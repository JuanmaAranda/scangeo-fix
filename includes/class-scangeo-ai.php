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
			$response = wp_remote_get(
				'https://api.openai.com/v1/models',
				array(
					'timeout' => 20,
					'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				)
			);
		} else {
			$response = wp_remote_get(
				'https://api.anthropic.com/v1/models?limit=100',
				array(
					'timeout' => 20,
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

	/**
	 * Genera texto. Devuelve string o WP_Error.
	 *
	 * @param string $prompt     Instrucción completa.
	 * @param int    $max_tokens Límite de tokens de salida.
	 */
	public static function generate( $prompt, $max_tokens = 300 ) {
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
		$response = wp_remote_post(
			self::INCLUDED_ENDPOINT,
			array(
				'timeout' => 30,
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
		$response = wp_remote_get(
			self::INCLUDED_ENDPOINT . '?domain=' . rawurlencode( $domain ),
			array( 'timeout' => 15 )
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
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 30,
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
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
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
