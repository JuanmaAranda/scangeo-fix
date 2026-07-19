<?php
/**
 * Parser del informe .md de scanGEO.
 * Modo primario: bloque JSON embebido (contrato v1).
 * Modo tolerante: detección de IDs de regla en el markdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ScanGEO_Parser {

	const KNOWN_RULES = array(
		'tech.core_web_vitals', 'tech.http_status', 'tech.robots_txt', 'tech.sitemap',
		'tech.mobile_viewport', 'tech.canonical', 'tech.pagespeed_mobile',
		'content.h1_unique', 'content.heading_hierarchy', 'content.title_length',
		'content.meta_description', 'content.internal_links', 'content.images_alt',
		'content.body_length', 'content.eeat_author', 'content.lang',
		'geo.jsonld', 'geo.open_graph', 'geo.faq_or_qa', 'geo.short_paragraphs',
		'geo.entity_author', 'geo.entity_organization', 'geo.entity_person_author',
		'geo.date_modified', 'geo.direct_answer', 'geo.ai_crawlers_access',
		'geo.content_in_raw_html', 'geo.llms_txt', 'geo.semantic_structure',
		'offpage.https_domain', 'offpage.social_signals', 'offpage.brand_mention',
		'offpage.backlinks_pro',
	);

	/**
	 * Parsea el contenido del .md y devuelve el informe normalizado o WP_Error.
	 */
	public static function parse( $markdown ) {
		if ( ! is_string( $markdown ) || '' === trim( $markdown ) ) {
			return new WP_Error( 'scangeo_empty', 'El archivo está vacío.' );
		}

		$report = self::parse_json_block( $markdown );
		if ( ! is_wp_error( $report ) ) {
			$report['parser']       = 'contract';
			$report['issues']       = self::with_uids( $report['issues'] );
			$report['issues_count'] = count( $report['issues'] );
			return $report;
		}

		$fallback = self::parse_tolerant( $markdown );
		if ( empty( $fallback['issues'] ) ) {
			return new WP_Error(
				'scangeo_unparseable',
				'No se encontró el bloque JSON de scanGEO ni IDs de regla reconocibles. Asegúrate de subir el informe .md exportado por scanGEO.'
			);
		}
		$fallback['parser']       = 'tolerant';
		$fallback['issues']       = self::with_uids( $fallback['issues'] );
		$fallback['issues_count'] = count( $fallback['issues'] );
		return $fallback;
	}

	/** Identificador único por fila: índice + regla (permite reglas repetidas en el informe). */
	private static function with_uids( $issues ) {
		$out = array();
		foreach ( array_values( (array) $issues ) as $i => $issue ) {
			if ( is_array( $issue ) ) {
				$issue['uid'] = $i . '-' . $issue['id'];
				$out[]        = $issue;
			}
		}
		return $out;
	}

	/** Busca el bloque ```json con "scangeo": 1. */
	private static function parse_json_block( $markdown ) {
		if ( ! preg_match_all( '/```json\s*(\{[\s\S]*?\})\s*```/i', $markdown, $m ) ) {
			return new WP_Error( 'scangeo_no_block', 'Sin bloque JSON.' );
		}
		foreach ( $m[1] as $candidate ) {
			$data = json_decode( $candidate, true );
			if ( ! is_array( $data ) || empty( $data['scangeo'] ) || ! isset( $data['issues'] ) ) {
				continue;
			}
			$issues = array();
			foreach ( (array) $data['issues'] as $issue ) {
				$norm = self::normalize_issue( $issue );
				if ( $norm ) {
					$issues[] = $norm;
				}
			}
			return array(
				'site'      => isset( $data['site'] ) ? esc_url_raw( $data['site'] ) : '',
				'generated' => isset( $data['generated'] ) ? sanitize_text_field( $data['generated'] ) : '',
				'mode'      => isset( $data['mode'] ) ? sanitize_text_field( $data['mode'] ) : '',
				'scores'    => isset( $data['scores'] ) && is_array( $data['scores'] ) ? array_map( 'floatval', $data['scores'] ) : array(),
				'issues'    => $issues,
			);
		}
		return new WP_Error( 'scangeo_bad_block', 'Bloque JSON no válido.' );
	}

	/** Modo degradado: busca IDs de regla conocidos y URLs cercanas. */
	private static function parse_tolerant( $markdown ) {
		$issues = array();
		$lines  = preg_split( '/\r\n|\r|\n/', $markdown );
		$count  = count( $lines );

		foreach ( self::KNOWN_RULES as $rule_id ) {
			$found_at = -1;
			foreach ( $lines as $i => $line ) {
				if ( false !== strpos( $line, $rule_id ) ) {
					$found_at = $i;
					break;
				}
			}
			if ( $found_at < 0 ) {
				continue;
			}
			// Contexto: 15 líneas siguientes para estado y URLs.
			$context = implode( "\n", array_slice( $lines, $found_at, min( 15, $count - $found_at ) ) );
			$status  = 'warning';
			if ( preg_match( '/\b(fail|fallo|error|cr[ií]tico)\b/iu', $context ) ) {
				$status = 'fail';
			} elseif ( preg_match( '/\b(pass|correcto|ok)\b/iu', $context ) && ! preg_match( '/\bwarning|aviso\b/iu', $context ) ) {
				continue; // La regla pasa: no es un issue.
			}
			$pages = array();
			if ( preg_match_all( '/https?:\/\/[^\s\)\]"\'<>]+/i', $context, $pm ) ) {
				$pages = array_slice( array_values( array_unique( $pm[0] ) ), 0, 100 );
			}
			$issues[] = self::normalize_issue( array(
				'id'       => $rule_id,
				'category' => strtok( $rule_id, '.' ),
				'status'   => $status,
				'severity' => 'medium',
				'scope'    => 'page',
				'label'    => $rule_id,
				'detail'   => 'Detectado en modo tolerante (sin bloque JSON del contrato).',
				'fixHint'  => '',
				'pages'    => $pages,
				'data'     => array(),
			) );
		}

		$site = '';
		if ( preg_match( '/https?:\/\/[^\s\)\]"\'<>]+/i', $markdown, $sm ) ) {
			$site = esc_url_raw( $sm[0] );
		}

		return array(
			'site'      => $site,
			'generated' => '',
			'mode'      => '',
			'scores'    => array(),
			'issues'    => array_values( array_filter( $issues ) ),
		);
	}

	/** Sanea y valida un issue. Devuelve array o null. */
	private static function normalize_issue( $issue ) {
		if ( ! is_array( $issue ) || empty( $issue['id'] ) ) {
			return null;
		}
		$id = sanitize_text_field( $issue['id'] );
		if ( ! preg_match( '/^(tech|content|geo|offpage)\.[a-z0-9_]+$/', $id ) ) {
			return null;
		}
		$status = isset( $issue['status'] ) ? strtolower( sanitize_text_field( $issue['status'] ) ) : 'warning';
		if ( ! in_array( $status, array( 'fail', 'warning' ), true ) ) {
			return null; // pass / unknown / not_applicable no se listan.
		}
		$severity = isset( $issue['severity'] ) ? strtolower( sanitize_text_field( $issue['severity'] ) ) : 'medium';
		if ( ! in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			$severity = 'medium';
		}
		$scope = isset( $issue['scope'] ) ? strtolower( sanitize_text_field( $issue['scope'] ) ) : 'page';
		if ( ! in_array( $scope, array( 'site', 'page', 'template', 'content_type' ), true ) ) {
			$scope = 'page';
		}
		$pages = array();
		if ( ! empty( $issue['pages'] ) && is_array( $issue['pages'] ) ) {
			foreach ( array_slice( $issue['pages'], 0, 100 ) as $p ) {
				$p = esc_url_raw( (string) $p );
				if ( $p ) {
					$pages[] = $p;
				}
			}
		}
		return array(
			'id'       => $id,
			'category' => strtok( $id, '.' ),
			'status'   => $status,
			'severity' => $severity,
			'scope'    => $scope,
			'label'    => isset( $issue['label'] ) ? sanitize_text_field( $issue['label'] ) : $id,
			'detail'   => isset( $issue['detail'] ) ? wp_kses_post( (string) $issue['detail'] ) : '',
			'fixHint'  => isset( $issue['fixHint'] ) ? wp_kses_post( (string) $issue['fixHint'] ) : '',
			'pages'    => $pages,
			'data'     => isset( $issue['data'] ) && is_array( $issue['data'] ) ? $issue['data'] : array(),
		);
	}
}
