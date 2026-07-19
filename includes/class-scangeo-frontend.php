<?php
/**
 * Salidas en el frontend activadas por los fixers (opción scangeo_flags):
 * metas, canonical, Open Graph, JSON-LD, robots.txt virtual y /llms.txt.
 * Solo se imprime lo que un fixer activó, y nunca si un plugin SEO ya lo cubre.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScanGEO_Frontend {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head_output' ), 4 );
		add_action( 'wp_head', array( __CLASS__, 'viewport_output' ), 0 );
		add_filter( 'language_attributes', array( __CLASS__, 'ensure_lang' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 2 );
		add_filter( 'wp_sitemaps_enabled', array( __CLASS__, 'sitemaps_enabled' ) );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'seo_title' ), 20 );
		add_action( 'init', array( __CLASS__, 'llms_rewrite' ) );
		add_action( 'template_redirect', array( __CLASS__, 'llms_serve' ) );
	}

	private static function flag( $name ) {
		$flags = get_option( 'scangeo_flags', array() );
		return ! empty( $flags[ $name ] );
	}

	private static function has_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'SEOPRESS_VERSION' );
	}

	/* ------------------------------ <head> ------------------------------ */

	public static function viewport_output() {
		if ( self::flag( 'viewport' ) ) {
			echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		}
	}

	public static function head_output() {
		$post_id = is_singular() ? get_queried_object_id() : 0;

		// Meta description propia (solo sin plugin SEO).
		if ( ! self::has_seo_plugin() && $post_id ) {
			$desc = get_post_meta( $post_id, '_scangeo_meta_description', true );
			if ( $desc ) {
				echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
			}
		}

		// Canonical en contextos donde el core no lo imprime.
		if ( self::flag( 'canonical' ) && ! self::has_seo_plugin() && ! is_singular() ) {
			$canonical = self::current_url();
			if ( $canonical ) {
				echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
			}
		}

		// Open Graph.
		if ( self::flag( 'og' ) && ! self::has_seo_plugin() ) {
			self::og_output( $post_id );
		}

		// JSON-LD Organization + WebSite en portada.
		if ( self::flag( 'schema_org' ) && ! self::has_seo_plugin() && ( is_front_page() || is_home() ) ) {
			self::schema_org_output();
		}

		// JSON-LD Article en entradas.
		if ( self::flag( 'schema_article' ) && ! self::has_seo_plugin() && is_singular( 'post' ) && $post_id ) {
			self::schema_article_output( $post_id );
		}
	}

	public static function seo_title( $title ) {
		if ( self::has_seo_plugin() || ! is_singular() ) {
			return $title;
		}
		$custom = get_post_meta( get_queried_object_id(), '_scangeo_seo_title', true );
		return $custom ? $custom : $title;
	}

	public static function ensure_lang( $output ) {
		if ( self::flag( 'lang' ) && false === stripos( $output, 'lang=' ) ) {
			$output .= ' lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '"';
		}
		return $output;
	}

	private static function current_url() {
		global $wp;
		if ( ! isset( $wp->request ) ) {
			return home_url( '/' );
		}
		return home_url( add_query_arg( array(), $wp->request ) );
	}

	private static function og_output( $post_id ) {
		$title = wp_get_document_title();
		$url   = $post_id ? get_permalink( $post_id ) : self::current_url();
		$desc  = '';
		$image = '';
		$type  = is_singular( 'post' ) ? 'article' : 'website';

		if ( $post_id ) {
			$desc = get_post_meta( $post_id, '_scangeo_meta_description', true );
			if ( ! $desc ) {
				$desc = get_the_excerpt( $post_id );
			}
			if ( has_post_thumbnail( $post_id ) ) {
				$image = get_the_post_thumbnail_url( $post_id, 'large' );
			}
		}
		if ( ! $desc ) {
			$desc = get_bloginfo( 'description' );
		}
		if ( ! $image ) {
			$logo_id = get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$image = wp_get_attachment_image_url( $logo_id, 'large' );
			}
		}

		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
		if ( $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( wp_strip_all_tags( $desc ) ) . '">' . "\n";
		}
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
			echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
		}
	}

	private static function schema_org_output() {
		$opts     = get_option( 'scangeo_settings', array() );
		$profiles = ! empty( $opts['social_profiles'] ) ? array_values( array_filter( array_map( 'trim', explode( "\n", $opts['social_profiles'] ) ) ) ) : array();
		$logo_id  = get_theme_mod( 'custom_logo' );
		$logo     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		$org = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
		if ( $logo ) {
			$org['logo'] = $logo;
		}
		if ( $profiles ) {
			$org['sameAs'] = array_map( 'esc_url_raw', $profiles );
		}

		$website = array(
			'@type'     => 'WebSite',
			'@id'       => home_url( '/#website' ),
			'name'      => get_bloginfo( 'name' ),
			'url'       => home_url( '/' ),
			'publisher' => array( '@id' => home_url( '/#organization' ) ),
		);
		if ( get_bloginfo( 'description' ) ) {
			$website['description'] = get_bloginfo( 'description' );
		}

		self::print_jsonld( array(
			'@context' => 'https://schema.org',
			'@graph'   => array( $org, $website ),
		) );
	}

	private static function schema_article_output( $post_id ) {
		$post   = get_post( $post_id );
		$author = get_the_author_meta( 'display_name', $post->post_author );

		$article = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'BlogPosting',
			'headline'      => get_the_title( $post_id ),
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_the_date( 'c', $post_id ),
			'dateModified'  => get_the_modified_date( 'c', $post_id ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => $author,
				'url'   => get_author_posts_url( $post->post_author ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
			'mainEntityOfPage' => get_permalink( $post_id ),
		);
		if ( has_post_thumbnail( $post_id ) ) {
			$article['image'] = get_the_post_thumbnail_url( $post_id, 'large' );
		}
		self::print_jsonld( $article );
	}

	private static function print_jsonld( $data ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/* ------------------------------ robots ------------------------------ */

	public static function robots_txt( $output, $public ) {
		if ( ! self::flag( 'robots_ai' ) || ! $public ) {
			return $output;
		}
		$ai_bots = array(
			'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
			'ClaudeBot', 'Claude-User', 'Claude-SearchBot',
			'PerplexityBot', 'Perplexity-User',
			'Google-Extended', 'CCBot', 'Amazonbot', 'Applebot-Extended',
			'meta-externalagent', 'Bytespider', 'cohere-ai',
		);
		$extra = "\n# scanGEO Fixer: acceso explícito para crawlers de IA (GEO)\n";
		foreach ( $ai_bots as $bot ) {
			$extra .= "User-agent: {$bot}\nAllow: /\n\n";
		}
		if ( false === stripos( $output, 'sitemap:' ) ) {
			$sitemap = defined( 'WPSEO_VERSION' ) ? home_url( '/sitemap_index.xml' ) : home_url( '/wp-sitemap.xml' );
			$extra  .= 'Sitemap: ' . $sitemap . "\n";
		}
		return $output . $extra;
	}

	public static function sitemaps_enabled( $enabled ) {
		if ( self::flag( 'sitemap_on' ) ) {
			return true;
		}
		return $enabled;
	}

	/* ------------------------------ llms.txt ----------------------------- */

	public static function llms_rewrite() {
		if ( self::flag( 'llms' ) ) {
			add_rewrite_rule( '^llms\.txt$', 'index.php?scangeo_llms=1', 'top' );
			add_filter( 'query_vars', function ( $vars ) {
				$vars[] = 'scangeo_llms';
				return $vars;
			} );
		}
	}

	public static function llms_serve() {
		if ( ! self::flag( 'llms' ) ) {
			return;
		}
		$is_llms = get_query_var( 'scangeo_llms' );
		// Fallback por si las rewrite rules no se han refrescado.
		if ( ! $is_llms && isset( $_SERVER['REQUEST_URI'] ) && '/llms.txt' === strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) ) {
			$is_llms = true;
		}
		if ( ! $is_llms ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		echo '# ' . esc_html( get_bloginfo( 'name' ) ) . "\n\n";
		if ( get_bloginfo( 'description' ) ) {
			echo '> ' . esc_html( get_bloginfo( 'description' ) ) . "\n\n";
		}
		echo "## Páginas principales\n\n";
		$pages = get_pages( array( 'sort_column' => 'menu_order', 'number' => 20 ) );
		foreach ( $pages as $page ) {
			echo '- [' . esc_html( get_the_title( $page ) ) . '](' . esc_url( get_permalink( $page ) ) . ")\n";
		}
		$posts = get_posts( array( 'numberposts' => 10 ) );
		if ( $posts ) {
			echo "\n## Últimos artículos\n\n";
			foreach ( $posts as $p ) {
				echo '- [' . esc_html( get_the_title( $p ) ) . '](' . esc_url( get_permalink( $p ) ) . ")\n";
			}
		}
		exit;
	}
}
