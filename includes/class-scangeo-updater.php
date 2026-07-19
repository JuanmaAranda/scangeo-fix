<?php
/**
 * Comprueba en un repositorio de GitHub si hay una versión más reciente del
 * plugin que la instalada, y lo conecta con el sistema de actualizaciones
 * nativo de WordPress (el mismo que usan los plugins del repositorio
 * oficial): aparece en Plugins → "Hay una nueva versión disponible" con su
 * botón "Actualizar ahora", sin que el usuario tenga que hacerlo a mano.
 *
 * CÓMO FUNCIONA (ya configurado para este plugin):
 * El plugin descarga el .zip directamente desde /dist/scangeo-fixer.zip
 * dentro de este mismo repositorio (usando el tag de cada versión), en vez
 * de depender de los "adjuntos" de un Release de GitHub. Esto permite
 * publicar una versión nueva subiendo el código y el .zip con un simple
 * "git push" más la creación del Release (ambos automatizables), sin
 * ningún paso manual en la web de GitHub.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScanGEO_Updater {

	/** Cambia esto por "tu-usuario-de-github/tu-repositorio". */
	const REPO = 'JuanmaAranda/scangeo-fix';

	const CACHE_KEY       = 'scangeo_fixer_latest_release';
	const CACHE_KEY_ALL   = 'scangeo_fixer_all_releases';
	const PLUGIN_FILE     = 'scangeo-fixer/scangeo-fixer.php';
	const SLUG            = 'scangeo-fixer';

	private static $initialized = false;

	public static function init() {
		// Guarda de seguridad: si algo llegara a llamar a init() más de una
		// vez en la misma petición, evita registrar los filtros por
		// duplicado (causaría, por ejemplo, un "Ver detalles" repetido).
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
	}

	/**
	 * Añade el enlace "Ver detalles" en la fila del plugin (listado de
	 * Plugins), igual que hacen los plugins de WordPress.org. Abre el mismo
	 * modal (thickbox) usando la información que ya devuelve plugin_info()
	 * más arriba — no depende de estar en el repositorio oficial.
	 */
	public static function row_meta( $links, $file ) {
		if ( self::PLUGIN_FILE !== $file ) {
			return $links;
		}
		// Evita añadir un segundo enlace idéntico si, por lo que sea
		// (otro plugin de gestión de actualizaciones, un filtro externo),
		// ya hay uno con el mismo texto en la fila.
		foreach ( $links as $existing ) {
			if ( false !== strpos( (string) $existing, 'open-plugin-details-modal' ) ) {
				return $links;
			}
		}
		$details_url = self_admin_url(
			'plugin-install.php?tab=plugin-information&plugin=' . self::SLUG . '&TB_iframe=true&width=600&height=550'
		);
		$links[] = '<a href="' . esc_url( $details_url ) . '" class="thickbox open-plugin-details-modal" aria-label="Más información sobre scanGEO Fixer">Ver detalles</a>';
		return $links;
	}

	/**
	 * Se ejecuta justo después de cualquier actualización de plugin. Limpia
	 * nuestra caché de GitHub y el transient de actualizaciones de
	 * WordPress para que, tras actualizar, no se quede colgado un aviso
	 * fantasma diciendo que hay una versión nueva cuando ya se acaba de
	 * instalar.
	 */
	public static function after_update( $upgrader_object, $hook_extra ) {
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] || empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array();
		if ( ! in_array( self::PLUGIN_FILE, $plugins, true ) ) {
			return;
		}
		delete_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_KEY_ALL );
		delete_site_transient( 'update_plugins' );
	}

	/** Devuelve solo el número de versión más reciente (o '' si no se sabe), para mostrarlo en pantalla. */
	public static function get_latest_version() {
		$release = self::get_latest_release();
		return $release ? $release['version'] : '';
	}

	/** Consulta la API de GitHub (con caché de 6 horas) y devuelve los datos del último release. */
	private static function get_latest_release() {
		if ( false !== strpos( self::REPO, 'TU-USUARIO' ) ) {
			return false; // Todavía no se ha configurado el repositorio real.
		}
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached ? $cached : false;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
				'timeout' => 10,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, '', 6 * HOUR_IN_SECONDS );
			return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, '', 6 * HOUR_IN_SECONDS );
			return false;
		}

		$version = trim( ltrim( (string) $data['tag_name'], 'vV' ) );

		// El .zip del plugin se sube como un archivo más dentro del propio
		// repositorio (en /dist), en vez de como "asset" adjunto al release.
		// raw.githubusercontent.com sirve ese archivo tal cual, sin pasar
		// por el sistema de adjuntos de GitHub.
		$zip_url = 'https://raw.githubusercontent.com/' . self::REPO . '/' . rawurlencode( (string) $data['tag_name'] ) . '/dist/scangeo-fixer.zip';

		$result = array(
			'version'   => $version,
			'zip_url'   => $zip_url,
			'notes_url' => isset( $data['html_url'] ) ? $data['html_url'] : '',
			'body'      => isset( $data['body'] ) ? $data['body'] : '',
		);
		set_transient( self::CACHE_KEY, $result, 6 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Consulta el listado completo de releases (no solo el último) para
	 * construir un registro de cambios con varias versiones, como el que
	 * enseñan los plugins de wordpress.org. Caché de 6 horas.
	 */
	private static function get_all_releases() {
		if ( false !== strpos( self::REPO, 'TU-USUARIO' ) ) {
			return array();
		}
		$cached = get_transient( self::CACHE_KEY_ALL );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases?per_page=15',
			array(
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
				'timeout' => 10,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY_ALL, array(), 6 * HOUR_IN_SECONDS );
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			set_transient( self::CACHE_KEY_ALL, array(), 6 * HOUR_IN_SECONDS );
			return array();
		}
		$releases = array();
		foreach ( $data as $item ) {
			if ( empty( $item['tag_name'] ) ) {
				continue;
			}
			$releases[] = array(
				'version' => trim( ltrim( (string) $item['tag_name'], 'vV' ) ),
				'date'    => isset( $item['published_at'] ) ? $item['published_at'] : '',
				'body'    => isset( $item['body'] ) ? $item['body'] : '',
			);
		}
		set_transient( self::CACHE_KEY_ALL, $releases, 6 * HOUR_IN_SECONDS );
		return $releases;
	}

	/** Construye el HTML del registro de cambios a partir de varias versiones. */
	private static function build_changelog_html() {
		$releases = self::get_all_releases();
		if ( empty( $releases ) ) {
			return 'Sin notas de la versión.';
		}
		$html = '';
		foreach ( $releases as $r ) {
			$date  = $r['date'] ? mysql2date( 'd/m/Y', $r['date'] ) : '';
			$html .= '<h4>Versión ' . esc_html( $r['version'] ) . ( $date ? ' — ' . esc_html( $date ) : '' ) . '</h4>';
			$html .= $r['body'] ? wpautop( wp_kses_post( $r['body'] ) ) : '<p><em>Sin notas.</em></p>';
		}
		return $html;
	}

	/** Engancha la versión de GitHub al comprobador de actualizaciones de WordPress. */
	public static function check_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::get_latest_release();
		if ( ! $release || empty( $release['zip_url'] ) ) {
			return $transient;
		}
		$installed = trim( (string) SCANGEO_FIXER_VERSION );
		$latest    = trim( (string) $release['version'] );
		if ( version_compare( $latest, $installed, '>' ) ) {
			$icon_url = defined( 'SCANGEO_FIXER_URL' ) ? SCANGEO_FIXER_URL . 'assets/icon.png' : '';
			$transient->response[ self::PLUGIN_FILE ] = (object) array(
				'slug'        => self::SLUG,
				'plugin'      => self::PLUGIN_FILE,
				'new_version' => $latest,
				'url'         => $release['notes_url'],
				'package'     => $release['zip_url'],
				'tested'      => get_bloginfo( 'version' ),
				'icons'       => $icon_url ? array( '1x' => $icon_url, '2x' => $icon_url, 'default' => $icon_url ) : array(),
			);
		} else {
			// Muy importante: si NO hay versión más nueva, hay que quitar
			// explícitamente cualquier entrada previa para este plugin
			// (si no, un aviso de una comprobación anterior podría quedarse
			// "pegado" en el transient aunque ya no aplique).
			unset( $transient->response[ self::PLUGIN_FILE ] );
			if ( ! isset( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ self::PLUGIN_FILE ] = (object) array(
				'slug'        => self::SLUG,
				'plugin'      => self::PLUGIN_FILE,
				'new_version' => $installed,
				'url'         => $release['notes_url'],
				'package'     => '',
			);
		}
		return $transient;
	}

	/** Rellena la ventana de "Ver detalles de la versión" en el listado de plugins. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::get_latest_release();
		if ( ! $release ) {
			return $result;
		}
		$icon_url   = defined( 'SCANGEO_FIXER_URL' ) ? SCANGEO_FIXER_URL . 'assets/icon.png' : '';
		$banner_low = defined( 'SCANGEO_FIXER_URL' ) ? SCANGEO_FIXER_URL . 'assets/banner-772x250.png' : '';
		$banner_hi  = defined( 'SCANGEO_FIXER_URL' ) ? SCANGEO_FIXER_URL . 'assets/banner-1544x500.png' : '';

		$description = '<p><strong>scanGEO Fixer</strong> lee el informe .md exportado desde scanGEO.app (con su bloque de datos de automatización), muestra tu puntuación por categoría (técnico, contenido, GEO, off-page) y repara los fallos detectados directamente desde tu WordPress:</p>'
			. '<ul>'
			. '<li>✔ Automáticamente cuando es seguro: canonical, viewport, lang, Open Graph, JSON-LD, robots.txt, sitemap, llms.txt, alt de imágenes.</li>'
			. '<li>📝 Con una propuesta de IA que revisas y apruebas cuando toca contenido: meta descriptions, títulos, FAQ, respuesta directa, enlaces internos, ampliar contenido corto.</li>'
			. '<li>✋ O te explica exactamente qué hacer a mano cuando no se puede automatizar (por ejemplo, si ya lo gestiona Yoast o Rank Math).</li>'
			. '</ul>'
			. '<p>Cualquier cambio aplicado se puede deshacer con un clic, y el plugin guarda el histórico de puntuaciones entre informes para ver la evolución de tu web con el tiempo.</p>';

		$installation = '<ol>'
			. '<li>Sube la carpeta <code>scangeo-fixer</code> a <code>/wp-content/plugins/</code>, o instala el .zip desde Plugins → Añadir nuevo → Subir plugin.</li>'
			. '<li>Activa el plugin.</li>'
			. '<li>(Opcional, recomendado) En scanGEO Fixer → Ajustes, añade tu clave de IA (Anthropic u OpenAI) para las propuestas de contenido y el resumen en palabras sencillas.</li>'
			. '<li>Ve a scanGEO Fixer, sube el informe .md exportado desde scanGEO.app y pulsa "Reparar todo".</li>'
			. '</ol>';

		return (object) array(
			'name'          => 'scanGEO Fixer',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://scangeo.app">scanGEO.app</a>',
			'homepage'      => $release['notes_url'],
			'tested'        => get_bloginfo( 'version' ),
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'last_updated'  => isset( $release['date'] ) ? $release['date'] : '',
			'sections'      => array(
				'description'  => $description,
				'installation' => $installation,
				'changelog'    => self::build_changelog_html(),
			),
			'download_link' => $release['zip_url'],
			'icons'         => $icon_url ? array( '1x' => $icon_url, '2x' => $icon_url, 'default' => $icon_url ) : array(),
			'banners'       => ( $banner_low || $banner_hi ) ? array( 'low' => $banner_low, 'high' => $banner_hi ) : array(),
		);
	}

	/**
	 * Si se usa el zip automático de GitHub (sin adjuntar uno propio), la
	 * carpeta interna tiene un nombre distinto (usuario-repo-hash). Esto la
	 * renombra a "scangeo-fixer" para que WordPress lo trate como una
	 * actualización del mismo plugin en vez de instalar uno nuevo aparte.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::PLUGIN_FILE !== $hook_extra['plugin'] ) {
			return $source;
		}
		$desired = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( $source !== $desired && $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}
		return $source;
	}
}
