<?php
/**
 * Registro de reparadores: cada regla de scanGEO se mapea a un método
 * que intenta arreglarla y devuelve:
 *   array( 'status' => 'fixed'|'failed'|'manual'|'suggested', 'message' => '...' )
 *
 * - fixed:     arreglado automáticamente (check verde). Puede incluir 'undo'
 *              con los datos necesarios para revertir el cambio.
 * - failed:    se intentó y no fue posible (se explica por qué).
 * - manual:    requiere intervención humana (edición de contenido, hosting...).
 * - suggested: hay una propuesta (texto/HTML) generada por IA lista para que
 *              el usuario la revise/edite y la aplique con un segundo paso
 *              (ver apply()). Nunca se guarda nada en el sitio en este paso.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScanGEO_Fixers {

	/** Punto de entrada: analiza el fallo y genera una reparación o una propuesta. */
	public static function fix( $issue ) {
		$map = array(
			'content.meta_description'  => 'suggest_meta_description',
			'content.title_length'      => 'suggest_title',
			'content.images_alt'        => 'fix_images_alt',
			'tech.canonical'            => 'fix_canonical',
			'tech.mobile_viewport'      => 'fix_viewport',
			'content.lang'              => 'fix_lang',
			'geo.open_graph'            => 'fix_open_graph',
			'geo.jsonld'                => 'fix_schema_org',
			'geo.entity_organization'   => 'fix_schema_org',
			'offpage.brand_mention'     => 'fix_schema_org',
			'geo.entity_author'         => 'fix_schema_article',
			'geo.entity_person_author'  => 'fix_schema_article',
			'content.eeat_author'       => 'fix_schema_article',
			'geo.date_modified'         => 'fix_schema_article',
			'tech.robots_txt'           => 'fix_robots',
			'geo.ai_crawlers_access'    => 'fix_robots',
			'tech.sitemap'              => 'fix_sitemap',
			'geo.llms_txt'              => 'fix_llms_txt',
			'offpage.social_signals'    => 'fix_social_signals',
			'geo.faq_or_qa'             => 'suggest_faq',
			'geo.direct_answer'         => 'suggest_direct_answer',
			'content.internal_links'    => 'suggest_internal_links',
			'content.body_length'       => 'suggest_body_expansion',
		);

		$id = $issue['id'];
		if ( isset( $map[ $id ] ) ) {
			$method = $map[ $id ];
			return self::$method( $issue );
		}
		return self::manual_guidance( $issue );
	}

	/**
	 * Segundo paso para las reglas que devuelven 'suggested': aplica el texto
	 * ya revisado (y posiblemente editado) por el usuario.
	 *
	 * @param array $issue  El issue original del informe.
	 * @param array $edited Array url => texto/HTML editado por el usuario.
	 */
	public static function apply( $issue, $edited ) {
		switch ( $issue['id'] ) {
			case 'content.meta_description':
				return self::apply_meta_description( $edited );
			case 'content.title_length':
				return self::apply_title( $edited );
			case 'geo.faq_or_qa':
				return self::apply_block_insert( $edited, 'faq' );
			case 'geo.direct_answer':
				return self::apply_block_insert( $edited, 'answer' );
			case 'content.internal_links':
				return self::apply_block_insert( $edited, 'links' );
			case 'content.body_length':
				return self::apply_block_insert( $edited, 'expand' );
		}
		return array( 'status' => 'failed', 'message' => 'Esta regla no admite aplicar una propuesta.' );
	}

	/** Deshace un fixer ya aplicado, restaurando lo guardado en 'undo'. */
	public static function undo( $undo_data ) {
		if ( empty( $undo_data ) || empty( $undo_data['type'] ) || empty( $undo_data['items'] ) ) {
			return array( 'status' => 'pending', 'message' => 'No había nada que deshacer.' );
		}
		$n = 0;
		if ( 'meta' === $undo_data['type'] ) {
			foreach ( $undo_data['items'] as $post_id => $item ) {
				update_post_meta( (int) $post_id, $item['meta'], $item['value'] );
				$n++;
			}
		} elseif ( 'content' === $undo_data['type'] ) {
			foreach ( $undo_data['items'] as $post_id => $item ) {
				wp_update_post( array( 'ID' => (int) $post_id, 'post_content' => $item['content'] ) );
				$n++;
			}
		} elseif ( 'flag' === $undo_data['type'] ) {
			foreach ( $undo_data['items'] as $flag => $prev ) {
				self::set_flag( $flag, $prev );
			}
			$n = count( $undo_data['items'] );
		}
		return array( 'status' => 'pending', 'message' => 'Deshecho: ' . $n . ' cambio(s) revertido(s) a su valor anterior.' );
	}

	/* ---------------------------------------------------------------------
	 * Utilidades
	 * ------------------------------------------------------------------- */

	private static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return '';
	}

	private static function meta_keys() {
		switch ( self::seo_plugin() ) {
			case 'yoast':
				return array( 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' );
			case 'rankmath':
				return array( 'title' => 'rank_math_title', 'desc' => 'rank_math_description' );
			case 'seopress':
				return array( 'title' => '_seopress_titles_title', 'desc' => '_seopress_titles_desc' );
			default:
				return array( 'title' => '_scangeo_seo_title', 'desc' => '_scangeo_meta_description' );
		}
	}

	/** Resuelve una URL del informe a un post/página local. */
	private static function url_to_post( $url ) {
		// TranslatePress no crea una página por idioma: traduce también el
		// propio slug de la URL. Sin esto, "/en/mi-titulo-en-ingles/" nunca
		// coincidiría con la página real en español.
		$url = self::detranslate_url( $url );

		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			return $post_id;
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			$front = (int) get_option( 'page_on_front' );
			return $front > 0 ? $front : 0;
		}
		$path_nolang = preg_replace( '#^[a-z]{2}(-[a-z]{2,4})?/#i', '', $path );
		foreach ( array_unique( array( $path, $path_nolang ) ) as $try ) {
			$page = get_page_by_path( $try );
			if ( $page ) {
				return $page->ID;
			}
		}
		$slug = sanitize_title( basename( $path ) );
		if ( $slug ) {
			$found = get_posts( array(
				'name'        => $slug,
				'post_type'   => 'any',
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
			) );
			if ( $found ) {
				return (int) $found[0];
			}
		}
		return 0;
	}

	/**
	 * Si TranslatePress está activo y la URL pertenece a un idioma distinto
	 * del original del sitio, devuelve la URL equivalente en el idioma
	 * original (el único que existe como post real en la base de datos).
	 * Si no se puede resolver, devuelve la URL tal cual llegó.
	 */
	private static function detranslate_url( $url ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) || ! function_exists( 'trp_get_languages' ) ) {
			return $url; // TranslatePress no está activo: nada que traducir.
		}
		if ( ! function_exists( 'get_option' ) ) {
			return $url;
		}
		$settings      = get_option( 'trp_settings', array() );
		$default_lang  = ! empty( $settings['default-language'] ) ? $settings['default-language'] : '';
		if ( ! $default_lang ) {
			return $url;
		}
		if ( ! method_exists( 'TRP_Translate_Press', 'get_trp_instance' ) ) {
			return $url;
		}
		$trp = TRP_Translate_Press::get_trp_instance();
		if ( ! $trp || ! method_exists( $trp, 'get_component' ) ) {
			return $url;
		}
		$url_converter = $trp->get_component( 'url_converter' );
		if ( ! $url_converter || ! method_exists( $url_converter, 'get_url_for_language' ) ) {
			return $url;
		}
		$original = $url_converter->get_url_for_language( $default_lang, $url, '' );
		return $original ? $original : $url;
	}

	/**
	 * Devuelve el nombre del idioma (p. ej. "inglés") en el que se visita
	 * esta URL, si TranslatePress está activo y la URL no es del idioma
	 * por defecto. Devuelve '' si es el idioma por defecto o si no se
	 * puede determinar (para no forzar nada en ese caso).
	 */
	private static function url_language_name( $url ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) || ! function_exists( 'trp_get_languages' ) ) {
			return '';
		}
		$settings = get_option( 'trp_settings', array() );
		$default  = ! empty( $settings['default-language'] ) ? $settings['default-language'] : '';
		if ( ! $default ) {
			return '';
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$slug = strtolower( strtok( $path, '/' ) );

		$languages = trp_get_languages(); // array( 'en_US' => 'English', ... )
		$lang_code = '';

		// 1) Slugs personalizados (Ajustes → TranslatePress → URL slugs).
		$url_slugs = ! empty( $settings['url-slugs'] ) && is_array( $settings['url-slugs'] ) ? $settings['url-slugs'] : array();
		foreach ( $url_slugs as $code => $custom_slug ) {
			if ( '' !== (string) $custom_slug && strtolower( (string) $custom_slug ) === $slug ) {
				$lang_code = $code;
				break;
			}
		}
		// 2) Sin slug personalizado: TranslatePress usa el código corto (es, en, fr...).
		if ( '' === $lang_code ) {
			foreach ( array_keys( $languages ) as $code ) {
				if ( strtolower( substr( (string) $code, 0, 2 ) ) === $slug ) {
					$lang_code = $code;
					break;
				}
			}
		}
		if ( '' === $lang_code || $lang_code === $default ) {
			return ''; // Es el idioma por defecto (o no se ha podido determinar): no forzar nada.
		}
		return isset( $languages[ $lang_code ] ) ? $languages[ $lang_code ] : '';
	}

	/**
	 * true si esta URL es una traducción (TranslatePress) de otra página.
	 * Estas URLs comparten el mismo post en la base de datos que la versión
	 * en el idioma original: escribir un meta, un alt o un bloque de
	 * contenido "solo para esa URL" en realidad lo escribe en el post
	 * compartido y se mezcla con la versión original. Por eso estas URLs
	 * se dejan sin tocar en vez de arriesgarse a mezclar idiomas.
	 */
	public static function is_translated_url( $url ) {
		return '' !== self::url_language_name( $url );
	}

	private static function flag_value( $flag ) {
		$flags = get_option( 'scangeo_flags', array() );
		return ! empty( $flags[ $flag ] );
	}

	private static function set_flag( $flag, $value = true ) {
		$flags          = get_option( 'scangeo_flags', array() );
		$flags[ $flag ] = (bool) $value;
		update_option( 'scangeo_flags', $flags );
	}

	/** Texto base de un post para prompts/heurísticas. */
	private static function post_text( $post_id, $chars = 1200 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$text = $post->post_excerpt ? $post->post_excerpt . ' ' : '';
		$text .= wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text  = preg_replace( '/\s+/u', ' ', $text );
		return mb_substr( trim( $text ), 0, $chars );
	}

	/** Recorta a un máximo de caracteres sin partir palabras. */
	private static function smart_trim( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		$pos = mb_strrpos( $cut, ' ' );
		return $pos ? rtrim( mb_substr( $cut, 0, $pos ), ' ,;:.' ) : $cut;
	}

	private static function manual_guidance( $issue ) {
		$guides = array(
			'content.h1_unique'         => 'Requiere revisar la plantilla o el contenido: asegúrate de un único H1 descriptivo en el contenido principal. No se modifica automáticamente para no romper el diseño.',
			'content.heading_hierarchy' => 'Requiere edición manual: reordena los headings del contenido (H1→H2→H3 sin saltos). Cambiarlos automáticamente podría alterar el significado.',
			'geo.short_paragraphs'      => 'Requiere edición: divide los párrafos largos (>80 palabras) del contenido principal.',
			'geo.content_in_raw_html'   => 'Depende del stack: tu contenido se genera por JavaScript y los crawlers de IA no lo ven. En WordPress puro esto es raro; si usas un tema/builder JS-heavy, activa renderizado en servidor o caché de HTML estático.',
			'geo.semantic_structure'    => 'Requiere ajustar la plantilla: usa <main>/<article>, listas y tablas para datos estructurados.',
			'tech.core_web_vitals'      => 'Rendimiento: instala/configura un plugin de caché y optimización de imágenes (p. ej. WP Rocket, LiteSpeed Cache, Perfmatters) y revisa el hosting. No se activa automáticamente por seguridad.',
			'tech.pagespeed_mobile'     => 'Rendimiento: igual que Core Web Vitals — caché, imágenes WebP/AVIF, menos JS de terceros.',
			'tech.http_status'          => 'Revisa manualmente los redirects/errores listados (usa un plugin de redirecciones si necesitas 301).',
			'offpage.https_domain'      => 'Requiere hosting: instala un certificado SSL y fuerza HTTPS desde tu panel de hosting. Después actualiza siteurl/home a https://.',
			'offpage.backlinks_pro'     => 'Off-page: los backlinks no se pueden arreglar desde el plugin; requiere estrategia de enlaces externa.',
		);
		$msg = isset( $guides[ $issue['id'] ] )
			? $guides[ $issue['id'] ]
			: 'Esta regla no tiene reparación automática. Revisa la recomendación del informe.';
		return array( 'status' => 'manual', 'message' => $msg );
	}

	/* ---------------------------------------------------------------------
	 * Propuestas de metadatos (requieren revisión antes de guardarse)
	 * ------------------------------------------------------------------- */

	private static function suggest_meta_description( $issue ) {
		if ( empty( $issue['pages'] ) ) {
			return array( 'status' => 'failed', 'message' => 'El informe no incluye las URLs afectadas.' );
		}
		$proposal = array();
		$ko       = array();
		foreach ( array_slice( $issue['pages'], 0, 15 ) as $url ) {
			if ( self::is_translated_url( $url ) ) {
				$ko[] = $url . ' (URL traducida por TranslatePress: se omite para no mezclar idiomas en el mismo post)';
				continue;
			}
			$post_id = self::url_to_post( $url );
			if ( ! $post_id ) {
				$ko[] = $url . ' (no se encontró el post)';
				continue;
			}
			$desc = '';
			if ( ScanGEO_AI::is_configured() ) {
				$prompt = "Escribe una meta description en el idioma del texto, de entre 120 y 155 caracteres, atractiva y fiel al contenido. Devuelve SOLO el texto, sin comillas.\n\nTítulo: " . get_the_title( $post_id ) . "\nContenido: " . self::post_text( $post_id );
				$lang_name = self::url_language_name( $url );
				if ( $lang_name ) {
					$prompt .= "\n\nIMPORTANTE: el contenido de referencia está en otro idioma, pero esta página se visita en {$lang_name}. Escribe la meta description en {$lang_name}.";
				}
				$ai     = ScanGEO_AI::generate( $prompt, 200 );
				if ( ! is_wp_error( $ai ) ) {
					$desc = self::smart_trim( $ai, 160 );
				}
			}
			if ( '' === $desc ) {
				$desc = self::smart_trim( self::post_text( $post_id, 300 ), 155 );
			}
			if ( mb_strlen( $desc ) < 50 ) {
				$ko[] = $url . ' (contenido insuficiente para generar descripción)';
				continue;
			}
			$proposal[ $url ] = $desc;
		}
		if ( empty( $proposal ) ) {
			return array( 'status' => 'failed', 'message' => 'No se pudo generar ninguna propuesta: ' . implode( '; ', array_slice( $ko, 0, 5 ) ) );
		}
		$note = self::seo_plugin() ? ( 'Se guardará en ' . self::seo_plugin() . '.' ) : 'Se mostrará en tu web (no hay plugin SEO activo).';
		return array(
			'status'   => 'suggested',
			'message'  => count( $proposal ) . ' propuesta(s) de meta description lista(s) para revisar. ' . $note,
			'proposal' => $proposal,
		);
	}

	private static function apply_meta_description( $edited ) {
		$keys = self::meta_keys();
		$ok   = 0;
		$ko   = array();
		$undo = array();
		foreach ( (array) $edited as $url => $text ) {
			if ( self::is_translated_url( $url ) ) {
				$ko[] = $url . ' (URL traducida por TranslatePress)';
				continue;
			}
			$text = self::smart_trim( sanitize_text_field( (string) $text ), 160 );
			if ( mb_strlen( $text ) < 20 ) {
				continue;
			}
			$post_id = self::url_to_post( $url );
			if ( ! $post_id ) {
				$ko[] = $url;
				continue;
			}
			$undo[ $post_id ] = array( 'meta' => $keys['desc'], 'value' => get_post_meta( $post_id, $keys['desc'], true ) );
			update_post_meta( $post_id, $keys['desc'], $text );
			$ok++;
		}
		if ( 0 === $ok ) {
			return array( 'status' => 'failed', 'message' => 'No se pudo aplicar ninguna propuesta.' );
		}
		return array(
			'status'  => 'fixed',
			'message' => $ok . ' meta description(s) aplicada(s).',
			'undo'    => array( 'type' => 'meta', 'items' => $undo ),
		);
	}

	private static function suggest_title( $issue ) {
		if ( empty( $issue['pages'] ) ) {
			return array( 'status' => 'failed', 'message' => 'El informe no incluye las URLs afectadas.' );
		}
		$proposal = array();
		$ko       = array();
		foreach ( array_slice( $issue['pages'], 0, 15 ) as $url ) {
			if ( self::is_translated_url( $url ) ) {
				$ko[] = $url . ' (URL traducida por TranslatePress: se omite para no mezclar idiomas en el mismo post)';
				continue;
			}
			$post_id = self::url_to_post( $url );
			if ( ! $post_id ) {
				$ko[] = $url . ' (no se encontró el post)';
				continue;
			}
			$current = get_the_title( $post_id );
			$title   = '';
			if ( ScanGEO_AI::is_configured() ) {
				$prompt = "Reescribe este título SEO para que tenga entre 30 y 60 caracteres, en el mismo idioma, manteniendo la palabra clave principal. Devuelve SOLO el título.\n\nTítulo actual: " . $current . "\nResumen del contenido: " . self::post_text( $post_id, 400 );
				$lang_name = self::url_language_name( $url );
				if ( $lang_name ) {
					$prompt .= "\n\nIMPORTANTE: el título actual y el resumen están en otro idioma, pero esta página se visita en {$lang_name}. Escribe el nuevo título en {$lang_name}.";
				}
				$ai     = ScanGEO_AI::generate( $prompt, 100 );
				if ( ! is_wp_error( $ai ) ) {
					$title = self::smart_trim( $ai, 60 );
				}
			}
			if ( '' === $title ) {
				$title = mb_strlen( $current ) > 60
					? self::smart_trim( $current, 60 )
					: self::smart_trim( $current . ' | ' . get_bloginfo( 'name' ), 60 );
			}
			if ( mb_strlen( $title ) < 15 ) {
				$ko[] = $url . ' (no se pudo generar un título válido)';
				continue;
			}
			$proposal[ $url ] = $title;
		}
		if ( empty( $proposal ) ) {
			return array( 'status' => 'failed', 'message' => 'No se pudo generar ninguna propuesta: ' . implode( '; ', array_slice( $ko, 0, 5 ) ) );
		}
		return array(
			'status'   => 'suggested',
			'message'  => count( $proposal ) . ' propuesta(s) de título lista(s) para revisar. El H1 del contenido no se toca, solo la etiqueta <title>.',
			'proposal' => $proposal,
		);
	}

	private static function apply_title( $edited ) {
		$keys = self::meta_keys();
		$ok   = 0;
		$undo = array();
		foreach ( (array) $edited as $url => $text ) {
			if ( self::is_translated_url( $url ) ) {
				continue;
			}
			$text = self::smart_trim( sanitize_text_field( (string) $text ), 60 );
			if ( mb_strlen( $text ) < 10 ) {
				continue;
			}
			$post_id = self::url_to_post( $url );
			if ( ! $post_id ) {
				continue;
			}
			$undo[ $post_id ] = array( 'meta' => $keys['title'], 'value' => get_post_meta( $post_id, $keys['title'], true ) );
			update_post_meta( $post_id, $keys['title'], $text );
			$ok++;
		}
		if ( 0 === $ok ) {
			return array( 'status' => 'failed', 'message' => 'No se pudo aplicar ninguna propuesta.' );
		}
		return array(
			'status'  => 'fixed',
			'message' => $ok . ' título(s) SEO aplicado(s).',
			'undo'    => array( 'type' => 'meta', 'items' => $undo ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Propuestas de contenido generadas por IA (FAQ, respuesta directa,
	 * enlaces internos): se insertan solo cuando el usuario las aprueba.
	 * ------------------------------------------------------------------- */

	private static function suggest_faq( $issue ) {
		return self::suggest_content_block( $issue, 'faq' );
	}

	private static function suggest_direct_answer( $issue ) {
		return self::suggest_content_block( $issue, 'answer' );
	}

	private static function suggest_internal_links( $issue ) {
		return self::suggest_content_block( $issue, 'links' );
	}

	private static function suggest_body_expansion( $issue ) {
		return self::suggest_content_block( $issue, 'expand' );
	}

	private static function suggest_content_block( $issue, $type ) {
		if ( ! ScanGEO_AI::is_configured() ) {
			return array(
				'status'  => 'manual',
				'message' => 'Esta mejora necesita IA generativa para redactar la propuesta: configura una clave API en scanGEO Fixer → Ajustes y vuelve a pulsar Reparar.',
			);
		}
		if ( empty( $issue['pages'] ) ) {
			return array( 'status' => 'failed', 'message' => 'El informe no incluye las URLs afectadas.' );
		}
		$all_pages = $issue['pages'];
		$ko        = array();
		$candidates = array();
		foreach ( $all_pages as $url ) {
			if ( self::is_translated_url( $url ) ) {
				$ko[] = $url . ' (URL traducida por TranslatePress: se omite para no mezclar idiomas en el mismo post)';
				continue;
			}
			$candidates[] = $url;
		}
		$pages    = array_slice( $candidates, 0, 6 );
		$proposal = array();
		foreach ( $pages as $url ) {
			$post_id = self::url_to_post( $url );
			if ( ! $post_id ) {
				$ko[] = $url . ' (no se encontró el post)';
				continue;
			}
			$html = self::generate_block( $post_id, $type, $url );
			if ( is_wp_error( $html ) ) {
				$ko[] = $url . ' (' . $html->get_error_message() . ')';
				continue;
			}
			if ( $html ) {
				$proposal[ $url ] = $html;
			} else {
				$ko[] = $url . ' (la IA devolvió una respuesta vacía)';
			}
		}
		if ( empty( $proposal ) ) {
			return array( 'status' => 'failed', 'message' => 'No se pudo generar ninguna propuesta: ' . implode( '; ', array_slice( $ko, 0, 5 ) ) );
		}
		$extra = count( $candidates ) > count( $pages )
			? ' (mostrando ' . count( $pages ) . ' de ' . count( $candidates ) . ' páginas aplicables; pulsa Reparar de nuevo tras aplicar estas para ver más)'
			: '';
		return array(
			'status'   => 'suggested',
			'message'  => count( $proposal ) . ' propuesta(s) redactada(s) por IA, lista(s) para revisar' . $extra . '.',
			'proposal' => $proposal,
		);
	}

	/** Genera el bloque HTML con IA. Devuelve string, string vacío o WP_Error. */
	private static function generate_block( $post_id, $type, $url = '' ) {
		$title = get_the_title( $post_id );
		$text  = self::post_text( $post_id, 1500 );

		if ( 'faq' === $type ) {
			$prompt = "A partir de este contenido, redacta de 3 a 5 preguntas frecuentes con su respuesta breve, en el mismo idioma del texto. Formato HTML simple: cada pregunta en una etiqueta <h3> y su respuesta en <p>. No añadas introducción ni nada fuera de ese HTML.\n\nTítulo: {$title}\nContenido: {$text}";
			$max    = 500;
		} elseif ( 'answer' === $type ) {
			$prompt = "Escribe un único párrafo de menos de 60 palabras, en el mismo idioma del texto, que responda directamente a la pregunta principal que resuelve esta página (una 'respuesta directa' al estilo de un featured snippet). Devuelve SOLO el párrafo dentro de una etiqueta <p>, sin comillas ni explicaciones.\n\nTítulo: {$title}\nContenido: {$text}";
			$max    = 150;
		} elseif ( 'links' === $type ) {
			$prompt = "Sugiere de 2 a 3 enlaces internos que tendría sentido añadir en esta página, basándote solo en su título y tema. Devuelve una lista HTML <ul><li>...</li></ul> donde cada línea proponga un texto ancla y describa brevemente a qué tipo de página debería apuntar. No inventes URLs concretas, solo el tipo de página. En el mismo idioma del texto.\n\nTítulo: {$title}\nContenido: {$text}";
			$max    = 300;
		} else { // expand
			$prompt = "Este contenido se ha quedado corto para el tema que trata. Escribe entre 150 y 250 palabras adicionales que lo amplíen con información relevante, útil y no repetitiva (ejemplos, matices, contexto adicional), en el mismo idioma y tono del texto. Formato HTML simple: uno o varios <p>, y un <h2> o <h3> si tiene sentido un nuevo apartado. No repitas literalmente frases ya presentes en el contenido. Devuelve SOLO el HTML nuevo, sin introducción ni comentarios.\n\nTítulo: {$title}\nContenido actual: {$text}";
			$max    = 700;
		}

		// La URL puede ser la versión traducida (TranslatePress) mientras que
		// $title/$text siempre vienen del post original en el idioma por
		// defecto del sitio. Si detectamos que la URL pertenece a otro
		// idioma, se lo indicamos explícitamente para que no escriba en el
		// idioma del contenido de referencia sino en el de la página real.
		$lang_name = $url ? self::url_language_name( $url ) : '';
		if ( $lang_name ) {
			$prompt .= "\n\nIMPORTANTE: el contenido de referencia de arriba está en otro idioma, pero esta página en concreto se visita en {$lang_name}. Escribe tu respuesta en {$lang_name}, no en el idioma del contenido de referencia.";
		}

		$ai = ScanGEO_AI::generate( $prompt, $max );
		if ( is_wp_error( $ai ) ) {
			return $ai;
		}
		return wp_kses_post( trim( $ai ) );
	}

	private static function apply_block_insert( $edited, $type ) {
		$ok           = 0;
		$undo         = array();
		$marker_start = '<!-- scangeo:' . $type . ' -->';
		$marker_end   = '<!-- /scangeo:' . $type . ' -->';

		foreach ( (array) $edited as $url => $html ) {
			if ( self::is_translated_url( $url ) ) {
				continue;
			}
			$html = wp_kses_post( (string) $html );
			if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
				continue;
			}
			$post_id = self::url_to_post( $url );
			if ( ! $post_id ) {
				continue;
			}
			$post        = get_post( $post_id );
			$original    = $post->post_content;
			$block       = "\n" . $marker_start . "\n" . $html . "\n" . $marker_end . "\n";
			$new_content = ( 'answer' === $type ) ? ( $block . $original ) : ( $original . $block );

			$undo[ $post_id ] = array( 'content' => $original );
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ) );
			$ok++;
		}
		if ( 0 === $ok ) {
			return array( 'status' => 'failed', 'message' => 'No se pudo insertar ninguna propuesta.' );
		}
		$labels = array(
			'faq'    => 'bloque(s) de preguntas frecuentes insertado(s) al final del contenido',
			'answer' => 'párrafo(s) de respuesta directa insertado(s) al principio del contenido',
			'links'  => 'sugerencia(s) de enlaces internos insertada(s) al final del contenido',
			'expand' => 'sección(es) de contenido ampliado insertada(s) al final del contenido',
		);
		return array(
			'status'  => 'fixed',
			'message' => $ok . ' ' . $labels[ $type ] . '.',
			'undo'    => array( 'type' => 'content', 'items' => $undo ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixers de imágenes (aplicación directa, con deshacer)
	 * ------------------------------------------------------------------- */

	private static function fix_images_alt( $issue ) {
		if ( empty( $issue['pages'] ) ) {
			return array( 'status' => 'failed', 'message' => 'El informe no incluye las URLs afectadas.' );
		}
		$ok   = 0;
		$ko   = array();
		$undo = array();

		foreach ( $issue['pages'] as $url ) {
			if ( self::is_translated_url( $url ) ) {
				$ko[] = $url . ' (URL traducida por TranslatePress: se omite para no mezclar idiomas en el mismo post)';
				continue;
			}
			$post_id = self::url_to_post( $url );
			if ( ! $post_id ) {
				$ko[] = $url . ' (no se encontró el post)';
				continue;
			}
			$post     = get_post( $post_id );
			$original = $post->post_content;
			$content  = $original;
			$changed  = false;

			if ( preg_match_all( '/<img\b[^>]*>/i', $content, $imgs ) ) {
				foreach ( $imgs[0] as $tag ) {
					if ( preg_match( '/\balt\s*=/i', $tag ) ) {
						continue;
					}
					if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcm ) ) {
						continue;
					}
					$src = $srcm[1];
					$alt = self::alt_for_image( $src, get_the_title( $post_id ), $url );
					if ( '' === $alt ) {
						continue;
					}
					$new_tag = preg_replace( '/<img\b/i', '<img alt="' . esc_attr( $alt ) . '"', $tag, 1 );
					$content = str_replace( $tag, $new_tag, $content );
					$att_id  = attachment_url_to_postid( $src );
					if ( $att_id ) {
						update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
					}
					$changed = true;
					$ok++;
				}
			}
			if ( $changed ) {
				$undo[ $post_id ] = array( 'content' => $original );
				wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
			}
		}
		if ( 0 === $ok && empty( $ko ) ) {
			return array( 'status' => 'failed', 'message' => 'No se encontraron imágenes sin alt en el contenido de esos posts (pueden estar en la plantilla del tema, no en el editor).' );
		}
		return self::batch_result( $ok, $ko, 'atributos alt añadidos', 'Guardados en el contenido y en la biblioteca de medios.', $undo );
	}

	private static function alt_for_image( $src, $post_title, $url = '' ) {
		$filename = pathinfo( (string) wp_parse_url( $src, PHP_URL_PATH ), PATHINFO_FILENAME );
		if ( ScanGEO_AI::is_configured() ) {
			$prompt = "Escribe un texto alternativo (alt) breve y descriptivo (máx. 100 caracteres) para una imagen. Devuelve SOLO el alt, sin comillas.\nNombre de archivo: " . $filename . "\nContexto de la página: " . $post_title;
			$lang_name = $url ? self::url_language_name( $url ) : '';
			if ( $lang_name ) {
				$prompt .= "\nIMPORTANTE: escribe el alt en {$lang_name} (aunque el contexto esté en otro idioma).";
			}
			$ai     = ScanGEO_AI::generate( $prompt, 60 );
			if ( ! is_wp_error( $ai ) ) {
				return self::smart_trim( $ai, 120 );
			}
		}
		$alt = preg_replace( '/[-_]+/', ' ', $filename );
		$alt = preg_replace( '/\b(\d{2,4}x\d{2,4}|scaled|copia|copy|final|img|dsc)\b/iu', '', $alt );
		$alt = trim( preg_replace( '/\s+/', ' ', $alt ) );
		if ( mb_strlen( $alt ) < 4 || preg_match( '/^\d+$/', $alt ) ) {
			return '';
		}
		return ucfirst( $alt );
	}

	private static function batch_result( $ok, $ko, $verb, $note = '', $undo = array() ) {
		if ( $ok > 0 && empty( $ko ) ) {
			$result = array( 'status' => 'fixed', 'message' => $ok . ' ' . $verb . '. ' . $note );
		} elseif ( $ok > 0 ) {
			$result = array( 'status' => 'fixed', 'message' => $ok . ' ' . $verb . '; pendientes: ' . implode( '; ', array_slice( $ko, 0, 5 ) ) . '. ' . $note );
		} else {
			return array( 'status' => 'failed', 'message' => 'No se pudo arreglar ninguna: ' . implode( '; ', array_slice( $ko, 0, 5 ) ) );
		}
		if ( ! empty( $undo ) ) {
			$result['undo'] = array( 'type' => 'content', 'items' => $undo );
		}
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Fixers de plantilla / sitio (activan salidas del frontend, con deshacer)
	 * ------------------------------------------------------------------- */

	private static function fix_canonical( $issue ) {
		if ( self::seo_plugin() ) {
			return array( 'status' => 'manual', 'message' => 'Tu plugin SEO (' . self::seo_plugin() . ') ya gestiona el canonical. Revisa su configuración por página: puede haber canonicals manuales apuntando a otra URL.' );
		}
		$prev = self::flag_value( 'canonical' );
		self::set_flag( 'canonical' );
		return array(
			'status'  => 'fixed',
			'message' => 'Canonical autorreferenciado activado en todas las páginas (WordPress solo lo imprime por defecto en contenido singular).',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'canonical' => $prev ) ),
		);
	}

	private static function fix_viewport( $issue ) {
		$prev = self::flag_value( 'viewport' );
		self::set_flag( 'viewport' );
		return array(
			'status'  => 'fixed',
			'message' => 'Meta viewport inyectada en <head>. Si tu tema ya la imprimía en alguna plantilla, la duplicidad es inocua; lo ideal es corregir el tema.',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'viewport' => $prev ) ),
		);
	}

	private static function fix_lang( $issue ) {
		$prev = self::flag_value( 'lang' );
		self::set_flag( 'lang' );
		return array(
			'status'  => 'fixed',
			'message' => 'Atributo lang="' . esc_html( str_replace( '_', '-', get_locale() ) ) . '" garantizado en <html>.',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'lang' => $prev ) ),
		);
	}

	private static function fix_open_graph( $issue ) {
		$seo = self::seo_plugin();
		if ( 'yoast' === $seo || 'rankmath' === $seo ) {
			return array( 'status' => 'manual', 'message' => ucfirst( $seo ) . ' ya genera Open Graph, pero el informe lo marca como incompleto: revisa que la opción de OG esté activada y que las páginas tengan imagen destacada.' );
		}
		$prev = self::flag_value( 'og' );
		self::set_flag( 'og' );
		return array(
			'status'  => 'fixed',
			'message' => 'Etiquetas Open Graph (og:title, og:description, og:image desde la imagen destacada, og:type, og:url, og:site_name) activadas.',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'og' => $prev ) ),
		);
	}

	private static function fix_schema_org( $issue ) {
		$seo = self::seo_plugin();
		if ( 'yoast' === $seo || 'rankmath' === $seo ) {
			return array( 'status' => 'manual', 'message' => ucfirst( $seo ) . ' ya emite schema en @graph. Si scanGEO lo marcó como ausente, probablemente el escáner no parsea @graph (bug conocido) o falta configurar la organización en el plugin SEO: revisa Apariencia en buscadores → Organización.' );
		}
		$prev   = self::flag_value( 'schema_org' );
		self::set_flag( 'schema_org' );
		$opts   = get_option( 'scangeo_settings', array() );
		$social = ! empty( $opts['social_profiles'] ) ? ' Con sameAs de tus perfiles sociales.' : ' Añade tus perfiles sociales en Ajustes para incluir sameAs.';
		return array(
			'status'  => 'fixed',
			'message' => 'JSON-LD Organization + WebSite activado en la portada.' . $social,
			'undo'    => array( 'type' => 'flag', 'items' => array( 'schema_org' => $prev ) ),
		);
	}

	private static function fix_schema_article( $issue ) {
		$seo = self::seo_plugin();
		if ( 'yoast' === $seo || 'rankmath' === $seo ) {
			return array( 'status' => 'manual', 'message' => ucfirst( $seo ) . ' ya emite schema Article con autor y fechas en las entradas. Verifica que los posts tengan autor con nombre público completo y perfil con biografía.' );
		}
		$prev = self::flag_value( 'schema_article' );
		self::set_flag( 'schema_article' );
		return array(
			'status'  => 'fixed',
			'message' => 'JSON-LD Article (autor Person, datePublished y dateModified) activado en las entradas del blog. Nota: solo aplica a entradas; páginas corporativas no necesitan autor/fecha.',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'schema_article' => $prev ) ),
		);
	}

	private static function fix_robots( $issue ) {
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			return array( 'status' => 'failed', 'message' => 'Existe un robots.txt físico en el servidor: WordPress no puede modificarlo. Edítalo a mano (o bórralo para que WordPress genere uno virtual y el plugin pueda gestionarlo).' );
		}
		$prev = self::flag_value( 'robots_ai' );
		self::set_flag( 'robots_ai' );
		$blocked = array();
		if ( 'geo.ai_crawlers_access' === $issue['id'] && ! empty( $issue['data']['blocked'] ) ) {
			$blocked = (array) $issue['data']['blocked'];
		}
		$msg = 'robots.txt virtual mejorado: acceso explícito permitido a crawlers de IA (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended...) y directiva Sitemap añadida.';
		if ( $blocked ) {
			$msg .= ' Bots que estaban bloqueados: ' . implode( ', ', array_map( 'sanitize_text_field', $blocked ) ) . '.';
		}
		$msg .= ' Ojo: si usas Cloudflare u otro WAF, revisa que no bloquee estos bots a nivel de red.';
		return array(
			'status'  => 'fixed',
			'message' => $msg,
			'undo'    => array( 'type' => 'flag', 'items' => array( 'robots_ai' => $prev ) ),
		);
	}

	private static function fix_sitemap( $issue ) {
		if ( 'yoast' === self::seo_plugin() ) {
			$prev = self::flag_value( 'robots_ai' );
			self::set_flag( 'robots_ai' );
			return array(
				'status'  => 'fixed',
				'message' => 'Yoast ya genera el sitemap en ' . home_url( '/sitemap_index.xml' ) . '. Se ha añadido la directiva Sitemap al robots.txt virtual. Nota para scanGEO: el escáner debe buscar también /sitemap_index.xml.',
				'undo'    => array( 'type' => 'flag', 'items' => array( 'robots_ai' => $prev ) ),
			);
		}
		$prev_sitemap = self::flag_value( 'sitemap_on' );
		$prev_robots  = self::flag_value( 'robots_ai' );
		self::set_flag( 'sitemap_on' );
		self::set_flag( 'robots_ai' );
		return array(
			'status'  => 'fixed',
			'message' => 'Sitemap nativo de WordPress activado: ' . home_url( '/wp-sitemap.xml' ) . ' y declarado en robots.txt.',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'sitemap_on' => $prev_sitemap, 'robots_ai' => $prev_robots ) ),
		);
	}

	private static function fix_llms_txt( $issue ) {
		$prev = self::flag_value( 'llms' );
		self::set_flag( 'llms' );
		return array(
			'status'  => 'fixed',
			'message' => 'Archivo /llms.txt generado dinámicamente con nombre del sitio, descripción y páginas principales. (Estándar propuesto: valor informativo para crawlers de IA.)',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'llms' => $prev ) ),
		);
	}

	private static function fix_social_signals( $issue ) {
		$opts     = get_option( 'scangeo_settings', array() );
		$profiles = ! empty( $opts['social_profiles'] ) ? array_filter( array_map( 'trim', explode( "\n", $opts['social_profiles'] ) ) ) : array();
		if ( empty( $profiles ) ) {
			return array( 'status' => 'failed', 'message' => 'Añade las URLs de tus perfiles sociales en scanGEO Fixer → Ajustes y vuelve a pulsar Reparar: se incluirán como sameAs en el schema Organization y podrás enlazarlas en el footer.' );
		}
		if ( self::seo_plugin() ) {
			return array( 'status' => 'manual', 'message' => 'Configura tus perfiles sociales en tu plugin SEO (sección Organización/Social) para que se emitan como sameAs. Perfiles detectados en Ajustes: ' . count( $profiles ) . '.' );
		}
		$prev = self::flag_value( 'schema_org' );
		self::set_flag( 'schema_org' );
		return array(
			'status'  => 'fixed',
			'message' => count( $profiles ) . ' perfiles sociales añadidos como sameAs en el schema Organization de la portada. Recomendación adicional: enlázalos también en el footer.',
			'undo'    => array( 'type' => 'flag', 'items' => array( 'schema_org' => $prev ) ),
		);
	}
}
