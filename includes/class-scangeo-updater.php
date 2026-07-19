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

	const CACHE_KEY = 'scangeo_fixer_latest_release';
	const PLUGIN_FILE = 'scangeo-fixer/scangeo-fixer.php';
	const SLUG = 'scangeo-fixer';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
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

		$version = ltrim( (string) $data['tag_name'], 'vV' );

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

	/** Engancha la versión de GitHub al comprobador de actualizaciones de WordPress. */
	public static function check_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::get_latest_release();
		if ( ! $release || empty( $release['zip_url'] ) ) {
			return $transient;
		}
		if ( version_compare( $release['version'], SCANGEO_FIXER_VERSION, '>' ) ) {
			$transient->response[ self::PLUGIN_FILE ] = (object) array(
				'slug'        => self::SLUG,
				'plugin'      => self::PLUGIN_FILE,
				'new_version' => $release['version'],
				'url'         => $release['notes_url'],
				'package'     => $release['zip_url'],
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
		return (object) array(
			'name'          => 'scanGEO Fixer',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://scangeo.app">scanGEO.app</a>',
			'homepage'      => $release['notes_url'],
			'sections'      => array(
				'changelog' => $release['body'] ? wpautop( wp_kses_post( $release['body'] ) ) : 'Sin notas de la versión.',
			),
			'download_link' => $release['zip_url'],
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
