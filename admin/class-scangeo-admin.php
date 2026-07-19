<?php
/**
 * Página de administración: subida del .md, listado de issues,
 * botones de reparación (individual y "Reparar todo") y ajustes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScanGEO_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed_history' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_scangeo_fix_issue', array( __CLASS__, 'ajax_fix_issue' ) );
		add_action( 'wp_ajax_scangeo_apply_suggestion', array( __CLASS__, 'ajax_apply_suggestion' ) );
		add_action( 'wp_ajax_scangeo_discard_suggestion', array( __CLASS__, 'ajax_discard_suggestion' ) );
		add_action( 'wp_ajax_scangeo_undo_fix', array( __CLASS__, 'ajax_undo_fix' ) );
		add_action( 'wp_ajax_scangeo_verify_key', array( __CLASS__, 'ajax_verify_key' ) );
		add_action( 'wp_ajax_scangeo_save_model', array( __CLASS__, 'ajax_save_model' ) );
	}

	public static function menu() {
		add_menu_page(
			'scanGEO Fixer',
			'scanGEO Fixer',
			'manage_options',
			'scangeo-fixer',
			array( __CLASS__, 'render_page' ),
			self::menu_icon(),
			81
		);
	}

	/**
	 * Isotipo scanGEO como SVG monocromo en data URI (20x20).
	 * WordPress lo recolorea automáticamente según el esquema del admin.
	 */
	private static function menu_icon() {
		return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+CjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgZmlsbD0iYmxhY2siIGQ9Ik0xMC4wMCwwLjgwIEwyLjAzLDUuNDAgTDIuMDMsMTQuNjAgTDEwLjAwLDE5LjIwIEwxNy45NywxNC42MCBMMTcuOTcsNS40MCBaIE0xMC4wMCwyLjgwIEwzLjc2LDYuNDAgTDMuNzYsMTMuNjAgTDEwLjAwLDE3LjIwIEwxNi4yNCwxMy42MCBMMTYuMjQsNi40MCBaIi8+CjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgZmlsbD0iYmxhY2siIGQ9Ik0xMCw0LjkgQTQuNiw0LjYgMCAxLDAgMTAsMTQuMSBBNC42LDQuNiAwIDEsMCAxMCw0LjkgWiBNMTAsNi43IEEyLjgsMi44IDAgMSwxIDEwLDEyLjMgQTIuOCwyLjggMCAxLDEgMTAsNi43IFoiLz4KPHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMy43NCwxMS45NiBMMTYuMDQsMTQuMjYgQTAuOSwwLjkgMCAwLDEgMTQuNzYsMTUuNTQgTDEyLjQ2LDEzLjI0IFoiLz4KPC9zdmc+';
	}

	public static function register_settings() {
		register_setting( 'scangeo_settings_group', 'scangeo_settings', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
		) );
	}

	public static function sanitize_settings( $input ) {
		// IMPORTANTE: se parte SIEMPRE de los ajustes existentes para no borrar
		// la clave API ni el modelo cuando el formulario no los envía
		// (la clave se guarda por AJAX al verificarla, no por este formulario).
		$existing = get_option( 'scangeo_settings', array() );
		$out      = wp_parse_args( is_array( $existing ) ? $existing : array(), array(
			'provider'        => 'anthropic',
			'api_key'         => '',
			'model'           => '',
			'social_profiles' => '',
		) );
		if ( isset( $input['provider'] ) && in_array( $input['provider'], array( 'anthropic', 'openai' ), true ) ) {
			$out['provider'] = $input['provider'];
		}
		if ( ! empty( $input['api_key'] ) ) {
			$out['api_key'] = sanitize_text_field( $input['api_key'] );
		}
		if ( isset( $input['model'] ) && '' !== $input['model'] ) {
			$out['model'] = sanitize_text_field( $input['model'] );
		}
		if ( isset( $input['social_profiles'] ) ) {
			$out['social_profiles'] = sanitize_textarea_field( $input['social_profiles'] );
		}
		return $out;
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_scangeo-fixer' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'scangeo-admin', SCANGEO_FIXER_URL . 'assets/admin.css', array(), SCANGEO_FIXER_VERSION );
		wp_enqueue_script( 'scangeo-admin', SCANGEO_FIXER_URL . 'assets/admin.js', array( 'jquery' ), SCANGEO_FIXER_VERSION, true );
		$opts = get_option( 'scangeo_settings', array() );
		wp_localize_script( 'scangeo-admin', 'scangeoFixer', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'scangeo_fix' ),
			'keySaved'  => ! empty( $opts['api_key'] ),
			'provider'  => ! empty( $opts['provider'] ) ? $opts['provider'] : 'anthropic',
			'model'     => ! empty( $opts['model'] ) ? $opts['model'] : '',
			'i18n'      => array(
				'fixing'    => 'Reparando…',
				'fixed'     => 'Corregido',
				'failed'    => 'No se pudo arreglar',
				'manual'    => 'Requiere acción manual',
				'confirm'   => 'Se intentarán reparar todos los fallos automáticamente (se modificarán metadatos y ajustes del sitio). ¿Continuar?',
				'verifying' => 'Verificando clave…',
				'verified'  => 'Clave verificada y guardada',
				'loadModels'=> 'Cargando modelos…',
				'modelSaved'=> 'Modelo guardado',
				'needKey'   => 'Introduce una clave API.',
			),
		) );
	}

	/**
	 * Si el historial está vacío pero ya había un informe cargado (típicamente
	 * al actualizar desde una versión anterior a la que introdujo el
	 * historial), se guarda un único punto de partida para que la próxima
	 * subida ya tenga con qué compararse.
	 */
	public static function maybe_seed_history() {
		$history = get_option( 'scangeo_history', array() );
		if ( ! empty( $history ) ) {
			return;
		}
		$report = get_option( 'scangeo_report', array() );
		if ( empty( $report ) || ! is_array( $report ) ) {
			return;
		}
		$uploaded = get_option( 'scangeo_report_uploaded', '' );
		update_option( 'scangeo_history', array( array(
			'date'   => $uploaded ? $uploaded : current_time( 'mysql' ),
			'scores' => isset( $report['scores'] ) ? $report['scores'] : array(),
			'issues' => isset( $report['issues_count'] ) ? (int) $report['issues_count'] : ( isset( $report['issues'] ) ? count( $report['issues'] ) : 0 ),
			'site'   => isset( $report['site'] ) ? $report['site'] : '',
		) ), false );
	}

	/* ------------------------------ Subida ------------------------------ */

	public static function handle_upload() {
		if ( empty( $_POST['scangeo_upload_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_key( $_POST['scangeo_upload_nonce'] ), 'scangeo_upload' ) ) {
			wp_die( 'Permisos insuficientes.' );
		}
		if ( empty( $_FILES['scangeo_report']['tmp_name'] ) ) {
			self::redirect_with_notice( 'no_file' );
		}

		$file = $_FILES['scangeo_report'];
		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'md', 'markdown', 'txt' ), true ) ) {
			self::redirect_with_notice( 'bad_type' );
		}
		if ( $file['size'] > 5 * MB_IN_BYTES ) {
			self::redirect_with_notice( 'too_big' );
		}

		$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore
		$report  = ScanGEO_Parser::parse( (string) $content );
		if ( is_wp_error( $report ) ) {
			set_transient( 'scangeo_error_detail', $report->get_error_message(), 60 );
			self::redirect_with_notice( 'parse_error' );
		}

		// Guarda una foto del momento (puntuaciones + nº de fallos) para poder
		// enseñar la evolución entre informes. Se limita a las últimas 24 subidas.
		$history   = get_option( 'scangeo_history', array() );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'date'   => current_time( 'mysql' ),
			'scores' => isset( $report['scores'] ) ? $report['scores'] : array(),
			'issues' => isset( $report['issues_count'] ) ? (int) $report['issues_count'] : count( $report['issues'] ),
			'site'   => isset( $report['site'] ) ? $report['site'] : '',
		);
		if ( count( $history ) > 24 ) {
			$history = array_slice( $history, -24 );
		}
		update_option( 'scangeo_history', $history, false );

		update_option( 'scangeo_report', $report, false );
		update_option( 'scangeo_results', array(), false ); // Reinicia estados de reparación.
		update_option( 'scangeo_report_uploaded', current_time( 'mysql' ), false );

		// Resumen en palabras sencillas con semáforo de colores, solo si hay
		// IA configurada. Si no la hay, se deja en null y el panel muestra
		// el aviso para configurarla.
		$summary = self::generate_ai_summary( $report );
		update_option( 'scangeo_summary', $summary, false );

		self::redirect_with_notice( 'ok' );
	}

	/**
	 * Genera, a partir del informe recién subido, un resumen en lenguaje
	 * sencillo con semáforo (rojo/ámbar/verde) usando la IA configurada.
	 * Devuelve null si no hay IA configurada o si la generación falla —
	 * en ambos casos el resto del plugin sigue funcionando con normalidad.
	 */
	private static function generate_ai_summary( $report ) {
		if ( ! ScanGEO_AI::is_configured() ) {
			return null;
		}
		$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
		$labels = array();
		foreach ( (array) $report['issues'] as $issue ) {
			$sev = isset( $issue['severity'] ) ? strtolower( $issue['severity'] ) : '';
			if ( isset( $counts[ $sev ] ) ) {
				$counts[ $sev ]++;
			}
			if ( count( $labels ) < 15 && ! empty( $issue['label'] ) ) {
				$labels[] = $issue['label'] . ' (' . $issue['severity'] . ')';
			}
		}
		$scores_txt = '';
		if ( ! empty( $report['scores'] ) ) {
			foreach ( $report['scores'] as $k => $v ) {
				$scores_txt .= $k . ': ' . $v . '; ';
			}
		}

		$prompt = "Eres un asistente que traduce un informe técnico de auditoría SEO/GEO (optimización para buscadores e inteligencias artificiales) a un resumen sencillo para el dueño de una web sin conocimientos técnicos.\n\n"
			. "Puntuaciones del informe (0-100): {$scores_txt}\n"
			. "Fallos por gravedad: crítica={$counts['critical']}, alta={$counts['high']}, media={$counts['medium']}, baja={$counts['low']}\n"
			. 'Algunos de los fallos detectados: ' . implode( '; ', $labels ) . "\n\n"
			. "Devuelve SOLO un JSON (sin texto adicional ni backticks) con este formato exacto:\n"
			. '{"headline":"una frase breve resumiendo el estado general de la web","items":[{"color":"red|yellow|green","text":"frase corta y clara sobre un aspecto concreto"}]}' . "\n"
			. 'Incluye entre 4 y 7 items, mezclando aspectos positivos (green), mejorables (yellow) y urgentes (red) según los datos. Nada de jerga técnica ni nombres de reglas.';

		$ai = ScanGEO_AI::generate( $prompt, 700 );
		if ( is_wp_error( $ai ) ) {
			return null;
		}
		$clean = trim( (string) $ai );
		$clean = preg_replace( '/^```(json)?/i', '', $clean );
		$clean = preg_replace( '/```$/', '', $clean );
		$data  = json_decode( trim( (string) $clean ), true );
		if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return null;
		}
		$items = array();
		foreach ( $data['items'] as $item ) {
			if ( empty( $item['text'] ) ) {
				continue;
			}
			$color   = isset( $item['color'] ) ? strtolower( (string) $item['color'] ) : '';
			$color   = in_array( $color, array( 'red', 'yellow', 'green' ), true ) ? $color : 'yellow';
			$items[] = array( 'color' => $color, 'text' => sanitize_text_field( $item['text'] ) );
		}
		if ( empty( $items ) ) {
			return null;
		}
		return array(
			'headline' => isset( $data['headline'] ) ? sanitize_text_field( $data['headline'] ) : '',
			'items'    => array_slice( $items, 0, 8 ),
			'time'     => current_time( 'mysql' ),
		);
	}

	private static function redirect_with_notice( $code ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'scangeo-fixer', 'scangeo_notice' => $code ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------ AJAX ------------------------------ */

	public static function ajax_fix_issue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
		}
		check_ajax_referer( 'scangeo_fix', 'nonce' );

		$issue_id = isset( $_POST['issue_id'] ) ? sanitize_text_field( wp_unslash( $_POST['issue_id'] ) ) : '';
		$uid      = isset( $_POST['uid'] ) ? sanitize_text_field( wp_unslash( $_POST['uid'] ) ) : '';
		$report   = get_option( 'scangeo_report', array() );
		$issue    = null;
		if ( ! empty( $report['issues'] ) ) {
			foreach ( $report['issues'] as $candidate ) {
				$cuid = isset( $candidate['uid'] ) ? $candidate['uid'] : '';
				if ( ( '' !== $uid && $cuid === $uid ) || ( '' === $uid && $candidate['id'] === $issue_id ) ) {
					$issue = $candidate;
					break;
				}
			}
		}
		if ( ! $issue ) {
			wp_send_json_error( array( 'message' => 'Issue no encontrado en el informe.' ), 404 );
		}

		$result = ScanGEO_Fixers::fix( $issue );

		$key             = isset( $issue['uid'] ) ? $issue['uid'] : $issue['id'];
		$results         = get_option( 'scangeo_results', array() );
		$entry           = array(
			'status'  => $result['status'],
			'message' => $result['message'],
			'time'    => current_time( 'mysql' ),
		);
		if ( isset( $result['proposal'] ) ) {
			foreach ( array_keys( $result['proposal'] ) as $p_url ) {
				if ( ScanGEO_Fixers::is_translated_url( $p_url ) ) {
					unset( $result['proposal'][ $p_url ] );
				}
			}
			if ( empty( $result['proposal'] ) ) {
				$result['status']  = 'failed';
				$result['message'] = 'Todas las páginas de esta propuesta eran URLs traducidas por TranslatePress: se omiten para no mezclar idiomas.';
				$entry['status']   = $result['status'];
				$entry['message']  = $result['message'];
			} else {
				$entry['proposal'] = $result['proposal'];
			}
		}
		if ( isset( $result['undo'] ) ) {
			$entry['undo'] = $result['undo'];
		}
		$results[ $key ] = $entry;
		update_option( 'scangeo_results', $results, false );

		// Refresca rewrite rules si se activó llms.txt.
		if ( 'geo.llms_txt' === $issue['id'] && 'fixed' === $result['status'] ) {
			flush_rewrite_rules();
		}

		wp_send_json_success( array_merge( $result, array( 'uid' => $key ) ) );
	}

	/** Encuentra un issue del informe guardado por su uid. */
	private static function find_issue( $uid ) {
		$report = get_option( 'scangeo_report', array() );
		if ( empty( $report['issues'] ) ) {
			return null;
		}
		foreach ( $report['issues'] as $candidate ) {
			if ( isset( $candidate['uid'] ) && $candidate['uid'] === $uid ) {
				return $candidate;
			}
		}
		return null;
	}

	/** Aplica una propuesta (texto de IA revisado/editado por el usuario), total o parcial. */
	public static function ajax_apply_suggestion() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
		}
		check_ajax_referer( 'scangeo_fix', 'nonce' );

		$uid        = isset( $_POST['uid'] ) ? sanitize_text_field( wp_unslash( $_POST['uid'] ) ) : '';
		$edited_raw = isset( $_POST['edited'] ) ? wp_unslash( $_POST['edited'] ) : ''; // phpcs:ignore
		$edited     = json_decode( (string) $edited_raw, true );
		if ( ! is_array( $edited ) ) {
			wp_send_json_error( array( 'message' => 'Datos de la propuesta no válidos.' ) );
		}
		$clean = array();
		foreach ( $edited as $url => $text ) {
			$clean[ esc_url_raw( (string) $url ) ] = wp_kses_post( (string) $text );
		}

		$issue = self::find_issue( $uid );
		if ( ! $issue ) {
			wp_send_json_error( array( 'message' => 'Issue no encontrado en el informe.' ), 404 );
		}

		$result = ScanGEO_Fixers::apply( $issue, $clean );
		$entry  = self::merge_partial_result( $uid, $clean, $result, true );

		wp_send_json_success( array_merge( $entry, array( 'uid' => $uid ) ) );
	}

	/** Descarta una propuesta sin aplicarla, total o solo una página concreta. */
	public static function ajax_discard_suggestion() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
		}
		check_ajax_referer( 'scangeo_fix', 'nonce' );

		$uid = isset( $_POST['uid'] ) ? sanitize_text_field( wp_unslash( $_POST['uid'] ) ) : '';
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( '' === $url ) {
			// Descarte total: se olvida la fila entera.
			$results = get_option( 'scangeo_results', array() );
			unset( $results[ $uid ] );
			update_option( 'scangeo_results', $results, false );
			wp_send_json_success( array( 'uid' => $uid, 'status' => 'pending', 'message' => '' ) );
		}

		$entry = self::merge_partial_result( $uid, array( $url => true ), array( 'status' => 'discarded' ), false );
		wp_send_json_success( array_merge( $entry, array( 'uid' => $uid ) ) );
	}

	/**
	 * Quita de la propuesta guardada las URLs ya resueltas (aplicadas o
	 * descartadas), fusiona los datos de "deshacer" si procede, y calcula
	 * el estado final de la fila: sigue habiendo propuestas pendientes
	 * ('suggested'), se aplicó al menos una y ya no queda ninguna ('fixed'),
	 * o se descartó todo sin aplicar nada ('pending', se borra la fila).
	 *
	 * @param string $uid          Identificador de la fila.
	 * @param array  $handled_urls URLs ya resueltas en esta llamada (url => lo que sea).
	 * @param array  $result       Resultado de ScanGEO_Fixers::apply() o array('status'=>'discarded').
	 * @param bool   $was_applied  true si $handled_urls se aplicó de verdad (no solo se descartó).
	 */
	private static function merge_partial_result( $uid, $handled_urls, $result, $was_applied ) {
		$results       = get_option( 'scangeo_results', array() );
		$prev          = ( isset( $results[ $uid ] ) && is_array( $results[ $uid ] ) ) ? $results[ $uid ] : array();
		$proposal      = isset( $prev['proposal'] ) && is_array( $prev['proposal'] ) ? $prev['proposal'] : array();
		$applied_count = isset( $prev['applied_count'] ) ? (int) $prev['applied_count'] : 0;
		$undo          = isset( $prev['undo'] ) && is_array( $prev['undo'] ) ? $prev['undo'] : array();

		// Limpieza defensiva: nunca se debe ofrecer (ni guardar) una propuesta
		// para una URL traducida, aunque viniera de datos guardados por una
		// versión anterior del plugin. Se descarta aquí para que no vuelva a
		// aparecer en ningún round-trip por AJAX.
		foreach ( array_keys( $proposal ) as $p_url ) {
			if ( ScanGEO_Fixers::is_translated_url( $p_url ) ) {
				unset( $proposal[ $p_url ] );
			}
		}

		foreach ( array_keys( $handled_urls ) as $url ) {
			unset( $proposal[ $url ] );
		}

		if ( $was_applied && 'fixed' === $result['status'] ) {
			$applied_count += count( $handled_urls );
			if ( ! empty( $result['undo'] ) ) {
				if ( empty( $undo ) ) {
					$undo = $result['undo'];
				} elseif ( isset( $undo['type'] ) && $undo['type'] === $result['undo']['type'] ) {
					$undo['items'] = array_merge( $undo['items'], $result['undo']['items'] );
				}
			}
		}

		if ( ! empty( $proposal ) ) {
			$entry = array(
				'status'        => 'suggested',
				'message'       => ( $was_applied ? $result['message'] : 'Propuesta descartada.' ) . ' Quedan ' . count( $proposal ) . ' propuesta(s) más por revisar.',
				'proposal'      => $proposal,
				'applied_count' => $applied_count,
				'time'          => current_time( 'mysql' ),
			);
			if ( ! empty( $undo ) ) {
				$entry['undo'] = $undo;
			}
			$results[ $uid ] = $entry;
			update_option( 'scangeo_results', $results, false );
			return $entry;
		}

		if ( $applied_count > 0 ) {
			$entry = array(
				'status'        => 'fixed',
				'message'       => $applied_count . ' propuesta(s) aplicada(s) en total.',
				'applied_count' => $applied_count,
				'time'          => current_time( 'mysql' ),
			);
			if ( ! empty( $undo ) ) {
				$entry['undo'] = $undo;
			}
			$results[ $uid ] = $entry;
			update_option( 'scangeo_results', $results, false );
			return $entry;
		}

		// No queda propuesta y nunca se aplicó nada: vuelve a pendiente.
		unset( $results[ $uid ] );
		update_option( 'scangeo_results', $results, false );
		return array( 'status' => 'pending', 'message' => '' );
	}

	/** Deshace un fixer ya aplicado (restaura el valor/contenido anterior). */
	public static function ajax_undo_fix() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
		}
		check_ajax_referer( 'scangeo_fix', 'nonce' );

		$uid     = isset( $_POST['uid'] ) ? sanitize_text_field( wp_unslash( $_POST['uid'] ) ) : '';
		$results = get_option( 'scangeo_results', array() );
		if ( empty( $results[ $uid ]['undo'] ) ) {
			wp_send_json_error( array( 'message' => 'No hay cambios que deshacer para este fallo.' ) );
		}

		$result = ScanGEO_Fixers::undo( $results[ $uid ]['undo'] );
		unset( $results[ $uid ] );
		update_option( 'scangeo_results', $results, false );

		wp_send_json_success( array_merge( $result, array( 'uid' => $uid ) ) );
	}

	/**
	 * Verifica la clave API contra el proveedor. Si es válida, la guarda
	 * y devuelve la lista de modelos disponibles para el desplegable.
	 * Si no se envía clave, usa la ya guardada (para recargar los modelos).
	 */
	public static function ajax_verify_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
		}
		check_ajax_referer( 'scangeo_fix', 'nonce' );

		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'anthropic';
		if ( ! in_array( $provider, array( 'anthropic', 'openai' ), true ) ) {
			$provider = 'anthropic';
		}
		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		$opts = get_option( 'scangeo_settings', array() );
		if ( '' === $api_key ) {
			// Recarga de modelos con la clave ya guardada.
			if ( empty( $opts['api_key'] ) || ( isset( $opts['provider'] ) && $opts['provider'] !== $provider ) ) {
				wp_send_json_error( array( 'message' => 'Introduce una clave API para este proveedor.' ) );
			}
			$api_key = $opts['api_key'];
		}

		$models = ScanGEO_AI::list_models( $provider, $api_key );
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}

		// Clave válida: guardar provider + clave. Se resetea el modelo si cambia de proveedor.
		$opts             = is_array( $opts ) ? $opts : array();
		$provider_changed = isset( $opts['provider'] ) && $opts['provider'] !== $provider;
		$opts['provider'] = $provider;
		$opts['api_key']  = $api_key;
		if ( $provider_changed ) {
			$opts['model'] = '';
		}
		update_option( 'scangeo_settings', $opts, false );

		wp_send_json_success( array(
			'models' => $models,
			'model'  => isset( $opts['model'] ) ? $opts['model'] : '',
		) );
	}

	/** Guarda el modelo elegido en el desplegable. */
	public static function ajax_save_model() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
		}
		check_ajax_referer( 'scangeo_fix', 'nonce' );

		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$opts  = get_option( 'scangeo_settings', array() );
		$opts  = is_array( $opts ) ? $opts : array();
		$opts['model'] = $model;
		update_option( 'scangeo_settings', $opts, false );
		wp_send_json_success( array( 'model' => $model ) );
	}

	/** Texto seguro para celdas de la tabla: solo etiquetas inline inofensivas. */
	private static function safe_text( $text ) {
		return wp_kses( (string) $text, array(
			'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'code'   => array(),
			'small'  => array(),
		) );
	}

	/** Explicación en lenguaje llano de qué comprueba cada regla. */
	private static function rule_description( $id ) {
		$map = array(
			'tech.core_web_vitals'      => 'Mide la velocidad de carga y la estabilidad visual de la página (los "Core Web Vitals" de Google). Si falla, tu web tarda o "salta" al cargar, y eso penaliza en buscadores.',
			'tech.http_status'          => 'Comprueba que la página responde correctamente (código 200 OK). Si falla, puede que dé error, esté rota o redirija mal.',
			'tech.robots_txt'           => 'El archivo robots.txt le dice a buscadores e IAs qué partes de tu web pueden visitar. Si falla, podrías estar bloqueando sin querer el acceso a tu contenido.',
			'tech.sitemap'              => 'El mapa del sitio (sitemap.xml) es un listado de tus páginas que ayuda a que buscadores e IAs las encuentren todas. Si falla, no existe o no está bien declarado.',
			'tech.mobile_viewport'      => 'Comprueba si la página está preparada para verse bien en el móvil. Si falla, falta una etiqueta técnica y el contenido puede verse mal ajustado en pantallas pequeñas.',
			'tech.canonical'            => 'La etiqueta canonical indica cuál es la versión "oficial" de una página cuando hay contenido duplicado. Si falta, puede haber confusión sobre qué versión debe aparecer en buscadores.',
			'tech.pagespeed_mobile'     => 'Mide la velocidad de carga específicamente desde un móvil. Si falla, tu web tarda demasiado en un teléfono.',
			'content.h1_unique'         => 'Cada página debería tener un único título principal (etiqueta H1) que resuma el tema. Si falla, falta ese H1 o hay más de uno, lo que confunde sobre cuál es el tema central.',
			'content.heading_hierarchy' => 'Los subtítulos (H2, H3...) deberían seguir un orden lógico, como un índice, sin saltarse niveles. Si falla, la estructura está desordenada y es más difícil de entender para lectores automáticos.',
			'content.title_length'      => 'El título que aparece en los resultados de búsqueda debería tener una longitud concreta para no verse cortado. Esta regla avisa si se sale de ese rango.',
			'content.meta_description'  => 'La meta description es el resumen que aparece bajo el título en los resultados de búsqueda. Si falla, falta o su longitud no es la ideal, lo que reduce las ganas de hacer clic.',
			'content.internal_links'    => 'Los enlaces internos conectan tus propias páginas entre sí, ayudando a entender cómo se relaciona tu contenido. Si falla, la página tiene pocos o ningún enlace hacia el resto de tu web.',
			'content.images_alt'        => 'El texto alternativo (alt) describe una imagen para quien no puede verla, o para un buscador. Si falla, hay imágenes sin ese texto.',
			'content.body_length'       => 'Comprueba si el contenido tiene suficiente extensión para tratar el tema en profundidad. Si falla, el texto se ha quedado corto.',
			'content.eeat_author'       => '"E-E-A-T" son las siglas que usa Google para valorar la experiencia y autoridad de quien escribe. Si falla, falta información visible sobre el autor del contenido.',
			'content.lang'              => 'Indica en qué idioma está escrita la página (a nivel de código, no de contenido visible). Si falla, esa etiqueta falta o está mal puesta.',
			'geo.jsonld'                => 'El JSON-LD es un código invisible que describe tu web (nombre, organización...) para que buscadores e IAs la entiendan mejor. Si falla, ese marcado no está presente.',
			'geo.open_graph'            => 'Las etiquetas Open Graph controlan cómo se ve tu página cuando se comparte en redes sociales (título, imagen, descripción). Si falla, faltan o están incompletas.',
			'geo.faq_or_qa'             => 'Comprueba si la página tiene una sección de preguntas frecuentes. Este formato ayuda a que las IAs (ChatGPT, etc.) puedan citar respuestas directas de tu contenido.',
			'geo.short_paragraphs'      => 'Los párrafos cortos son más fáciles de leer y de citar por una IA. Si falla, hay párrafos demasiado largos (más de 80 palabras).',
			'geo.entity_author'         => 'Comprueba si el autor del contenido está identificado como una entidad reconocible (con nombre, no solo "admin"), lo que da más credibilidad ante buscadores e IAs.',
			'geo.entity_organization'   => 'Comprueba si tu marca o empresa está identificada como una entidad clara (nombre, logo, web) en el código de la página.',
			'geo.entity_person_author'  => 'Parecida a "entity_author": revisa si hay una persona concreta identificada como autora, con datos que una IA pueda asociar a un perfil real.',
			'geo.date_modified'         => 'Indica si se muestra la fecha de última actualización del contenido, para que buscadores e IAs sepan que la información sigue vigente.',
			'geo.direct_answer'         => 'Comprueba si el contenido empieza respondiendo directamente a la pregunta principal, en un párrafo corto. Es el formato que más facilita que una IA cite tu respuesta.',
			'geo.ai_crawlers_access'    => 'Comprueba si los robots de las IAs (ChatGPT, Claude, Perplexity...) tienen permiso para acceder a tu web. Si falla, alguno de ellos está bloqueado.',
			'geo.content_in_raw_html'   => 'Comprueba si el contenido está presente en el HTML que reciben los buscadores e IAs, o si solo aparece después de cargar JavaScript (que muchos rastreadores no ejecutan).',
			'geo.llms_txt'              => 'El archivo llms.txt es un estándar reciente pensado para orientar a las IAs sobre tu web. Si falla, no lo tienes.',
			'geo.semantic_structure'    => 'Comprueba si el HTML usa etiquetas con significado (como listas, tablas, artículo) en vez de solo bloques genéricos, lo que ayuda a que el contenido se entienda mejor de forma automática.',
			'offpage.https_domain'      => 'Comprueba si tu web usa HTTPS (conexión segura) en vez de HTTP. Si falla, no tienes un certificado SSL activo.',
			'offpage.social_signals'    => 'Comprueba si tu web está conectada visiblemente con tus perfiles sociales. Ayuda a reforzar la confianza en la marca.',
			'offpage.brand_mention'     => 'Comprueba si tu marca está bien identificada como entidad en el código de la página (parecida a "entity_organization", centrada en menciones de marca).',
			'offpage.backlinks_pro'     => 'Comprueba la cantidad y calidad de enlaces externos que apuntan a tu web (backlinks). No se puede arreglar desde el plugin: requiere una estrategia externa de enlaces.',
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : '';
	}

	/* ------------------------------ Render ------------------------------ */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Botón "Comprobar ahora": borra la caché de GitHub y obliga a
		// WordPress a recalcular su propio aviso de actualización (el de
		// Plugins → "Hay una nueva versión disponible"), no solo el de aquí.
		if ( isset( $_GET['scangeo_check_update'] ) && check_admin_referer( 'scangeo_check_update' ) ) {
			delete_transient( 'scangeo_fixer_latest_release' );
			if ( ! function_exists( 'wp_update_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			wp_update_plugins();
			wp_safe_redirect( remove_query_arg( array( 'scangeo_check_update', '_wpnonce' ) ) );
			exit;
		}

		// Comprobación automática (silenciosa) al abrir el panel, pero como
		// máximo una vez cada 10 minutos, para no ralentizar cada visita ni
		// saturar la API de GitHub / WordPress.org.
		if ( false === get_transient( 'scangeo_auto_check_lock' ) ) {
			set_transient( 'scangeo_auto_check_lock', 1, 10 * MINUTE_IN_SECONDS );
			if ( ! function_exists( 'wp_update_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			wp_update_plugins();
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'report'; // phpcs:ignore
		echo '<div class="wrap scangeo-wrap">';
		echo '<div class="scangeo-header">';
		echo '<div class="scangeo-header-left">';
		echo '<img src="' . esc_url( SCANGEO_FIXER_URL . 'assets/logo.png' ) . '" alt="scanGEO" class="scangeo-logo">';
		echo '<span class="scangeo-version-badge">v' . esc_html( SCANGEO_FIXER_VERSION ) . '</span>';
		$latest = class_exists( 'ScanGEO_Updater' ) ? ScanGEO_Updater::get_latest_version() : '';
		if ( $latest && version_compare( $latest, SCANGEO_FIXER_VERSION, '>' ) ) {
			echo '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '" class="scangeo-update-pill">Nueva versión disponible: v' . esc_html( $latest ) . ' →</a>';
		}
		$check_url = wp_nonce_url( add_query_arg( 'scangeo_check_update', '1' ), 'scangeo_check_update' );
		echo '<a href="' . esc_url( $check_url ) . '" class="scangeo-check-update-link">Comprobar actualización del plugin</a>';
		echo '</div>';
		echo '<a href="https://scangeo.app" target="_blank" rel="noopener" class="scangeo-header-link">scanGEO.app ↗</a>';
		echo '<h1 class="screen-reader-text">scanGEO Fixer</h1>';
		echo '</div>';
		self::notices();
		echo '<nav class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=scangeo-fixer' ) ) . '" class="nav-tab ' . ( 'report' === $tab ? 'nav-tab-active' : '' ) . '">Informe</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=scangeo-fixer&tab=settings' ) ) . '" class="nav-tab ' . ( 'settings' === $tab ? 'nav-tab-active' : '' ) . '">Ajustes</a>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=scangeo-fixer&tab=help' ) ) . '" class="nav-tab ' . ( 'help' === $tab ? 'nav-tab-active' : '' ) . '">Ayuda</a>';
		echo '</nav>';

		if ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( 'help' === $tab ) {
			self::render_help();
		} else {
			self::render_report();
		}
		echo '</div>';
	}

	private static function notices() {
		if ( empty( $_GET['scangeo_notice'] ) ) { // phpcs:ignore
			return;
		}
		$code     = sanitize_key( $_GET['scangeo_notice'] ); // phpcs:ignore
		$messages = array(
			'ok'          => array( 'success', 'Informe cargado correctamente.' ),
			'no_file'     => array( 'error', 'No se seleccionó ningún archivo.' ),
			'bad_type'    => array( 'error', 'El archivo debe ser .md (o .txt).' ),
			'too_big'     => array( 'error', 'El archivo supera los 5 MB.' ),
			'parse_error' => array( 'error', 'No se pudo interpretar el informe. ' . esc_html( (string) get_transient( 'scangeo_error_detail' ) ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $code ][0] ), wp_kses_post( $messages[ $code ][1] ) );
		}
	}

	private static function render_help() {
		$faqs = array(
			array(
				'q' => '¿Dónde consigo el archivo .md que tengo que subir?',
				'a' => 'Se genera al completar un análisis de tu web en scanGEO.app. Desde el panel de resultados de ese análisis puedes descargarlo y luego subirlo aquí, en la pestaña "Informe".',
			),
			array(
				'q' => '¿Por qué al subir un informe nuevo desaparece el anterior?',
				'a' => 'El informe nuevo sustituye al anterior como "informe activo" (es el que ves y reparas), pero su puntuación queda guardada en el historial para que puedas ver la evolución entre subidas, en la tabla que aparece bajo el resumen de puntuaciones.',
			),
			array(
				'q' => '¿Qué significa que un fallo sea "solución manual"?',
				'a' => 'Significa que el plugin no puede (o no debe) tocarlo automáticamente: por ejemplo, porque ya lo gestiona tu plugin SEO (Yoast/Rank Math), porque depende de tu hosting (SSL, velocidad), o porque requiere una decisión editorial que solo tú puedes tomar.',
			),
			array(
				'q' => '¿Por qué algunas páginas en otro idioma no se pueden reparar?',
				'a' => 'Si usas TranslatePress, las traducciones no son páginas independientes: comparten el mismo contenido guardado en la base de datos que la versión original. Escribir algo "solo para el inglés", por ejemplo, mezclaría los dos idiomas en la misma página. Por eso esas URLs se dejan sin tocar.',
			),
			array(
				'q' => '¿Es seguro darle mi clave de API al plugin?',
				'a' => 'La clave se guarda en tu propia base de datos de WordPress (no sale de tu servidor salvo para llamar a la API de Anthropic u OpenAI) y solo se usa para generar los textos que tú apruebas. Nunca se vuelve a mostrar en pantalla una vez guardada.',
			),
			array(
				'q' => '¿Qué pasa si aplico una propuesta y no me convence el resultado?',
				'a' => 'Cualquier cambio aplicado por el plugin (meta descriptions, títulos, alt de imágenes, bloques de contenido, ajustes de plantilla) se puede revertir con el botón "Deshacer" que aparece junto a ese fallo una vez corregido.',
			),
			array(
				'q' => '¿Con qué frecuencia debería volver a escanear mi web?',
				'a' => 'Depende de cuánto publiques, pero un buen punto de partida es una vez al mes, o después de cambios grandes en el sitio. Puedes lanzar un nuevo análisis con el botón "Volver a escanear sitio" de la pestaña Informe.',
			),
			array(
				'q' => '¿Por qué algunas propuestas de IA no aparecen aunque tenga clave configurada?',
				'a' => 'Puede deberse a un fallo puntual del proveedor (límite de uso, modelo no disponible) o a que la página no tenía suficiente contenido de partida. El mensaje bajo cada fallo explica el motivo exacto; puedes volver a pulsar "Reparar" para reintentarlo.',
			),
		);
		?>
		<div class="scangeo-help">
			<h2>Preguntas frecuentes</h2>
			<p class="description">Dudas habituales al cargar el informe de scanGEO y usar el plugin.</p>
			<?php foreach ( $faqs as $faq ) : ?>
				<details class="scangeo-help-item">
					<summary><?php echo esc_html( $faq['q'] ); ?></summary>
					<p><?php echo esc_html( $faq['a'] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_settings() {
		$opts = wp_parse_args( get_option( 'scangeo_settings', array() ), array(
			'provider' => 'anthropic', 'api_key' => '', 'model' => '', 'social_profiles' => '',
		) );
		$has_key = ! empty( $opts['api_key'] );
		?>
		<div class="scangeo-settings">
			<h2>Generación de textos con IA (opcional)</h2>
			<p>Si configuras una clave API, las meta descriptions, títulos y alt de imágenes se generarán con IA. Sin clave, se usan heurísticas básicas (extracto del contenido, nombre de archivo).</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="scangeo-provider">Proveedor</label></th>
					<td>
						<select id="scangeo-provider">
							<option value="anthropic" <?php selected( $opts['provider'], 'anthropic' ); ?>>Anthropic (Claude)</option>
							<option value="openai" <?php selected( $opts['provider'], 'openai' ); ?>>OpenAI</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="scangeo-api-key">Clave API</label></th>
					<td>
						<input type="password" id="scangeo-api-key" class="regular-text" autocomplete="off"
							placeholder="<?php echo $has_key ? '••••••••  (clave guardada)' : 'sk-…'; ?>">
						<button type="button" class="button" id="scangeo-verify-key">Verificar y guardar</button>
						<span id="scangeo-key-status" class="scangeo-key-status" data-saved="<?php echo $has_key ? '1' : '0'; ?>"></span>
						<p class="description">La clave se comprueba contra la API del proveedor antes de guardarse. No se muestra nunca de vuelta en pantalla.</p>
						<p class="description">
							¿No tienes clave todavía?
							<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">Consíguela en Anthropic ↗</a>
							&nbsp;·&nbsp;
							<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">Consíguela en OpenAI ↗</a>
						</p>
					</td>
				</tr>
				<tr id="scangeo-model-row" <?php echo $has_key ? '' : 'style="display:none"'; ?>>
					<th scope="row"><label for="scangeo-model-select">Modelo</label></th>
					<td>
						<select id="scangeo-model-select" disabled>
							<option value=""><?php echo $has_key ? 'Cargando modelos…' : 'Verifica la clave primero'; ?></option>
						</select>
						<span id="scangeo-model-status" class="scangeo-key-status"></span>
						<p class="description">Recomendado: un modelo rápido y económico (Haiku / gpt-4o-mini) es suficiente para metas y alts.</p>
					</td>
				</tr>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( 'scangeo_settings_group' ); ?>
				<h2>Perfiles sociales (para schema sameAs)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="scangeo-social">URLs de perfiles</label></th>
						<td>
							<textarea id="scangeo-social" name="scangeo_settings[social_profiles]" rows="5" class="large-text" placeholder="https://www.linkedin.com/company/tu-empresa&#10;https://x.com/tu-empresa"><?php echo esc_textarea( $opts['social_profiles'] ); ?></textarea>
							<p class="description">Una URL por línea. Se usan en el JSON-LD Organization como <code>sameAs</code>.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Guardar perfiles' ); ?>
			</form>
		</div>
		<?php
	}

	/** Enseña la nota del informe (técnico/contenido/GEO/off-page) en barras. */
	/**
	 * Enseña el resumen en palabras sencillas (semáforo rojo/ámbar/verde)
	 * generado por IA a partir del último informe. Si no hay IA configurada,
	 * enseña un aviso explicando que hace falta para que esto funcione.
	 */
	private static function render_summary( $report ) {
		$summary = get_option( 'scangeo_summary', null );
		if ( ! empty( $summary ) && ! empty( $summary['items'] ) ) {
			echo '<div class="scangeo-ai-summary">';
			echo '<h3>Resumen en palabras sencillas</h3>';
			if ( ! empty( $summary['headline'] ) ) {
				echo '<p class="scangeo-ai-headline">' . esc_html( $summary['headline'] ) . '</p>';
			}
			echo '<ul class="scangeo-ai-items">';
			foreach ( $summary['items'] as $item ) {
				echo '<li><span class="scangeo-dot scangeo-dot-' . esc_attr( $item['color'] ) . '"></span>' . esc_html( $item['text'] ) . '</li>';
			}
			echo '</ul>';
			echo '</div>';
			return;
		}
		if ( ! ScanGEO_AI::is_configured() ) {
			$settings_url = admin_url( 'admin.php?page=scangeo-fixer&tab=settings' );
			echo '<div class="scangeo-warning-box">';
			echo '<strong>Falta un paso para que el plugin funcione al completo:</strong> añade una clave de IA (Anthropic u OpenAI) en Ajustes. ';
			echo 'Sin ella no se puede generar este resumen en palabras sencillas, ni las propuestas de contenido (meta descriptions, FAQ, enlaces, ampliar contenido…). ';
			echo '<a href="' . esc_url( $settings_url ) . '">Ir a Ajustes →</a>';
			echo '</div>';
		}
	}

	private static function render_scores( $scores ) {
		if ( empty( $scores ) || ! is_array( $scores ) ) {
			return;
		}
		$labels = array(
			'overall' => 'General',
			'tech'    => 'Técnico',
			'content' => 'Contenido',
			'geo'     => 'GEO (IA)',
			'offpage' => 'Off-page',
		);
		echo '<div class="scangeo-scores">';
		foreach ( $scores as $key => $val ) {
			$pct   = max( 0, min( 100, (float) $val ) );
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : ucfirst( (string) $key );
			$level = $pct >= 80 ? 'good' : ( $pct >= 50 ? 'mid' : 'bad' );
			echo '<div class="scangeo-score-item">';
			echo '<div class="scangeo-score-label">' . esc_html( $label ) . ' <strong>' . esc_html( (string) round( $pct ) ) . '</strong></div>';
			echo '<div class="scangeo-score-bar"><div class="scangeo-score-fill scangeo-score-' . esc_attr( $level ) . '" style="width:' . esc_attr( (string) $pct ) . '%"></div></div>';
			echo '</div>';
		}
		echo '</div>';
	}

	/** Enseña la evolución de la puntuación general entre informes subidos. */
	private static function render_history() {
		$history = get_option( 'scangeo_history', array() );
		if ( ! is_array( $history ) || count( $history ) < 2 ) {
			return; // Hace falta al menos 2 informes para mostrar una evolución.
		}
		echo '<div class="scangeo-history"><h3>Evolución entre informes</h3><table class="widefat striped">';
		echo '<thead><tr><th>Fecha de subida</th><th>Puntuación general</th><th>Fallos detectados</th></tr></thead><tbody>';
		foreach ( array_reverse( $history ) as $h ) {
			$overall = isset( $h['scores']['overall'] ) ? (string) round( (float) $h['scores']['overall'] ) : '—';
			$date    = ! empty( $h['date'] ) ? mysql2date( 'd/m/Y H:i', $h['date'] ) : '—';
			$issues  = isset( $h['issues'] ) ? (int) $h['issues'] : '—';
			echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( $overall ) . '</td><td>' . esc_html( (string) $issues ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_report() {
		$report     = get_option( 'scangeo_report', array() );
		$results    = get_option( 'scangeo_results', array() );
		$results    = is_array( $results ) ? $results : array();
		$issue_list = ( ! empty( $report['issues'] ) && is_array( $report['issues'] ) ) ? array_values( $report['issues'] ) : array();
		$stored     = isset( $report['issues_count'] ) ? (int) $report['issues_count'] : 0;
		if ( $stored > 0 && count( $issue_list ) !== $stored ) {
			printf(
				'<div class="notice notice-error"><p><strong>Aviso de integridad:</strong> el informe guardado tenía %1$d fallos y ahora contiene %2$d. Algo externo al plugin (caché de objetos, un limpiador de base de datos…) ha alterado los datos guardados en wp_options. Vuelve a subir el informe .md.</p></div>',
				$stored,
				count( $issue_list )
			);
		}
		?>
		<div class="scangeo-upload-box">
			<h2>Subir informe de scanGEO</h2>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'scangeo_upload', 'scangeo_upload_nonce' ); ?>
				<input type="file" name="scangeo_report" accept=".md,.markdown,.txt" required>
				<?php submit_button( ! empty( $report ) ? 'Cargar nuevo informe' : 'Cargar informe', 'primary', 'submit', false ); ?>
			</form>
			<a href="https://scangeo.app" target="_blank" rel="noopener" class="button button-primary scangeo-rescan-btn">↻ Volver a escanear sitio</a>
		</div>
		<?php
		self::render_summary( $report );
		self::render_scores( isset( $report['scores'] ) ? $report['scores'] : array() );
		self::render_history();

		if ( empty( $issue_list ) ) {
			echo '<p>Aún no hay ningún informe cargado, o el último informe no contenía fallos. Sube el archivo .md exportado desde <strong>scanGEO.app</strong>.</p>';
			return;
		}

		$fixed  = 0;
		$failed = 0;
		$manual = 0;
		foreach ( $results as $r ) {
			if ( ! is_array( $r ) || empty( $r['status'] ) ) {
				continue;
			}
			if ( 'fixed' === $r['status'] ) {
				$fixed++;
			} elseif ( 'failed' === $r['status'] ) {
				$failed++;
			} elseif ( 'manual' === $r['status'] ) {
				$manual++;
			}
		}

		$category_labels = array(
			'tech'    => 'Técnico',
			'content' => 'Contenido',
			'geo'     => 'GEO (IA)',
			'offpage' => 'Off-page',
		);
		$category_counts = array();
		foreach ( $issue_list as $issue ) {
			$cat = ! empty( $issue['category'] ) ? $issue['category'] : 'otros';
			$category_counts[ $cat ] = isset( $category_counts[ $cat ] ) ? $category_counts[ $cat ] + 1 : 1;
		}
		?>
		<div class="scangeo-cat-tabs" id="scangeo-cat-tabs">
			<button type="button" class="scangeo-cat-tab is-active" data-category="all">
				Todos <span class="scangeo-cat-count"><?php echo count( $issue_list ); ?></span>
			</button>
			<?php foreach ( $category_counts as $cat => $n ) : ?>
				<button type="button" class="scangeo-cat-tab" data-category="<?php echo esc_attr( $cat ); ?>">
					<?php echo esc_html( isset( $category_labels[ $cat ] ) ? $category_labels[ $cat ] : ucfirst( $cat ) ); ?>
					<span class="scangeo-cat-count"><?php echo (int) $n; ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<div class="scangeo-stats">
			<div class="scangeo-stat">
				<span class="scangeo-stat-value"><?php echo count( $issue_list ); ?></span>
				<span class="scangeo-stat-label">Fallos</span>
			</div>
			<div class="scangeo-stat scangeo-stat-good">
				<span class="scangeo-stat-value" id="scangeo-fixed-count"><?php echo (int) $fixed; ?></span>
				<span class="scangeo-stat-label">Corregidos</span>
			</div>
			<div class="scangeo-stat scangeo-stat-bad">
				<span class="scangeo-stat-value"><?php echo (int) $failed; ?></span>
				<span class="scangeo-stat-label">No reparables</span>
			</div>
			<div class="scangeo-stat scangeo-stat-mid">
				<span class="scangeo-stat-value"><?php echo (int) $manual; ?></span>
				<span class="scangeo-stat-label">Manuales</span>
			</div>
		</div>
		<div class="scangeo-toolbar">
			<div class="scangeo-toolbar-meta">
				<strong>Sitio:</strong> <?php echo esc_html( $report['site'] ? $report['site'] : '—' ); ?>
				&nbsp;·&nbsp; <strong>Generado:</strong> <?php echo esc_html( $report['generated'] ? $report['generated'] : '—' ); ?>
				<?php if ( isset( $report['parser'] ) && 'tolerant' === $report['parser'] ) : ?>
					<span class="scangeo-badge scangeo-badge-warn">Informe sin bloque JSON: parseado en modo tolerante</span>
				<?php endif; ?>
			</div>
			<button type="button" class="button button-primary button-hero" id="scangeo-fix-all">Reparar todo</button>
		</div>
		<div class="scangeo-issues" id="scangeo-issues">
		<?php foreach ( $issue_list as $ix => $issue ) :
			$uid = isset( $issue['uid'] ) ? $issue['uid'] : $ix . '-' . $issue['id'];
			$res = null;
			if ( isset( $results[ $uid ] ) && is_array( $results[ $uid ] ) ) {
				$res = $results[ $uid ];
			} elseif ( isset( $results[ $issue['id'] ] ) && is_array( $results[ $issue['id'] ] ) ) {
				$res = $results[ $issue['id'] ];
			}
			$res_status = $res ? $res['status'] : 'pending';
			?>
			<div class="scangeo-issue" data-uid="<?php echo esc_attr( $uid ); ?>" data-issue="<?php echo esc_attr( $issue['id'] ); ?>" data-status="<?php echo esc_attr( $res_status ); ?>" data-category="<?php echo esc_attr( ! empty( $issue['category'] ) ? $issue['category'] : 'otros' ); ?>">
				<div class="scangeo-issue-status"><span class="scangeo-icon scangeo-icon-<?php echo esc_attr( $res_status ); ?>"></span></div>
				<div class="scangeo-issue-main">
					<div class="scangeo-issue-heading">
						<h3 class="scangeo-issue-title"><?php echo esc_html( $issue['label'] ); ?></h3>
						<span class="scangeo-sev scangeo-sev-<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( $issue['severity'] ); ?></span>
					</div>
					<code class="scangeo-rule-id"><?php echo esc_html( $issue['id'] ); ?></code>
					<?php $explainer = self::rule_description( $issue['id'] ); ?>
					<?php if ( $explainer ) : ?><p class="scangeo-issue-explainer"><?php echo esc_html( $explainer ); ?></p><?php endif; ?>
					<?php if ( $issue['detail'] ) : ?><p class="description"><?php echo self::safe_text( $issue['detail'] ); ?></p><?php endif; ?>

					<?php if ( $issue['pages'] ) : ?>
						<details class="scangeo-issue-pages"><summary><?php echo count( $issue['pages'] ); ?> página(s) afectada(s)</summary>
							<ul class="scangeo-pages">
							<?php foreach ( array_slice( $issue['pages'], 0, 20 ) as $p ) :
								$is_translated = ScanGEO_Fixers::is_translated_url( $p );
								?>
								<li<?php echo $is_translated ? ' class="scangeo-page-translated"' : ''; ?>>
									<a href="<?php echo esc_url( $p ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $p, PHP_URL_PATH ) ? wp_parse_url( $p, PHP_URL_PATH ) : $p ); ?></a>
									<?php if ( $is_translated ) : ?><span class="scangeo-badge scangeo-badge-lang">traducción, no se modifica</span><?php endif; ?>
								</li>
							<?php endforeach; ?>
							</ul>
						</details>
					<?php else : ?>
						<p class="scangeo-issue-scope"><em>Todo el sitio</em></p>
					<?php endif; ?>

					<?php
					// Filtro de seguridad: aunque una propuesta ya se hubiera generado
					// antes de este cambio, nunca se ofrece aplicarla sobre una URL
					// traducida (comparte post con el idioma original).
					$visible_proposal = array();
					if ( $res && ! empty( $res['proposal'] ) ) {
						foreach ( $res['proposal'] as $p_url => $p_text ) {
							if ( ! ScanGEO_Fixers::is_translated_url( $p_url ) ) {
								$visible_proposal[ $p_url ] = $p_text;
							}
						}
					}
					?>
					<div class="scangeo-result-msg">
						<?php if ( $res && $res['message'] ) : ?>
							<div class="scangeo-msg-text"><?php echo self::safe_text( $res['message'] ); ?></div>
						<?php endif; ?>
						<?php if ( $res && 'suggested' === $res_status && ! empty( $visible_proposal ) ) : ?>
							<div class="scangeo-proposal" data-uid="<?php echo esc_attr( $uid ); ?>">
								<?php foreach ( $visible_proposal as $p_url => $p_text ) : ?>
									<div class="scangeo-proposal-item" data-url="<?php echo esc_attr( $p_url ); ?>">
										<small><?php echo esc_html( wp_parse_url( $p_url, PHP_URL_PATH ) ? wp_parse_url( $p_url, PHP_URL_PATH ) : $p_url ); ?></small>
										<textarea class="scangeo-proposal-text" data-url="<?php echo esc_attr( $p_url ); ?>" rows="2"><?php echo esc_textarea( $p_text ); ?></textarea>
										<div class="scangeo-proposal-item-actions">
											<button type="button" class="button button-primary button-small scangeo-apply-one">Aplicar esta</button>
											<button type="button" class="button button-small scangeo-discard-one">Descartar esta</button>
										</div>
									</div>
								<?php endforeach; ?>
								<?php if ( count( $visible_proposal ) > 1 ) : ?>
									<div class="scangeo-proposal-bulk">
										<button type="button" class="button button-primary scangeo-apply-suggestion">Aplicar todas</button>
										<button type="button" class="button scangeo-discard-suggestion">Descartar todas</button>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
				<div class="scangeo-issue-action col-action">
					<?php if ( $res && 'suggested' === $res_status ) : ?>
						<em>Revisar arriba ↑</em>
					<?php elseif ( $res && 'fixed' === $res_status && ! empty( $res['undo'] ) ) : ?>
						<button type="button" class="button scangeo-undo-fix">Deshacer</button>
					<?php elseif ( $res && 'manual' === $res_status ) : ?>
						<em class="scangeo-manual-note">Solución manual</em>
					<?php else : ?>
						<button type="button" class="button scangeo-fix-one" <?php disabled( 'fixed' === $res_status ); ?>>Reparar</button>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<p class="description scangeo-legend">✔ corregido automáticamente · ✖ no se pudo arreglar (ver motivo) · ✋ requiere acción manual · 📝 propuesta de IA pendiente de revisar. Todos los cambios se pueden deshacer con el botón "Deshacer".</p>
		<?php
	}
}
