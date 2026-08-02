<?php
/**
 * Cliente de IA opcional (Anthropic u OpenAI) para generar textos
 * (meta descriptions, titles, alt de imágenes).
 * Si no hay clave API configurada, los fixers usan heurísticas básicas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScanGEO_AI {

	/** Endpoint propio (scangeo.app) para la IA incluida por defecto en el plugin. */
	const INCLUDED_ENDPOINT = 'https://scangeo.app/api/public/wp-plugin/ai';

	/**
	 * Timeout que realmente queremos usar en nuestras llamadas al proveedor
	 * de IA, en segundos.
	 *
	 * WordPress permite pasar un 'timeout' por petición a wp_remote_get()/
	 * wp_remote_post(), pero justo antes de ejecutar la petición vuelve a
	 * pasar ese valor por el filtro 'http_request_timeout'. Muchos hostings
	 * (sobre todo compartidos) enganchan ahí un límite fijo — típicamente
	 * 10 segundos — para todas las peticiones salientes de cualquier
	 * plugin, sin excepción. Si eso ocurre, el 'timeout' que indicamos al
	 * llamar a wp_remote_post() se ignora por completo y cURL corta la
	 * conexión a los ~10000 ms exactos, aunque el proveedor de IA solo
	 * necesitara uno o dos segundos más para responder. Esto es
	 * precisamente lo que produce el error "cURL error 28: Connection
	 * timed out after 10002 milliseconds" de forma sistemática (no
	 * intermitente) en algunos hostings.
	 *
	 * Para evitarlo, enganchamos nuestro propio filtro 'http_request_timeout'
	 * con prioridad muy alta justo antes de cada llamada y lo retiramos justo
	 * después, de modo que nuestro valor gane sobre el que el hosting (u
	 * otro plugin) intente forzar.
	 */
	const REQUEST_TIMEOUT = 45;

	/** Fuerza REQUEST_TIMEOUT por encima de cualquier filtro que el hosting u otro plugin aplique a 'http_request_timeout'. */
	public static function force_timeout( $seconds ) {
		return self::REQUEST_TIMEOUT;
	}

	/**
	 * Ejecuta wp_remote_get()/wp_remote_post() garantizando que se use
	 * REQUEST_TIMEOUT (o el que se indique en $args['timeout']) de verdad,
	 * en vez del que el hosting pudiera estar forzando.
	 */
	private static function remote_request( $method, $url, $args ) {
		add_filter( 'http_request_timeout', array( __CLASS__, 'force_timeout' ), PHP_INT_MAX );
		try {
			return ( 'get' === $method )
				? wp_remote_get( $url, $args )
				: wp_remote_post( $url, $args );
		} finally {
			remove_filter( 'http_request_timeout', array( __CLASS__, 'force_timeout' ), PHP_INT_MAX );
		}
	}

	public static function is_configured() {
		$opts     = get_option( 'scangeo_settings', array() );
		$provider = ! empty( $opts['provider'] ) ? $opts['provider'] : 'included';
		if ( 'included' === $provider ) {
			return true; // Siempre "disponible": si se agota la cuota, generate() devuelve el aviso correspondiente.
		}
		return ! empty( $opts['api_key'] );
	}

	/**
	 * Verifica una clave y devuelve los modelos disponibles del proveedor.
	 * Sirve a la vez de validación (401 = clave inválida) y de catálogo
	 * para el desplegable de modelos.
	 *
	 * @return array[]|WP_Error Lista de array( 'id' => ..., 'label' => ... ).
	 */
	public static function list_models( $provider, $api_key ) {
		if ( 'openai' === $provider ) {
			$response = self::remote_request(
				'get',
				'https://api.openai.com/v1/models',
				array(
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				)
			);
		} else {
			$response = self::remote_request(
				'get',
				'https://api.anthropic.com/v1/models?limit=100',
				array(
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => array(
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
					),
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'scangeo_net', 'No se pudo conectar con el proveedor: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'scangeo_bad_key', 'Clave API inválida o sin permisos (' . $code . ').' );
		}
		if ( 200 !== $code || empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'scangeo_models_error', 'No se pudo obtener la lista de modelos: ' . $msg );
		}

		$models = array();
		foreach ( $body['data'] as $m ) {
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$id = (string) $m['id'];
			if ( 'openai' === $provider ) {
				// Solo modelos de chat: gpt-* / o* — fuera embeddings, audio, imagen, etc.
				if ( ! preg_match( '/^(gpt-|o\d)/', $id ) || preg_match( '/embed|audio|tts|whisper|dall-e|realtime|image|moderation|transcribe|search|instruct/', $id ) ) {
					continue;
				}
				$models[] = array( 'id' => $id, 'label' => $id );
			} else {
				$models[] = array(
					'id'    => $id,
					'label' => ! empty( $m['display_name'] ) ? $m['display_name'] . ' (' . $id . ')' : $id,
				);
			}
		}
		if ( empty( $models ) ) {
			return new WP_Error( 'scangeo_no_models', 'La clave es válida pero no se encontraron modelos de chat disponibles.' );
		}
		usort( $models, function ( $a, $b ) {
			return strcmp( $b['id'], $a['id'] ); // Más recientes primero (aprox.).
		} );
		return $models;
	}

	/** Fragmentos de mensaje que identifican un fallo de red transitorio (no un fallo real del proveedor). */
	const TRANSIENT_ERROR_PATTERNS = array(
		'cURL error 28',
		'cURL error 7',
		'cURL error 6',
		'cURL error 35',
		'Connection timed out',
		'Operation timed out',
		'Could not resolve host',
		'Connection reset',
	);

	/**
	 * Genera texto. Devuelve string o WP_Error.
	 *
	 * Si el primer intento falla por un error de red transitorio (ver
	 * TRANSIENT_ERROR_PATTERNS: son precisamente los que produce una
	 * conexión saliente que se corta a mitad de camino, muy típico en
	 * hostings que limitan las conexiones salientes), se reintenta una vez
	 * más tras una breve espera antes de dar el fallo por definitivo.
	 *
	 * @param string $prompt     Instrucción completa.
	 * @param int    $max_tokens Límite de tokens de salida.
	 */
	public static function generate( $prompt, $max_tokens = 300 ) {
		$result = self::generate_once( $prompt, $max_tokens );
		if ( is_wp_error( $result ) && self::is_transient_error( $result ) ) {
			usleep( 800000 );
			$result = self::generate_once( $prompt, $max_tokens );
		}
		return $result;
	}

	/** Comprueba si un WP_Error corresponde a un fallo de red transitorio (y no, por ejemplo, a una clave inválida o una cuota agotada). */
	private static function is_transient_error( WP_Error $error ) {
		$msg = $error->get_error_message();
		foreach ( self::TRANSIENT_ERROR_PATTERNS as $pattern ) {
			if ( false !== stripos( $msg, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/** Un único intento de generación, sin reintentos. Devuelve string o WP_Error. */
	private static function generate_once( $prompt, $max_tokens ) {
		$opts     = get_option( 'scangeo_settings', array() );
		$provider = ! empty( $opts['provider'] ) ? $opts['provider'] : 'included';

		if ( 'included' === $provider ) {
			return self::call_included( $prompt, $max_tokens );
		}
		if ( empty( $opts['api_key'] ) ) {
			return new WP_Error( 'scangeo_no_key', 'Sin clave API configurada.' );
		}
		if ( 'openai' === $provider ) {
			return self::call_openai( $opts, $prompt, $max_tokens );
		}
		return self::call_anthropic( $opts, $prompt, $max_tokens );
	}

	/**
	 * Prueba la conexión con el proveedor de IA configurado (botón
	 * "Comprobar conexión" en Ajustes). Devuelve un resumen apto para
	 * mostrar directamente al usuario, incluyendo el tiempo de respuesta y
	 * si hizo falta un reintento.
	 *
	 * @return array{success:bool,message:string,elapsed_ms:int,provider:string,retried:bool}
	 */
	public static function test_connection() {
		$opts     = get_option( 'scangeo_settings', array() );
		$provider = ! empty( $opts['provider'] ) ? $opts['provider'] : 'included';
		$start    = microtime( true );
		$result   = self::generate_once( 'Responde solo con la palabra OK.', 5 );
		$retried  = false;
		if ( is_wp_error( $result ) && self::is_transient_error( $result ) ) {
			$retried = true;
			usleep( 800000 );
			$result = self::generate_once( 'Responde solo con la palabra OK.', 5 );
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $result ) ) {
			$is_net = self::is_transient_error( $result );
			$msg    = $result->get_error_message();
			if ( $is_net ) {
				$msg = 'Fallo de red al contactar con el proveedor de IA (' . $msg . '). Esto suele significar que tu hosting limita o corta las conexiones salientes a servicios externos (a veces con un límite fijo de unos 10 segundos, sin importar lo que el plugin le pida). Contacta con el soporte de tu hosting y pregunta si permiten conexiones salientes HTTPS de larga duración (al menos 30-45 segundos) hacia servicios de IA de terceros.';
			}
			return array(
				'success'    => false,
				'message'    => $msg,
				'elapsed_ms' => $elapsed_ms,
				'provider'   => $provider,
				'retried'    => $retried,
			);
		}
		return array(
			'success'    => true,
			'message'    => 'Conexión correcta con el proveedor de IA (' . $elapsed_ms . ' ms' . ( $retried ? ', tras un reintento' : '' ) . ').',
			'elapsed_ms' => $elapsed_ms,
			'provider'   => $provider,
			'retried'    => $retried,
		);
	}

	/** Dominio del sitio, usado como identificador de cuota en el endpoint incluido. */
	private static function included_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? strtolower( $host ) : '';
	}

	private static function call_included( $prompt, $max_tokens ) {
		$domain = self::included_domain();
		if ( ! $domain ) {
			return new WP_Error( 'scangeo_no_domain', 'No se pudo determinar el dominio de este sitio.' );
		}
		$response = self::remote_request(
			'post',
			self::INCLUDED_ENDPOINT,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'domain'     => $domain,
					'prompt'     => $prompt,
					'max_tokens' => $max_tokens,
				) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $code ) {
			delete_transient( 'scangeo_included_quota' ); // El indicador de Ajustes se refresca en la próxima visita.
			$limit = isset( $body['limit'] ) ? $body['limit'] : '';
			return new WP_Error(
				'scangeo_quota_exceeded',
				'Se ha agotado la cuota gratuita de IA incluida de este mes' . ( $limit ? ' (' . $limit . ' consultas)' : '' ) . '. Añade tu propia clave de Anthropic u OpenAI en Ajustes → Generación de textos con IA para seguir generando propuestas sin límite.'
			);
		}
		if ( 200 !== $code || empty( $body['text'] ) ) {
			$msg = isset( $body['error'] ) ? $body['error'] : 'HTTP ' . $code;
			return new WP_Error( 'scangeo_ai_error', 'IA incluida: ' . $msg );
		}
		if ( isset( $body['remaining'], $body['limit'] ) ) {
			set_transient( 'scangeo_included_quota', array( 'limit' => (int) $body['limit'], 'remaining' => (int) $body['remaining'] ), 5 * MINUTE_IN_SECONDS );
		}
		return trim( $body['text'] );
	}

	/**
	 * Cuánta cuota gratuita queda este mes, para enseñarlo en Ajustes.
	 * Caché de 5 minutos para no llamar al endpoint en cada carga del panel.
	 *
	 * @return array{limit:int,remaining:int}|false
	 */
	public static function get_included_quota() {
		$cached = get_transient( 'scangeo_included_quota' );
		if ( false !== $cached ) {
			return $cached;
		}
		$domain = self::included_domain();
		if ( ! $domain ) {
			return false;
		}
		$response = self::remote_request(
			'get',
			self::INCLUDED_ENDPOINT . '?domain=' . rawurlencode( $domain ),
			array( 'timeout' => self::REQUEST_TIMEOUT )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['limit'], $body['remaining'] ) ) {
			return false;
		}
		$result = array( 'limit' => (int) $body['limit'], 'remaining' => (int) $body['remaining'] );
		set_transient( 'scangeo_included_quota', $result, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	private static function call_anthropic( $opts, $prompt, $max_tokens ) {
		$model    = ! empty( $opts['model'] ) ? $opts['model'] : 'claude-haiku-4-5-20251001';
		$response = self::remote_request(
			'post',
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'x-api-key'         => $opts['api_key'],
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( array(
					'model'      => $model,
					'max_tokens' => $max_tokens,
					'messages'   => array(
						array( 'role' => 'user', 'content' => $prompt ),
					),
				) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['content'][0]['text'] ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'scangeo_ai_error', 'Anthropic: ' . $msg );
		}
		return trim( $body['content'][0]['text'] );
	}

	private static function call_openai( $opts, $prompt, $max_tokens ) {
		$model = ! empty( $opts['model'] ) ? $opts['model'] : 'gpt-4o-mini';
		$result = self::openai_request( $model, $opts['api_key'], $prompt, array( 'max_completion_tokens' => $max_tokens ) );

		// Los modelos "o*"/GPT-5 exigen max_completion_tokens y rechazan max_tokens;
		// algunos endpoints antiguos o compatibles con OpenAI hacen justo lo
		// contrario. Si el primer intento falla por ese parámetro, se reintenta
		// con el otro nombre antes de dar el fallo por bueno.
		if ( is_wp_error( $result ) && false !== strpos( $result->get_error_message(), 'max_completion_tokens' ) ) {
			$result = self::openai_request( $model, $opts['api_key'], $prompt, array( 'max_tokens' => $max_tokens ) );
		}
		return $result;
	}

	private static function openai_request( $model, $api_key, $prompt, $token_param ) {
		$response = self::remote_request(
			'post',
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array_merge(
					array(
						'model'    => $model,
						'messages' => array(
							array( 'role' => 'user', 'content' => $prompt ),
						),
					),
					$token_param
				) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'scangeo_ai_error', 'OpenAI: ' . $msg );
		}
		return trim( $body['choices'][0]['message']['content'] );
	}
}
