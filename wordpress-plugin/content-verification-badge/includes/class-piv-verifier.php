<?php
/**
 * Automated post verification engine.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Verifier {

	/**
	 * Run verification for a post and persist results.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function verify_post( $post_id, $force = false, $args = array() ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		if ( ! in_array( $post->post_type, PIV_Helpers::post_types(), true ) ) {
			return array();
		}

		if ( PIV_Helpers::is_excluded_post( $post_id ) ) {
			PIV_Helpers::clear_verification_meta( $post_id );
			return array();
		}

		if ( ! PIV_Helpers::is_in_verification_scope( $post_id ) ) {
			return array();
		}

		if ( ! $force && ! PIV_Helpers::should_run_verification( $post_id ) ) {
			$result = PIV_Helpers::get_result( $post_id );
			return is_array( $result ) ? $result : array();
		}

		$settings = PIV_Helpers::get_settings();
		$context  = isset( $args['context'] ) ? (string) $args['context'] : 'background';

		if ( ! empty( $args['reset'] ) ) {
			PIV_Helpers::reset_verification( $post_id, ! empty( $args['clear_manual'] ) );
			// Keep an explicit precision context; otherwise default reset to manual full scan.
			if ( ! in_array( $context, array( 'manual', 'precision' ), true ) ) {
				$context = 'manual';
			}
		}

		$settings = PIV_Helpers::apply_verification_context( $settings, $context );
		$settings['_cache_nonce'] = PIV_Helpers::get_cache_nonce( $post_id );
		if ( ! empty( $args['reset'] ) || in_array( $context, array( 'manual', 'precision' ), true ) ) {
			$settings['bypass_search_cache'] = true;
			$settings['bypass_fetch_cache']  = true;
		}

		$cited    = self::extract_urls( $post->post_content );
		$manual   = self::get_manual_override( $post_id );
		if ( ! empty( $manual['evidence'] ) ) {
			$cited[] = $manual['evidence'];
			$cited   = array_values( array_unique( $cited ) );
		}

		$search   = PIV_Search::discover_sources( $post, $settings );
		$official = PIV_Search::discover_official_urls( $post, $settings, $cited );
		$news     = PIV_Search::discover_news_urls( $post, $settings, $cited );
		$rss      = PIV_RSS::discover_for_post( $post, $settings );
		$origins  = array();

		foreach ( $cited as $url ) {
			$origins[ $url ] = 'cited';
		}
		if ( ! empty( $manual['evidence'] ) ) {
			$origins[ $manual['evidence'] ] = 'manual';
		}
		foreach ( $search['urls'] as $url ) {
			if ( ! isset( $origins[ $url ] ) ) {
				$origins[ $url ] = 'discovered';
			}
		}
		foreach ( $official['urls'] as $url ) {
			if ( ! isset( $origins[ $url ] ) ) {
				$origins[ $url ] = 'official';
			}
		}
		foreach ( $news['urls'] as $url ) {
			if ( ! isset( $origins[ $url ] ) ) {
				$origins[ $url ] = 'news';
			}
		}
		foreach ( $rss['urls'] as $url ) {
			if ( ! isset( $origins[ $url ] ) ) {
				$origins[ $url ] = 'rss';
			}
		}

		// Reclassify by domain: a playstation.com hit from general search is still official.
		$origins = self::reclassify_source_origins( $origins );

		$urls = array();
		foreach ( array_merge( $cited, $official['urls'], $news['urls'], $rss['urls'], $search['urls'] ) as $url ) {
			if ( ! isset( $origins[ $url ] ) || in_array( $url, $urls, true ) ) {
				continue;
			}

			if ( 'manual' === $origins[ $url ] || self::is_verification_candidate_url( $url, $origins[ $url ] ) ) {
				$urls[] = $url;
			}
		}

		$urls = self::prioritize_urls( $urls, $origins );

		$sources = self::analyze_sources( $urls, $post_id, $origins, $settings );
		$sources = self::merge_rss_source_hints( $sources, $rss, $post );

		$discovery = array(
			'search'   => $search,
			'news'     => $news,
			'official' => $official,
			'rss'      => $rss,
		);
		$layer   = self::determine_layer( $post, $sources, $settings, $discovery );
		$reasons = self::build_reasons( $layer, $sources, $post, $settings, $discovery );

		if ( ! empty( $manual['layer'] ) && 'yes' === ( $settings['allow_manual_override'] ?? 'yes' ) ) {
			$manual_layer = $manual['layer'];

			// Manual "מאומת" requires an official publisher/studio evidence URL that was checked.
			if ( PIV_Layers::VERIFIED === $manual_layer ) {
				$manual_official = ! empty( $manual['evidence'] )
					&& PIV_Sources::is_official_domain( PIV_Sources::domain_from_url( $manual['evidence'] ) );
				$manual_checked  = $manual_official && self::sources_include_url( $sources, $manual['evidence'] );
				if ( ! $manual_checked && ! self::has_verified_official_source( $sources, max( 0.12, (float) ( $settings['match_verified_official'] ?? 0.25 ) ) ) ) {
					$reasons = array_merge(
						array( __( 'אימות ידני ל"מידע מאומת" נדחה — חובה קישור רשמי של המפתחת/הסטודיו/החברה שנבדק בפועל', 'content-verification-badge' ) ),
						$reasons
					);
				} else {
					$layer   = $manual_layer;
					$reasons = array_merge(
						array( __( 'שכבה נקבעה ידנית על ידי העורך', 'content-verification-badge' ) ),
						$reasons
					);
					if ( ! empty( $manual['evidence'] ) ) {
						array_unshift(
							$reasons,
							sprintf(
								/* translators: %s: evidence URL */
								__( 'ראיית אימות ידנית: %s', 'content-verification-badge' ),
								$manual['evidence']
							)
						);
					}
					if ( ! empty( $manual['note'] ) ) {
						$reasons[] = $manual['note'];
					}
				}
			} else {
				$layer   = $manual_layer;
				$reasons = array_merge(
					array( __( 'שכבה נקבעה ידנית על ידי העורך', 'content-verification-badge' ) ),
					$reasons
				);
				if ( ! empty( $manual['note'] ) ) {
					$reasons[] = $manual['note'];
				}
			}
		}

		// Hard stop: layers that claim sources must have real evidence URLs.
		$official_threshold = max( 0.24, (float) ( $settings['match_verified_official'] ?? 0.25 ) );
		if ( PIV_Layers::VERIFIED === $layer && ! self::has_verified_official_source( $sources, $official_threshold ) ) {
			$layer = ! empty( self::get_trusted_evidence_sources( $sources ) )
				? PIV_Layers::TRUSTED_SOURCE
				: PIV_Layers::INITIAL_REPORT;
			$reasons = self::build_reasons( $layer, $sources, $post, $settings, $discovery );
			array_unshift(
				$reasons,
				__( 'לא ניתן לסמן "מידע מאומת" בלי מקור רשמי של המפתחת/הסטודיו/החברה שנפתח ונבדק', 'content-verification-badge' )
			);
		} elseif ( PIV_Layers::TRUSTED_SOURCE === $layer && empty( self::get_trusted_evidence_sources( $sources ) ) ) {
			$layer   = PIV_Layers::INITIAL_REPORT;
			$reasons = self::build_reasons( $layer, $sources, $post, $settings, $discovery );
			array_unshift(
				$reasons,
				__( 'לא נמצא מקור מתאים שנבדק ואושר', 'content-verification-badge' )
			);
		}

		$evidence = array();
		foreach ( self::get_trusted_evidence_sources( $sources ) as $src ) {
			$evidence[] = array(
				'url'           => (string) ( $src['url'] ?? '' ),
				'domain'        => (string) ( $src['domain'] ?? '' ),
				'title'         => (string) ( $src['title'] ?? '' ),
				'body_excerpt'  => (string) ( $src['body_excerpt'] ?? '' ),
				'match_score'   => (float) ( $src['match_score'] ?? 0 ),
				'title_score'   => (float) ( $src['title_score'] ?? 0 ),
				'body_score'    => (float) ( $src['body_score'] ?? 0 ),
				'ai_same_story' => ! empty( $src['ai_same_story'] ),
				'ai_confidence' => (float) ( $src['ai_confidence'] ?? 0 ),
				'ai_reason'     => (string) ( $src['ai_reason'] ?? '' ),
				'origin'        => (string) ( $src['origin'] ?? '' ),
				'is_official'   => ! empty( $src['is_official'] ),
				'is_news'       => ! empty( $src['is_news'] ),
			);
		}

		// Make sure reasons always name the evidence sources when we have them.
		if ( ! empty( $evidence ) && in_array( $layer, array( PIV_Layers::VERIFIED, PIV_Layers::TRUSTED_SOURCE ), true ) ) {
			$named = array();
			foreach ( array_slice( $evidence, 0, 4 ) as $ev ) {
				if ( ! empty( $ev['domain'] ) ) {
					$named[] = $ev['domain'];
				}
			}
			if ( ! empty( $named ) ) {
				array_unshift(
					$reasons,
					sprintf(
						/* translators: %s: comma-separated source domains */
						__( 'מקורות: %s', 'content-verification-badge' ),
						implode( ', ', array_unique( $named ) )
					)
				);
			}
		}

		$matched_urls = array();
		foreach ( $evidence as $ev ) {
			if ( ! empty( $ev['url'] ) ) {
				$matched_urls[] = (string) $ev['url'];
			}
		}
		foreach ( (array) ( $sources['external'] ?? array() ) as $src ) {
			if ( ! empty( $src['url'] ) && ! empty( $src['accepted'] ) ) {
				$matched_urls[] = (string) $src['url'];
			}
		}
		$matched_urls = array_values( array_unique( $matched_urls ) );

		$discovery_diag = array(
			'candidates'      => count( $urls ),
			'search_raw'      => (int) ( $search['raw_count'] ?? count( (array) ( $search['urls'] ?? array() ) ) ),
			'search_kept'     => count( (array) ( $search['urls'] ?? array() ) ),
			'official'        => count( (array) ( $official['urls'] ?? array() ) ),
			'news'            => count( (array) ( $news['urls'] ?? array() ) ),
			'rss'             => count( (array) ( $rss['urls'] ?? array() ) ),
			'provider'        => (string) ( $search['provider'] ?? '' ),
			'search_error'    => (string) ( $search['error'] ?? '' ),
			'sample_urls'     => array_slice( array_values( $urls ), 0, 8 ),
		);

		$result = array(
			'layer'            => $layer,
			'checked_at'       => time(),
			// Matched evidence URLs for the badge; discovery_diag keeps scan diagnostics.
			'urls'             => $matched_urls,
			'cited_urls'       => $cited,
			'discovery_diag'   => $discovery_diag,
			'search'           => array(
				'query'     => $search['query'] ?? '',
				'provider'  => $search['provider'] ?? '',
				'raw_count' => (int) ( $search['raw_count'] ?? 0 ),
				'error'     => (string) ( $search['error'] ?? '' ),
				'urls'      => array(),
			),
			'official_search'  => array(
				'domains' => array_slice( array_values( (array) ( $official['domains'] ?? array() ) ), 0, 40 ),
				'urls'    => array(),
				'found'   => count( (array) ( $official['urls'] ?? array() ) ),
			),
			'news_search'      => array(
				'domains' => array_slice( array_values( (array) ( $news['domains'] ?? array() ) ), 0, 40 ),
				'urls'    => array(),
				'found'   => count( (array) ( $news['urls'] ?? array() ) ),
			),
			'rss_search'       => array(
				'urls'  => array_slice( array_values( (array) ( $rss['urls'] ?? array() ) ), 0, 12 ),
				'count' => count( (array) ( $rss['urls'] ?? array() ) ),
			),
			'manual'           => $manual,
			'sources'          => $sources,
			'evidence'         => $evidence,
			'reasons'          => $reasons,
			'best_match'       => isset( $sources['best_match'] ) ? $sources['best_match'] : null,
			'has_conflicts'    => ! empty( $sources['has_conflicts'] ),
			'post_age_minutes' => self::post_age_minutes( $post ),
		);

		// Never keep verified/trusted if we somehow have no concrete evidence URLs.
		if ( in_array( $layer, array( PIV_Layers::VERIFIED, PIV_Layers::TRUSTED_SOURCE ), true ) && empty( $evidence ) ) {
			$layer                 = PIV_Layers::INITIAL_REPORT;
			$result['layer']       = $layer;
			$result['evidence']    = array();
			$result['urls']        = array();
			$reasons               = self::build_reasons( $layer, $sources, $post, $settings, $discovery );
			array_unshift(
				$reasons,
				__( 'השכבה הורדה — אין מקורות שנפתחו ונשמרו לבדיקה זו', 'content-verification-badge' )
			);
			$result['reasons'] = $reasons;
		}

		$saved = PIV_Helpers::save_verification_result( $post_id, $result );
		if ( ! $saved ) {
			// Absolute fallback: keep a tiny payload so the UI never shows a layer without sources.
			$minimal = array(
				'layer'      => PIV_Layers::INITIAL_REPORT,
				'checked_at' => $result['checked_at'],
				'urls'       => array_slice( array_values( $urls ), 0, 20 ),
				'evidence'   => array_slice( $evidence, 0, 8 ),
				'sources'    => array(
					'attempted' => array_slice( (array) ( $sources['attempted'] ?? array() ), 0, 20 ),
					'external'  => array_slice( (array) ( $sources['external'] ?? array() ), 0, 10 ),
					'counts'    => $sources['counts'] ?? array(),
				),
				'reasons'    => array(
					__( 'שמירת תוצאת הסריקה המלאה נכשלה — נשמר סיכום מצומצם. לחץ "אפס ובדוק מחדש".', 'content-verification-badge' ),
				),
			);
			$saved  = PIV_Helpers::save_verification_result( $post_id, $minimal );
			$result = array_merge( $result, $minimal );
			$layer  = $minimal['layer'];
		}

		if ( ! $saved ) {
			// Never leave a verified badge when the source payload could not be stored.
			$layer = PIV_Layers::UNDER_REVIEW;
			update_post_meta( $post_id, PIV_META_LAYER, $layer );
			update_post_meta( $post_id, PIV_META_STATUS, 'error' );
			update_post_meta( $post_id, PIV_META_CHECKED_AT, time() );
			PIV_Helpers::invalidate_verification_stats_cache();
			return array(
				'layer'   => $layer,
				'reasons' => array( __( 'שגיאה בשמירת תוצאת האימות — נסה שוב עם "אפס ובדוק מחדש"', 'content-verification-badge' ) ),
			);
		}

		update_post_meta( $post_id, PIV_META_LAYER, $layer );
		update_post_meta( $post_id, PIV_META_CHECKED_AT, $result['checked_at'] );
		update_post_meta( $post_id, PIV_META_STATUS, 'complete' );
		update_post_meta( $post_id, PIV_META_CONTENT_HASH, PIV_Helpers::get_post_content_hash( $post ) );

		do_action( 'piv_verification_complete', $post_id, $result );

		PIV_Helpers::invalidate_verification_stats_cache();

		return $result;
	}

	/**
	 * Extract unique external URLs from HTML content.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	public static function extract_urls( $content ) {
		$urls = array();

		if ( preg_match_all( '#https?://[^\s"\'<>]+#i', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$url = esc_url_raw( untrailingslashit( rtrim( $url, '.,;)]' ) ) );
				if ( ! $url || in_array( $url, $urls, true ) ) {
					continue;
				}
				if ( ! PIV_Sources::is_external_url( $url ) ) {
					continue;
				}
				$urls[] = $url;
			}
		}

		return apply_filters( 'piv_extracted_urls', $urls, $content );
	}

	/**
	 * Analyze every external source cited in the post.
	 *
	 * @param array $urls        URLs to analyze.
	 * @param int   $post_id     Post ID for similarity comparison.
	 * @param array $url_origins Map of url => cited|discovered.
	 * @return array
	 */
	public static function analyze_sources( $urls, $post_id = 0, $url_origins = array(), $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = PIV_Helpers::get_settings();
		}
		$max_fetches     = max( 1, min( 15, (int) ( $settings['max_source_fetches'] ?? 3 ) ) );
		$full_scan       = ! empty( $settings['force_full_scan'] );
		$max_official    = max( 1, min( 80, (int) ( $settings['max_official_fetches'] ?? 15 ) ) );
		$max_news        = max( 1, min( 80, (int) ( $settings['max_news_fetches'] ?? 5 ) ) );
		$stop_score      = max( 0.5, (float) ( $settings['match_verified'] ?? 0.9 ) );
		$official_stop   = max( 0.15, (float) ( $settings['match_verified_official'] ?? 0.35 ) );
		$news_stop       = max( 0.25, (float) ( $settings['match_review'] ?? 0.6 ) );
		$post_obj  = $post_id ? get_post( $post_id ) : null;
		$post_text = $post_obj ? self::get_post_comparison_text( $post_obj, 2500 ) : '';
		$external         = array();
		$attempted        = array();
		$match_scores     = array();
		$fetched          = 0;
		$discovered       = 0;
		$cited            = 0;
		$official_hits    = 0;
		$news_hits        = 0;
		$general_fetches  = 0;
		$official_fetches = 0;
		$news_fetches     = 0;
		$ai_compares      = 0;
		$scanned          = 0;
		$rejected         = 0;
		$rejected_samples = array();
		$ai_enabled       = class_exists( 'PIV_Gemini' ) && PIV_Gemini::is_enabled( $settings );
		$ai_max           = max( 0, min( 20, (int) ( $settings['gemini_max_compares'] ?? 8 ) ) );
		$ai_min_conf      = max( 0.5, min( 0.95, (float) ( $settings['gemini_min_confidence'] ?? 0.72 ) ) );
		// Gemini is a booster/veto only when it returns a valid answer — never a hard requirement on precision.
		$ai_required      = 'yes' === ( $settings['gemini_required'] ?? 'no' );

		foreach ( $urls as $url ) {
			$domain      = PIV_Sources::domain_from_url( $url );
			$origin      = isset( $url_origins[ $url ] ) ? $url_origins[ $url ] : 'cited';
			$is_official = ( 'official' === $origin || PIV_Sources::is_official_domain( $domain ) );
			$is_news     = ! $is_official && ( in_array( $origin, array( 'news', 'rss' ), true ) || PIV_Sources::is_news_domain( $domain ) );

			if ( $is_official ) {
				if ( $official_fetches >= $max_official ) {
					continue;
				}
				$official_fetches++;
			} elseif ( $is_news ) {
				if ( $news_fetches >= $max_news ) {
					continue;
				}
				$news_fetches++;
			} else {
				if ( $general_fetches >= $max_fetches ) {
					continue;
				}
				$general_fetches++;
			}

			$scanned++;

			$remote = self::fetch_source_snapshot(
				$url,
				$post_id,
				array(
					'bypass_cache' => ! empty( $settings['bypass_fetch_cache'] ) || ! empty( $settings['bypass_search_cache'] ),
					'timeout'      => max( 8, (int) ( $settings['fetch_timeout'] ?? 8 ) ),
				)
			);

			$http_ok   = ! empty( $remote['http_ok'] );
			$body_text = isset( $remote['body_text'] ) ? (string) $remote['body_text'] : '';
			$entry     = array(
				'url'          => $url,
				'domain'       => $domain,
				'origin'       => $origin,
				'is_official'  => PIV_Sources::is_official_domain( $domain ),
				'is_news'      => PIV_Sources::is_news_domain( $domain ),
				'title'        => isset( $remote['title'] ) ? $remote['title'] : '',
				'body_excerpt' => isset( $remote['body_excerpt'] ) ? (string) $remote['body_excerpt'] : mb_substr( $body_text, 0, 160, 'UTF-8' ),
				'match_score'  => isset( $remote['match_score'] ) ? (float) $remote['match_score'] : 0,
				'title_score'  => isset( $remote['title_score'] ) ? (float) $remote['title_score'] : 0,
				'body_score'   => isset( $remote['body_score'] ) ? (float) $remote['body_score'] : 0,
				// Only a successful HTTP response counts as "fetched" — never invent this from URL path score.
				'fetched'      => $http_ok,
				'http_ok'      => $http_ok,
				'error'        => isset( $remote['error'] ) ? $remote['error'] : '',
				'accepted'     => false,
			);

			$compare_blob = trim( $entry['title'] . ' ' . $body_text );
			if ( ( $is_official || $is_news ) && '' !== $post_text ) {
				$key_score                = self::key_token_similarity( $post_text, $compare_blob, $url );
				$entry['key_match_score'] = $key_score;
				$context                  = self::evaluate_source_context( $post_obj, $entry['title'], $url, $entry['match_score'], $key_score, $body_text );
				$entry['context_ok']      = ! empty( $context['ok'] );
				$entry['context_reason']  = isset( $context['reason'] ) ? $context['reason'] : '';
				$entry['context_score']   = isset( $context['score'] ) ? (float) $context['score'] : 0.0;
				$entry['effective_match'] = (float) $context['score'];
				$entry['match_score']     = $entry['effective_match'];
			} else {
				$context                  = self::evaluate_source_context( $post_obj, $entry['title'], $url, $entry['match_score'], 0.0, $body_text );
				$entry['context_ok']      = ! empty( $context['ok'] );
				$entry['context_reason']  = isset( $context['reason'] ) ? $context['reason'] : '';
				$entry['context_score']   = isset( $context['score'] ) ? (float) $context['score'] : 0.0;
				$entry['effective_match'] = (float) $context['score'];
				$entry['match_score']     = $entry['effective_match'];
			}

			// Without a real page fetch, never accept as verification evidence.
			if ( ! $http_ok ) {
				$entry['context_ok'] = false;
				if ( empty( $entry['context_reason'] ) ) {
					$entry['context_reason'] = ! empty( $entry['error'] ) ? 'fetch_failed' : 'not_fetched';
				}
			}

			// Gemini: optional semantic check. On API error, keep the text match.
			if ( $ai_enabled && $http_ok && $ai_compares < $ai_max && $post_obj instanceof WP_Post ) {
				$should_ask_ai = $ai_required
					|| ! empty( $entry['context_ok'] )
					|| (float) ( $entry['match_score'] ?? 0 ) >= 0.18
					|| in_array( $origin, array( 'official', 'news', 'rss', 'manual', 'cited' ), true );

				if ( $should_ask_ai ) {
					$ai = PIV_Gemini::compare_source(
						$post_obj,
						array(
							'url'          => $url,
							'title'        => $entry['title'],
							'body_text'    => $body_text,
							'body_excerpt' => $entry['body_excerpt'],
						),
						$settings
					);
					$ai_compares++;

					$entry['ai_error']      = (string) ( $ai['error'] ?? '' );
					$entry['ai_reason']     = (string) ( $ai['reason'] ?? '' );
					$entry['ai_confidence'] = (float) ( $ai['confidence'] ?? 0 );

					if ( ! empty( $ai['error'] ) ) {
						// Keep text-based decision unless Gemini is explicitly required.
						if ( $ai_required ) {
							$entry['context_ok']     = false;
							$entry['context_reason'] = 'ai_error';
						}
					} else {
						$entry['ai_same_story'] = ! empty( $ai['same_story'] );
						if ( ! empty( $ai['same_story'] ) && $entry['ai_confidence'] >= $ai_min_conf ) {
							// Booster: Gemini can promote a borderline text match.
							$entry['context_ok']      = true;
							$entry['context_reason']  = 'gemini_same_story';
							$entry['effective_match'] = max( (float) $entry['effective_match'], $entry['ai_confidence'] );
							$entry['match_score']     = $entry['effective_match'];
						} elseif ( empty( $ai['same_story'] ) && $ai_required ) {
							// Veto only when "Gemini required" is enabled.
							$entry['context_ok']     = false;
							$entry['context_reason'] = 'gemini_different_story';
						}
					}
				}
			} elseif ( $ai_required && $http_ok ) {
				$entry['context_ok']     = false;
				$entry['context_reason'] = 'ai_limit';
			}

			$accepted = self::is_relevant_source( $entry, $post_id, $origin );
			// Gemini boosts acceptance; vetoes only when explicitly required.
			if ( array_key_exists( 'ai_same_story', $entry ) && empty( $entry['ai_error'] ) ) {
				if ( ! empty( $entry['ai_same_story'] ) && (float) ( $entry['ai_confidence'] ?? 0 ) >= $ai_min_conf ) {
					$accepted = true;
				} elseif ( $ai_required ) {
					$accepted = false;
				}
			}
			$entry['accepted'] = $accepted;
			if ( ! $accepted && empty( $entry['context_reason'] ) ) {
				$entry['context_reason'] = 'not_relevant';
			}

			// Only matching sources are kept/counted. Keep a few rejects for admin diagnostics.
			if ( ! $accepted ) {
				$rejected++;
				if ( count( $rejected_samples ) < 5 ) {
					$rejected_samples[] = array(
						'url'            => $url,
						'domain'         => $domain,
						'title'          => (string) ( $entry['title'] ?? '' ),
						'origin'         => $origin,
						'context_reason' => (string) ( $entry['context_reason'] ?? 'not_relevant' ),
						'match_score'    => (float) ( $entry['match_score'] ?? 0 ),
						'fetched'        => ! empty( $entry['fetched'] ),
						'error'          => (string) ( $entry['error'] ?? '' ),
					);
				}
				continue;
			}

			$attempted[] = $entry;
			$external[]  = $entry;

			if ( $entry['fetched'] ) {
				$fetched++;
			}
			if ( 'discovered' === $origin ) {
				$discovered++;
			} elseif ( 'official' === $origin ) {
				$official_hits++;
			} elseif ( in_array( $origin, array( 'news', 'rss' ), true ) ) {
				$news_hits++;
			} elseif ( 'manual' === $origin ) {
				$cited++;
			} else {
				$cited++;
			}

			if ( $entry['match_score'] > 0 ) {
				$match_scores[] = $entry['match_score'];
			}

			// Background scans may stop early after a strong hit; manual/full scan checks all URLs.
			if ( ! $full_scan ) {
				$threshold = $stop_score;
				if ( $is_official || ! empty( $entry['is_official'] ) ) {
					$threshold = $official_stop;
				} elseif ( $is_news || ! empty( $entry['is_news'] ) ) {
					$threshold = $news_stop;
				}
				if ( ! empty( $match_scores ) && max( $match_scores ) >= $threshold ) {
					break;
				}
			}
		}

		$best_match = null;
		if ( ! empty( $match_scores ) ) {
			$best_score = max( $match_scores );
			foreach ( $external as $source ) {
				if ( isset( $source['match_score'] ) && (float) $source['match_score'] === (float) $best_score ) {
					$best_match = $source;
					break;
				}
			}
		}

		$matched = count( $external );

		return array(
			'external'      => $external,
			// attempted === matched only (rejected candidates are not counted as sources).
			'attempted'     => $attempted,
			'rejected_samples' => $rejected_samples,
			'counts'        => array(
				'scanned'    => $scanned,
				'rejected'   => $rejected,
				'matched'    => $matched,
				'external'   => $matched,
				'attempted'  => $matched,
				'matched'    => $matched,
				'fetched'    => $fetched,
				'discovered' => $discovered,
				'official'   => $official_hits,
				'news'       => $news_hits,
				'cited'      => $cited,
				'official_domains' => count(
					array_filter(
						$external,
						static function ( $source ) {
							return ! empty( $source['is_official'] );
						}
					)
				),
				'domains'    => count( array_unique( array_filter( wp_list_pluck( $external, 'domain' ) ) ) ),
				'total'      => $matched,
			),
			'best_match'    => $best_match,
			'max_match'     => ! empty( $match_scores ) ? max( $match_scores ) : 0,
			'has_conflicts' => self::detect_conflicts( $external ),
		);
	}

	/**
	 * Determine verification layer from analysis.
	 *
	 * "דיווח ראשוני" רק כשאין כיסוי חיצוני שפורסם.
	 * אם נמצאו כתבות חדשות/רשמיות — לפחות מקור מהימן.
	 *
	 * @param WP_Post $post      Post object.
	 * @param array   $sources   Source analysis.
	 * @param array   $settings  Plugin settings.
	 * @param array   $discovery Search/news/official discovery payloads.
	 * @return string
	 */
	public static function determine_layer( $post, $sources, $settings, $discovery = array() ) {
		// Back-compat: older callers passed only the general search array.
		if ( isset( $discovery['urls'] ) || isset( $discovery['query'] ) || isset( $discovery['provider'] ) ) {
			$discovery = array( 'search' => $discovery );
		}

		$age_minutes = self::post_age_minutes( $post );
		$max_match   = isset( $sources['max_match'] ) ? (float) $sources['max_match'] : 0;
		$counts      = isset( $sources['counts'] ) ? $sources['counts'] : array();
		$external    = (int) ( $counts['external'] ?? 0 );
		$fetched     = (int) ( $counts['fetched'] ?? 0 );
		$news_count  = (int) ( $counts['news'] ?? 0 );
		$official_count = (int) ( $counts['official'] ?? 0 );
		$official_threshold = max( 0.12, (float) ( $settings['match_verified_official'] ?? 0.25 ) );
		$official_score     = self::get_best_official_match_score( $sources );
		$news_score         = self::get_best_news_match_score( $sources );
		$news_domains       = self::count_independent_news_domains( $sources );

		unset( $discovery );

		// Only strong, fetched, topic-matched news/official sources can create trust.
		$checked_trusted = self::get_trusted_evidence_sources( $sources );
		$can_trust       = ! empty( $checked_trusted );

		// 1) Verified — ONLY with a fetched+matched official publisher/studio/developer source.
		if ( self::has_verified_official_source( $sources, max( 0.24, $official_threshold ) ) ) {
			return PIV_Layers::VERIFIED;
		}

		// 2) Conflicts among accepted matches.
		if ( ! empty( $sources['has_conflicts'] ) && $external > 0 ) {
			return PIV_Layers::UNDER_REVIEW;
		}

		// 3) Trusted — real news/official evidence only (never anonymous web junk).
		if ( ! $can_trust ) {
			// fall through
		} elseif ( $news_count >= 1 && $news_score >= 0.24 ) {
			return PIV_Layers::TRUSTED_SOURCE;
		} elseif ( $official_count >= 1 && $official_score >= 0.22 ) {
			return PIV_Layers::TRUSTED_SOURCE;
		} elseif ( $news_domains >= 2 && $news_score >= 0.24 ) {
			return PIV_Layers::TRUSTED_SOURCE;
		}

		unset( $fetched, $max_match );

		// 4) Very new posts may still be waiting for coverage to appear.
		if ( 0 === $external && $age_minutes < (int) ( $settings['review_window'] ?? 15 ) ) {
			return PIV_Layers::UNDER_REVIEW;
		}

		// 5) No matching sources → initial report (non-matches are not counted).
		return PIV_Layers::INITIAL_REPORT;
	}

	/**
	 * Evidence strong enough for "מקור מהימן" / display.
	 *
	 * @param array $sources Source analysis.
	 * @return array
	 */
	public static function get_trusted_evidence_sources( $sources ) {
		$items = array();
		foreach ( self::get_checked_evidence_sources( $sources, 0.24 ) as $source ) {
			$is_official = ! empty( $source['is_official'] ) || 'official' === ( $source['origin'] ?? '' );
			$is_news     = ! empty( $source['is_news'] ) || in_array( ( $source['origin'] ?? '' ), array( 'news', 'rss' ), true );
			if ( ! $is_official && ! $is_news ) {
				continue;
			}
			if ( empty( $source['title'] ) || empty( $source['url'] ) ) {
				continue;
			}
			// Prefer sources Gemini confirmed; always require a real HTTP fetch.
			if ( empty( $source['http_ok'] ) && empty( $source['fetched'] ) ) {
				continue;
			}
			$items[] = $source;
		}
		return $items;
	}

	/**
	 * Sources that were actually fetched and topic-matched (not discovery-only hits).
	 *
	 * @param array $sources   Source analysis.
	 * @param float $min_score Minimum match score.
	 * @return array
	 */
	public static function get_checked_evidence_sources( $sources, $min_score = 0.22 ) {
		$items = array();

		foreach ( (array) ( $sources['external'] ?? array() ) as $source ) {
			$url   = isset( $source['url'] ) ? (string) $source['url'] : '';
			$score = (float) ( $source['effective_match'] ?? $source['match_score'] ?? 0 );
			if ( '' === $url || $score < $min_score ) {
				continue;
			}
			// Must have been opened over HTTP and passed context matching — not RSS-title-only hints.
			$http_ok = ! empty( $source['http_ok'] ) || ( ! empty( $source['fetched'] ) && empty( $source['error'] ) && ! empty( $source['title'] ) );
			if ( ! $http_ok || empty( $source['context_ok'] ) || ! empty( $source['rss_hint'] ) ) {
				continue;
			}
			if ( ! empty( $source['error'] ) && $score < 0.35 ) {
				continue;
			}
			$items[] = $source;
		}

		return $items;
	}

	/**
	 * Whether analyzed sources include a given URL.
	 *
	 * @param array  $sources Source analysis.
	 * @param string $url     URL to find.
	 * @return bool
	 */
	public static function sources_include_url( $sources, $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return false;
		}

		foreach ( self::get_checked_evidence_sources( $sources, 0.22 ) as $source ) {
			if ( isset( $source['url'] ) && (string) $source['url'] === $url ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether analysis includes a concrete checked verification source.
	 *
	 * @param array      $sources   Source analysis.
	 * @param array|null $manual    Optional manual override (evidence URL alone is not enough).
	 * @param float      $min_score Minimum match score for auto evidence.
	 * @return bool
	 */
	public static function has_verification_evidence( $sources, $manual = null, $min_score = 0.28 ) {
		unset( $manual );

		return ! empty( self::get_checked_evidence_sources( $sources, $min_score ) );
	}

	/**
	 * Best match score among gaming-news sources.
	 *
	 * @param array $sources Source analysis.
	 * @return float
	 */
	public static function get_best_news_match_score( $sources ) {
		$max = 0.0;

		foreach ( (array) ( $sources['external'] ?? array() ) as $source ) {
			$origin = $source['origin'] ?? '';
			if ( empty( $source['is_news'] ) && ! in_array( $origin, array( 'news', 'rss' ), true ) ) {
				continue;
			}

			$score = (float) ( $source['effective_match'] ?? $source['match_score'] ?? 0 );
			if ( $score > $max ) {
				$max = $score;
			}
		}

		return $max;
	}

	/**
	 * Whether the post itself describes an already-released / published game or update.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function post_describes_published_release( $post ) {
		$text = mb_strtolower(
			wp_strip_all_tags( $post->post_title . ' ' . $post->post_excerpt . ' ' . mb_substr( wp_strip_all_tags( $post->post_content ), 0, 500, 'UTF-8' ) ),
			'UTF-8'
		);

		$patterns = array(
			'/הושק/',
			'/הושקה/',
			'/יצא לאור/',
			'/יצאה לאור/',
			'/זמין עכשיו/',
			'/זמין להורדה/',
			'/כבר יצא/',
			'/שוחרר/',
			'/שוחררה/',
			'/\breleased\b/',
			'/\bout now\b/',
			'/\bavailable now\b/',
			'/\blaunched\b/',
			'/\bnow available\b/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build human-readable reason strings.
	 *
	 * @param string  $layer    Layer slug.
	 * @param array   $sources  Source analysis.
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Settings.
	 * @param array   $discovery Search/news/official discovery payloads.
	 * @return array
	 */
	public static function build_reasons( $layer, $sources, $post, $settings, $discovery = array() ) {
		if ( isset( $discovery['urls'] ) || isset( $discovery['query'] ) || isset( $discovery['provider'] ) ) {
			$discovery = array( 'search' => $discovery );
		}

		$reasons  = array();
		$counts   = isset( $sources['counts'] ) ? $sources['counts'] : array();
		$external = isset( $sources['external'] ) ? $sources['external'] : array();
		$search   = isset( $discovery['search'] ) ? $discovery['search'] : array();
		$news_urls = isset( $discovery['news']['urls'] ) ? (array) $discovery['news']['urls'] : array();
		$official_urls = isset( $discovery['official']['urls'] ) ? (array) $discovery['official']['urls'] : array();

		switch ( $layer ) {
			case PIV_Layers::VERIFIED:
				$reasons[] = __( 'המידע אומת מול מקור רשמי של המפתחת/הסטודיו/החברה', 'content-verification-badge' );
				$official_ev = null;
				foreach ( self::get_checked_evidence_sources( $sources, 0.12 ) as $src ) {
					if ( ! empty( $src['is_official'] ) || ( $src['origin'] ?? '' ) === 'official' ) {
						$official_ev = $src;
						break;
					}
				}
				if ( $official_ev && ! empty( $official_ev['domain'] ) ) {
					$reasons[] = sprintf(
						/* translators: %s: official domain */
						__( 'מקור רשמי: %s', 'content-verification-badge' ),
						$official_ev['domain']
					);
					$pct = (float) ( $official_ev['match_score'] ?? $official_ev['effective_match'] ?? 0 );
					if ( $pct > 0 ) {
						$reasons[] = sprintf(
							/* translators: %d: similarity percent */
							__( 'התאמת תוכן: %d%%', 'content-verification-badge' ),
							(int) round( $pct * 100 )
						);
					}
				}
				break;

			case PIV_Layers::TRUSTED_SOURCE:
				$reasons[] = __( 'נמצא מקור חיצוני מתאים שנפתח ואושר', 'content-verification-badge' );
				if ( ! empty( $counts['news'] ) ) {
					$reasons[] = sprintf(
						/* translators: %d: number of gaming news sources */
						_n(
							'מקור חדשותי מתאים אחד',
							'%d מקורות חדשותיים מתאימים',
							(int) $counts['news'],
							'content-verification-badge'
						),
						(int) $counts['news']
					);
				}
				if ( ! empty( $counts['official'] ) ) {
					$reasons[] = sprintf(
						/* translators: %d: number of official sources */
						_n(
							'מקור רשמי מתאים אחד',
							'%d מקורות רשמיים מתאימים',
							(int) $counts['official'],
							'content-verification-badge'
						),
						(int) $counts['official']
					);
				}
				if ( ! empty( $external ) ) {
					$domains = array_unique( array_filter( wp_list_pluck( $external, 'domain' ) ) );
					if ( ! empty( $domains ) ) {
						$reasons[] = sprintf(
							/* translators: %s: domains */
							__( 'מקורות: %s', 'content-verification-badge' ),
							implode( ', ', array_slice( $domains, 0, 4 ) )
						);
					}
				}
				break;

			case PIV_Layers::UNDER_REVIEW:
				if ( self::post_age_minutes( $post ) < (int) $settings['review_window'] ) {
					$reasons[] = __( 'הפוסט חדש — עדיין מחכים למקור מתאים', 'content-verification-badge' );
				}
				if ( ! empty( $sources['has_conflicts'] ) ) {
					$reasons[] = __( 'נמצאו מקורות מתאימים עם מידע סותר', 'content-verification-badge' );
				}
				if ( empty( $reasons ) ) {
					$reasons[] = __( 'עדיין אין מקור מתאים שאושר', 'content-verification-badge' );
				}
				break;

			case PIV_Layers::INITIAL_REPORT:
			default:
				$scanned  = (int) ( $counts['scanned'] ?? 0 );
				$rejected = (int) ( $counts['rejected'] ?? 0 );
				$found    = count( $news_urls ) + count( $official_urls ) + count( (array) ( $discovery['rss']['urls'] ?? array() ) ) + count( (array) ( $search['urls'] ?? array() ) );
				$raw      = (int) ( $search['raw_count'] ?? 0 );

				if ( $scanned > 0 && $rejected > 0 ) {
					$reasons[] = sprintf(
						/* translators: 1: scanned count, 2: rejected count */
						__( 'נסרקו %1$d מועמדים — אף אחד לא התאים לתוכן הפוסט (נדחו %2$d)', 'content-verification-badge' ),
						$scanned,
						$rejected
					);
				} elseif ( $found > 0 || $raw > 0 ) {
					$reasons[] = __( 'נמצאו מועמדים בחיפוש, אבל אף אחד לא עבר בדיקת תוכן', 'content-verification-badge' );
				} else {
					$reasons[] = __( 'לא נמצאו מועמדים בחיפוש (Google News / RSS / מנועי חיפוש). נסה שוב או הוסף מפתח Google/Bing', 'content-verification-badge' );
				}
				break;
		}

		return $reasons;
	}

	/**
	 * Build post text used for content comparison (title + excerpt + body).
	 *
	 * @param WP_Post $post Post object.
	 * @param int     $max  Max characters.
	 * @return string
	 */
	public static function get_post_comparison_text( $post, $max = 2500 ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return '';
		}

		$parts = array(
			(string) $post->post_title,
			wp_strip_all_tags( (string) $post->post_excerpt ),
			wp_strip_all_tags( (string) $post->post_content ),
		);
		$text = trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
		$max  = max( 200, (int) $max );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max, 'UTF-8' );
		}

		return substr( $text, 0, $max );
	}

	/**
	 * Fetch remote page, read article body, and compare with post content.
	 *
	 * @param string $url     URL.
	 * @param int    $post_id Post ID.
	 * @param array  $args    Optional args: bypass_cache.
	 * @return array|null
	 */
	public static function fetch_source_snapshot( $url, $post_id = 0, $args = array() ) {
		$cache_key = 'piv_src_' . md5( $url . '|v2' );
		if ( empty( $args['bypass_cache'] ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		} else {
			delete_transient( $cache_key );
		}

		$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 8;
		$timeout = max( 4, min( 20, $timeout ) );

		// Browser-like UA — news sites behind Cloudflare/WAF block bot UAs with 403,
		// and a blocked fetch can never be accepted as verification evidence.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				'headers'     => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9,he;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'title'       => '',
				'body_text'   => '',
				'match_score' => 0,
				'title_score' => 0,
				'body_score'  => 0,
				'http_ok'     => false,
				'error'       => $response->get_error_message(),
			);
			set_transient( $cache_key, $result, HOUR_IN_SECONDS );
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return array(
				'title'       => '',
				'body_text'   => '',
				'match_score' => 0,
				'title_score' => 0,
				'body_score'  => 0,
				'http_ok'     => false,
				'error'       => 'HTTP ' . $code,
			);
		}

		$html = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $html ) > 600000 ) {
			$html = substr( $html, 0, 600000 );
		}

		$title = self::extract_og_content( $html, 'title' );
		if ( '' === $title ) {
			$title = self::extract_title_from_html( $html );
		}
		$desc = self::extract_og_content( $html, 'description' );
		if ( '' === $desc ) {
			$desc = self::extract_meta_description( $html );
		}
		$body_text = self::extract_article_text( $html );
		$remote    = trim( $title . ' ' . $desc . ' ' . $body_text );

		$post_text   = '';
		$post_title  = '';
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$post_title = (string) $post->post_title;
				$post_text  = self::get_post_comparison_text( $post, 2500 );
			}
		}

		$title_score = ( '' !== $post_title && '' !== $title )
			? self::text_similarity( $post_title, $title )
			: 0.0;
		$body_score  = ( '' !== $post_text && '' !== $remote )
			? self::text_similarity( $post_text, $remote )
			: 0.0;
		// Prefer body comparison; keep title as a floor so strong headline matches still count.
		$score = max( $title_score, ( $body_score * 0.7 ) + ( $title_score * 0.3 ) );
		if ( $body_score >= 0.28 && $title_score >= 0.2 ) {
			$score = min( 1.0, $score + 0.08 );
		}

		$result = array(
			'title'        => $title,
			'description'  => $desc,
			'body_text'    => $body_text,
			'body_excerpt' => function_exists( 'mb_substr' ) ? mb_substr( $body_text, 0, 180, 'UTF-8' ) : substr( $body_text, 0, 180 ),
			'title_score'  => round( $title_score, 3 ),
			'body_score'   => round( $body_score, 3 ),
			'match_score'  => round( $score, 3 ),
			'http_ok'      => true,
			'read_chars'   => function_exists( 'mb_strlen' ) ? mb_strlen( $body_text, 'UTF-8' ) : strlen( $body_text ),
		);

		// Cache without the full body to keep transients small.
		$cached = $result;
		$cached['body_text'] = function_exists( 'mb_substr' )
			? mb_substr( $body_text, 0, 2200, 'UTF-8' )
			: substr( $body_text, 0, 2200 );
		set_transient( $cache_key, $cached, 6 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Extract readable article body text from HTML.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function extract_article_text( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}

		$html = preg_replace( '#<(script|style|noscript|svg|iframe|template)[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<!--.*?-->#s', ' ', $html );

		$chunks = array();
		$patterns = array(
			'#<article\b[^>]*>(.*?)</article>#is',
			'#<main\b[^>]*>(.*?)</main>#is',
			'#<div[^>]+(?:class|id)=["\'][^"\']*(?:article|entry|post|content|story|news)[^"\']*["\'][^>]*>(.*?)</div>#is',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $html, $matches ) ) {
				foreach ( $matches[1] as $chunk ) {
					$chunks[] = $chunk;
				}
			}
			if ( count( $chunks ) >= 3 ) {
				break;
			}
		}

		if ( empty( $chunks ) && preg_match( '#<body\b[^>]*>(.*)</body>#is', $html, $m ) ) {
			$chunks[] = $m[1];
		}
		if ( empty( $chunks ) ) {
			$chunks[] = $html;
		}

		$best = '';
		foreach ( $chunks as $chunk ) {
			$text = wp_strip_all_tags( $chunk );
			$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
			$text = preg_replace( '/\s+/u', ' ', $text );
			$text = trim( $text );
			if ( function_exists( 'mb_strlen' ) ) {
				if ( mb_strlen( $text, 'UTF-8' ) > ( function_exists( 'mb_strlen' ) ? mb_strlen( $best, 'UTF-8' ) : strlen( $best ) ) ) {
					$best = $text;
				}
			} elseif ( strlen( $text ) > strlen( $best ) ) {
				$best = $text;
			}
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $best, 0, 3500, 'UTF-8' );
		}

		return substr( $best, 0, 3500 );
	}

	/**
	 * Extract <title> from HTML.
	 *
	 * @param string $html HTML body.
	 * @return string
	 */
	public static function extract_title_from_html( $html ) {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * Extract meta description from HTML.
	 *
	 * @param string $html HTML body.
	 * @return string
	 */
	public static function extract_meta_description( $html ) {
		if ( preg_match( '#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		if ( preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']#i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * Compute normalized text similarity between 0 and 1.
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 * @return float
	 */
	public static function text_similarity( $a, $b ) {
		$a = self::normalize_text( $a );
		$b = self::normalize_text( $b );

		if ( '' === $a || '' === $b ) {
			return 0.0;
		}

		similar_text( $a, $b, $percent );
		$char_score = $percent / 100;

		$words_a = array_unique( preg_split( '/\s+/', $a ) );
		$words_b = array_unique( preg_split( '/\s+/', $b ) );
		$words_a = array_filter( $words_a );
		$words_b = array_filter( $words_b );

		if ( empty( $words_a ) || empty( $words_b ) ) {
			return $char_score;
		}

		$overlap    = count( array_intersect( $words_a, $words_b ) );
		$word_score = $overlap / max( count( $words_a ), count( $words_b ) );

		return round( ( $char_score * 0.4 ) + ( $word_score * 0.6 ), 3 );
	}

	/**
	 * Normalize text for comparison.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Detect conflicting sources by low cross-similarity of titles.
	 *
	 * @param array $external External sources.
	 * @return bool
	 */
	public static function detect_conflicts( $external ) {
		$titles = array();

		foreach ( $external as $source ) {
			if ( ! empty( $source['is_official'] ) || ( isset( $source['origin'] ) && 'official' === $source['origin'] ) ) {
				continue;
			}
			if ( ! empty( $source['is_news'] ) || ( isset( $source['origin'] ) && 'news' === $source['origin'] ) ) {
				continue;
			}
			if ( ! empty( $source['title'] ) ) {
				$titles[] = self::normalize_text( $source['title'] );
			}
		}

		if ( count( $titles ) < 2 ) {
			return false;
		}

		for ( $i = 0; $i < count( $titles ); $i++ ) {
			for ( $j = $i + 1; $j < count( $titles ); $j++ ) {
				if ( self::text_similarity( $titles[ $i ], $titles[ $j ] ) < 0.35 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether there are 2+ independent external domains.
	 *
	 * @param array $sources Source analysis.
	 * @return bool
	 */
	public static function has_multiple_independent_domains( $sources ) {
		if ( empty( $sources['external'] ) ) {
			return false;
		}

		$domains = array_filter( wp_list_pluck( $sources['external'], 'domain' ) );
		return count( array_unique( $domains ) ) >= 2;
	}

	/**
	 * Minutes since post was published.
	 *
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public static function post_age_minutes( $post ) {
		$published = strtotime( $post->post_date_gmt . ' UTC' );
		if ( ! $published ) {
			return 0;
		}

		return (int) floor( ( time() - $published ) / MINUTE_IN_SECONDS );
	}

	/**
	 * Whether a discovered source is relevant enough to count.
	 *
	 * @param array  $entry   Source entry.
	 * @param int    $post_id Post ID.
	 * @param string $origin  cited|discovered.
	 * @return bool
	 */
	public static function is_relevant_source( $entry, $post_id, $origin ) {
		unset( $post_id );
		$url         = isset( $entry['url'] ) ? (string) $entry['url'] : '';
		$domain      = isset( $entry['domain'] ) ? (string) $entry['domain'] : PIV_Sources::domain_from_url( $url );
		$is_official = ! empty( $entry['is_official'] ) || PIV_Sources::is_official_domain( $domain );
		$is_news     = ! $is_official && ( ! empty( $entry['is_news'] ) || PIV_Sources::is_news_domain( $domain ) );

		if ( $is_official || $is_news || in_array( $origin, array( 'official', 'news', 'discovered', 'rss' ), true ) ) {
			if ( ! PIV_Sources::is_article_url( $url ) ) {
				return false;
			}
		}

		return PIV_Match::should_accept( $entry, $origin );
	}

	/**
	 * Evaluate whether a candidate source matches the post's topic/context.
	 *
	 * @param WP_Post|null $post       Post object.
	 * @param string       $src_title  Remote title.
	 * @param string       $url        Source URL.
	 * @param float        $text_score Title/body similarity.
	 * @param float        $key_score  Key-token similarity.
	 * @param string       $src_body   Remote article body text.
	 * @return array{ok:bool,score:float,reason:string}
	 */
	public static function evaluate_source_context( $post, $src_title, $url, $text_score = 0.0, $key_score = 0.0, $src_body = '' ) {
		return PIV_Match::evaluate( $post, $src_title, $url, $text_score, $key_score, $src_body );
	}

	/**
	 * Distinctive tokens from the post title (excludes platforms/franchise stopwords).
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function get_distinctive_title_tokens( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return array();
		}

		$tokens = self::expand_language_aliases(
			self::extract_key_tokens( wp_strip_all_tags( (string) $post->post_title ) )
		);
		$noise  = self::broad_topic_noise_tokens();

		return array_values(
			array_filter(
				$tokens,
				static function ( $token ) use ( $noise ) {
					$token = strtolower( (string) $token );
					if ( strlen( $token ) < 3 ) {
						return false;
					}
					return ! in_array( $token, $noise, true );
				}
			)
		);
	}

	/**
	 * Broad tokens that alone never prove topic match.
	 *
	 * @return array
	 */
	public static function broad_topic_noise_tokens() {
		return array(
			'game', 'games', 'gaming', 'trailer', 'update', 'news', 'leak', 'rumor', 'rumour',
			'release', 'date', 'official', 'announcement', 'new', 'video', 'watch', 'play',
			'xbox', 'playstation', 'nintendo', 'steam', 'switch', 'ps5', 'ps4', 'pc',
			'microsoft', 'sony', 'epic', 'mobile', 'console', 'multiplayer', 'dlc',
			'משחק', 'משחקים', 'גיימינג', 'טריילר', 'עדכון', 'חדשות', 'שמועה', 'דליפה',
			'יציאה', 'רשמי', 'הכרזה', 'חדש', 'אקסבוקס', 'פלייסטיישן', 'נינטנדו', 'סטים',
		);
	}

	/**
	 * Whether text/URL looks like a review / walkthrough — not hard news.
	 *
	 * @param string $text Text or URL.
	 * @return bool
	 */
	public static function is_review_like_text( $text ) {
		$text = strtolower( (string) $text );
		if ( '' === $text ) {
			return false;
		}

		$patterns = array(
			'#/(reviews?|walkthroughs?|spoilers?)/#',
			'#\breview(ed|s)?\b#',
			'#\bwalkthrough\b#',
			'#\bending[-_ ]explained\b#',
			'#ביקורת#',
			'#סקירה#',
			'#\bscore:\s*\d#',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reject clearly dated old review/archive URLs, not every year mention.
	 *
	 * @param WP_Post $post      Post object.
	 * @param string  $src_title Source title.
	 * @param string  $url       Source URL.
	 * @return bool True when outdated.
	 */
	public static function is_outdated_source_for_post( $post, $src_title, $url ) {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$hay  = strtolower( (string) $src_title . ' ' . $path );

		$post_year = (int) gmdate( 'Y', strtotime( $post->post_date_gmt . ' UTC' ) );
		if ( $post_year < 2000 ) {
			$path_year = (int) gmdate( 'Y' );
			$post_year = $path_year;
		}

		$post_text = strtolower( wp_strip_all_tags( $post->post_title . ' ' . $post->post_excerpt ) );
		$reviewish = self::is_review_like_text( $hay );

		// Only treat /2015/ style path years (or review titles with old years) as outdated.
		if ( preg_match_all( '#/(?:19|20)\d{2}/#', $path . '/', $matches ) ) {
			foreach ( $matches[0] as $chunk ) {
				$year = (int) trim( $chunk, '/' );
				if ( $year >= 2005 && ( $post_year - $year ) >= 3 && false === strpos( $post_text, (string) $year ) ) {
					return true;
				}
			}
		}

		if ( $reviewish && preg_match_all( '/\b(19|20)\d{2}\b/', $hay, $matches ) ) {
			foreach ( array_map( 'intval', array_unique( $matches[0] ) ) as $year ) {
				if ( $year >= 2005 && ( $post_year - $year ) >= 2 && false === strpos( $post_text, (string) $year ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Expand Hebrew brand/game terms to English equivalents for matching.
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	public static function expand_language_aliases( $tokens ) {
		$map = array(
			'פלייסטיישן'  => array( 'playstation', 'ps5', 'ps4' ),
			'פס5'         => array( 'ps5', 'playstation' ),
			'פס4'         => array( 'ps4', 'playstation' ),
			'אקסבוקס'     => array( 'xbox' ),
			'נינטנדו'     => array( 'nintendo' ),
			'סוויץ'       => array( 'switch', 'nintendo' ),
			'סטים'        => array( 'steam' ),
			'פורטנייט'    => array( 'fortnite' ),
			'מיינקראפט'   => array( 'minecraft' ),
			'גטה'         => array( 'gta' ),
			'ויצ׳ר'       => array( 'witcher' ),
			'אלדן'        => array( 'elden' ),
			'סילנט'       => array( 'silent' ),
			'היל'         => array( 'hill' ),
			'ספיידרמן'    => array( 'spiderman', 'spider' ),
			'רזידנט'      => array( 'resident' ),
			'איבל'        => array( 'evil' ),
			'האלו'        => array( 'halo' ),
			'ואלורנט'     => array( 'valorant' ),
			'דיאבלו'      => array( 'diablo' ),
			'וורזון'      => array( 'warzone' ),
			'נדחה'        => array( 'delayed', 'delay' ),
			'הכרזה'       => array( 'announced', 'announce', 'announcement' ),
			'הוכרז'       => array( 'announced', 'announce' ),
			'הכריזה'      => array( 'announced', 'announce' ),
			'הכריז'       => array( 'announced', 'announce' ),
			'טריילר'      => array( 'trailer' ),
			'עדכון'       => array( 'update', 'patch' ),
			'דלף'         => array( 'leak', 'leaked' ),
			'דליפה'       => array( 'leak', 'leaked' ),
			'הושק'        => array( 'released', 'launch', 'release' ),
			'בוטל'        => array( 'cancelled', 'canceled' ),
			'ביטול'       => array( 'cancelled', 'canceled' ),
		);

		$out = array();
		foreach ( (array) $tokens as $token ) {
			$token = strtolower( (string) $token );
			if ( '' === $token ) {
				continue;
			}
			$out[] = $token;
			if ( isset( $map[ $token ] ) ) {
				foreach ( $map[ $token ] as $alias ) {
					$out[] = $alias;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Distinctive anchors from the post (game names, numbers, event words).
	 *
	 * @param string $text Post text.
	 * @return array
	 */
	public static function extract_context_anchors( $text ) {
		$tokens = self::extract_key_tokens( $text );
		$generic = array(
			'playstation', 'xbox', 'nintendo', 'switch', 'steam', 'epic', 'pc', 'gaming',
			'game', 'games', 'trailer', 'update', 'news', 'official', 'microsoft', 'sony',
			'פלייסטיישן', 'אקסבוקס', 'נינטנדו', 'סוויץ', 'סטים', 'משחק', 'משחקים', 'טריילר',
			'חדשות', 'עדכון', 'רשמי',
		);

		$anchors = array();
		foreach ( $tokens as $token ) {
			$token = strtolower( (string) $token );
			if ( in_array( $token, $generic, true ) ) {
				continue;
			}
			if ( strlen( $token ) < 3 && ! preg_match( '/^\d+$/', $token ) ) {
				continue;
			}
			$anchors[] = $token;
		}

		return array_values( array_unique( $anchors ) );
	}

	/**
	 * True when overlap is only broad franchise/platform words.
	 *
	 * @param array $anchors    Post anchors.
	 * @param array $src_tokens Source tokens.
	 * @return bool
	 */
	/**
	 * True when overlap tokens are only platforms/generic words (not a specific topic).
	 *
	 * @param array $overlap Overlapping tokens.
	 * @return bool
	 */
	public static function is_platform_only_overlap( $overlap ) {
		$noise = self::broad_topic_noise_tokens();
		$overlap = array_filter( array_map( 'strtolower', (array) $overlap ) );
		if ( empty( $overlap ) ) {
			return false;
		}

		foreach ( $overlap as $token ) {
			if ( ! in_array( $token, $noise, true ) && ! preg_match( '/^(ps|xbox)?[45]$/', $token ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Back-compat alias.
	 *
	 * @param array $anchors    Post anchors.
	 * @param array $src_tokens Source tokens.
	 * @return bool
	 */
	public static function is_franchise_only_overlap( $anchors, $src_tokens ) {
		$overlap = array_intersect(
			array_map( 'strtolower', (array) $anchors ),
			array_map( 'strtolower', (array) $src_tokens )
		);

		return self::is_platform_only_overlap( $overlap );
	}

	/**
	 * Upgrade origin labels from domain lists (official / news).
	 *
	 * @param array $origins URL => origin map.
	 * @return array
	 */
	public static function reclassify_source_origins( $origins ) {
		if ( ! is_array( $origins ) ) {
			return array();
		}

		foreach ( $origins as $url => $origin ) {
			if ( 'manual' === $origin ) {
				continue;
			}

			$domain = PIV_Sources::domain_from_url( (string) $url );
			if ( ! $domain ) {
				continue;
			}

			if ( PIV_Sources::is_official_domain( $domain ) ) {
				$origins[ $url ] = 'official';
				continue;
			}

			if ( PIV_Sources::is_news_domain( $domain ) ) {
				// Keep RSS tag for provenance, but treat as news for scoring/counts.
				$origins[ $url ] = ( 'rss' === $origin ) ? 'rss' : 'news';
			}
		}

		return $origins;
	}

	/**
	 * Whether a URL should be fetched and used as verification evidence.
	 *
	 * @param string $url    URL.
	 * @param string $origin cited|discovered|official|news.
	 * @return bool
	 */
	public static function is_verification_candidate_url( $url, $origin ) {
		$domain      = PIV_Sources::domain_from_url( $url );
		$is_official = PIV_Sources::is_official_domain( $domain );
		$is_news     = PIV_Sources::is_news_domain( $domain );

		if ( $is_official || $is_news || in_array( $origin, array( 'official', 'news', 'discovered' ), true ) ) {
			return PIV_Sources::is_article_url( $url );
		}

		return true;
	}

	/**
	 * Sort URLs so official sources are fetched first.
	 *
	 * @param array $urls    URL list.
	 * @param array $origins URL origin map.
	 * @return array
	 */
	public static function prioritize_urls( $urls, $origins ) {
		usort(
			$urls,
			static function ( $a, $b ) use ( $origins ) {
				$priority_a = self::url_fetch_priority( $a, $origins );
				$priority_b = self::url_fetch_priority( $b, $origins );
				if ( $priority_a === $priority_b ) {
					return 0;
				}

				return ( $priority_a < $priority_b ) ? -1 : 1;
			}
		);

		return array_values( $urls );
	}

	/**
	 * @param string $url     URL.
	 * @param array  $origins Origin map.
	 * @return int
	 */
	public static function url_fetch_priority( $url, $origins ) {
		$origin      = isset( $origins[ $url ] ) ? $origins[ $url ] : 'discovered';
		$domain      = PIV_Sources::domain_from_url( $url );
		$is_official = PIV_Sources::is_official_domain( $domain );
		$is_news     = PIV_Sources::is_news_domain( $domain );

		if ( 'manual' === $origin ) {
			return 0;
		}
		if ( $is_official && 'official' === $origin ) {
			return 1;
		}
		if ( ( $is_news || 'rss' === $origin ) && in_array( $origin, array( 'news', 'rss' ), true ) ) {
			return 2;
		}
		if ( $is_official && 'cited' === $origin ) {
			return 3;
		}
		if ( $is_news && 'cited' === $origin ) {
			return 4;
		}
		if ( 'cited' === $origin ) {
			return 5;
		}
		if ( 'official' === $origin ) {
			return 6;
		}
		if ( in_array( $origin, array( 'news', 'rss' ), true ) ) {
			return 7;
		}

		return 8;
	}

	/**
	 * Manual override fields from the post editor.
	 *
	 * @param int $post_id Post ID.
	 * @return array{layer:string,evidence:string,note:string}
	 */
	public static function get_manual_override( $post_id ) {
		$layer = sanitize_key( (string) get_post_meta( $post_id, PIV_META_MANUAL_LAYER, true ) );
		$allowed = array(
			PIV_Layers::INITIAL_REPORT,
			PIV_Layers::UNDER_REVIEW,
			PIV_Layers::TRUSTED_SOURCE,
			PIV_Layers::VERIFIED,
		);

		if ( ! in_array( $layer, $allowed, true ) ) {
			$layer = '';
		}

		return array(
			'layer'    => $layer,
			'evidence' => esc_url_raw( (string) get_post_meta( $post_id, PIV_META_MANUAL_EVIDENCE, true ) ),
			'note'     => sanitize_text_field( (string) get_post_meta( $post_id, PIV_META_MANUAL_NOTE, true ) ),
		);
	}

	/**
	 * Attach RSS titles/scores and inject matches that fetch skipped.
	 *
	 * @param array        $sources Source analysis.
	 * @param array        $rss     RSS discovery payload.
	 * @param WP_Post|null $post    Post object.
	 * @return array
	 */
	public static function merge_rss_source_hints( $sources, $rss, $post = null ) {
		if ( empty( $sources['external'] ) ) {
			$sources['external'] = array();
		}
		if ( empty( $sources['counts'] ) || ! is_array( $sources['counts'] ) ) {
			$sources['counts'] = array(
				'external'   => 0,
				'fetched'    => 0,
				'discovered' => 0,
				'cited'      => 0,
				'official'   => 0,
				'news'       => 0,
			);
		}

		$existing_urls = array();
		foreach ( $sources['external'] as $entry ) {
			if ( ! empty( $entry['url'] ) ) {
				$existing_urls[ $entry['url'] ] = true;
			}
		}

		foreach ( (array) ( $rss['items'] ?? array() ) as $item ) {
			$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
			$title = isset( $item['title'] ) ? (string) $item['title'] : '';
			$score = (float) ( $item['score'] ?? 0 );
			if ( '' === $url || $score < 0.28 ) {
				continue;
			}

			$domain = PIV_Sources::domain_from_url( $url );

			if ( isset( $existing_urls[ $url ] ) ) {
				foreach ( $sources['external'] as &$entry ) {
					if ( ( $entry['url'] ?? '' ) !== $url ) {
						continue;
					}
					if ( empty( $entry['title'] ) && '' !== $title ) {
						$entry['title'] = $title;
					}
					// Never invent a "fetched" page from RSS title alone.
					if ( $score > (float) ( $entry['match_score'] ?? 0 ) && ! empty( $entry['fetched'] ) ) {
						$entry['match_score']     = $score;
						$entry['effective_match'] = $score;
						$entry['origin']          = 'rss';
						$entry['is_news']         = true;
						$entry['context_ok']      = true;
					}
				}
				unset( $entry );
				continue;
			}

			// Do not count unmatched RSS title hits as sources.
			unset( $domain, $title );
		}

		$scores = array();
		foreach ( $sources['external'] as $entry ) {
			if ( ! empty( $entry['match_score'] ) ) {
				$scores[] = (float) $entry['match_score'];
			}
		}
		if ( ! empty( $scores ) ) {
			$sources['max_match'] = max( $scores );
			foreach ( $sources['external'] as $entry ) {
				if ( isset( $entry['match_score'] ) && (float) $entry['match_score'] === (float) $sources['max_match'] ) {
					$sources['best_match'] = $entry;
					break;
				}
			}
		}

		unset( $post );

		return $sources;
	}

	/**
	 * Extract distinctive tokens for cross-language matching.
	 *
	 * @param string $text Source text.
	 * @param string $url  Optional URL for path tokens.
	 * @return array
	 */
	public static function extract_key_tokens( $text, $url = '' ) {
		$text = self::normalize_text( $text );

		if ( $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path ) {
				$text .= ' ' . str_replace( array( '-', '_', '/' ), ' ', $path );
			}
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			if ( '' !== $query ) {
				$text .= ' ' . str_replace( array( '=', '&' ), ' ', $query );
			}
		}

		$tokens = array();
		if ( preg_match_all( '/\b\d+\b/u', $text, $matches ) ) {
			$tokens = array_merge( $tokens, $matches[0] );
		}
		if ( preg_match_all( '/\b[a-z][a-z0-9]{2,}\b/u', $text, $matches ) ) {
			$tokens = array_merge( $tokens, $matches[0] );
		}
		if ( preg_match_all( '/[\x{0590}-\x{05FF}]{3,}/u', $text, $matches ) ) {
			$tokens = array_merge( $tokens, $matches[0] );
		}

		$stop_words = array(
			'the', 'and', 'for', 'with', 'from', 'that', 'this', 'will', 'have', 'has',
			'new', 'now', 'game', 'games', 'news', 'blog', 'official', 'press',
			'video', 'watch', 'read', 'more', 'about', 'your', 'our', 'are', 'was',
			'www', 'com', 'https', 'http',
			'review', 'reviews', 'reviewed', 'walkthrough', 'score', 'rating',
			'opinion', 'editorial', 'ביקורת', 'סקירה', 'מדריך', 'דירוג', 'ציון', 'דעה',
		);

		$tokens = array_values(
			array_unique(
				array_filter(
					$tokens,
					static function ( $token ) use ( $stop_words ) {
						$token = strtolower( (string) $token );
						return '' !== $token && ! in_array( $token, $stop_words, true );
					}
				)
			)
		);

		return $tokens;
	}

	/**
	 * Match game names / numbers across Hebrew news and English official pages.
	 *
	 * @param string $post_text Post title + excerpt.
	 * @param string $source_text Remote title/description.
	 * @param string $url         Source URL.
	 * @return float
	 */
	public static function key_token_similarity( $post_text, $source_text, $url = '' ) {
		$post_tokens   = self::expand_language_aliases( self::extract_key_tokens( $post_text ) );
		$source_tokens = self::expand_language_aliases( self::extract_key_tokens( $source_text, $url ) );

		if ( empty( $post_tokens ) || empty( $source_tokens ) ) {
			return 0.0;
		}

		$overlap = array_intersect( $post_tokens, $source_tokens );
		if ( empty( $overlap ) ) {
			return 0.0;
		}

		$overlap_count = count( $overlap );
		$has_long_hit  = false;
		foreach ( $overlap as $token ) {
			if ( strlen( $token ) >= 5 || preg_match( '/^\d+$/', $token ) ) {
				$has_long_hit = true;
				break;
			}
		}

		if ( $overlap_count < 2 && ! $has_long_hit ) {
			return 0.0;
		}

		$score = $overlap_count / max( 3, min( count( $post_tokens ), count( $source_tokens ) ) );
		return round( min( 1.0, $score ), 3 );
	}

	/**
	 * @param array $sources Source analysis.
	 * @return float
	 */
	public static function get_best_official_match_score( $sources ) {
		$max = 0.0;

		foreach ( (array) ( $sources['external'] ?? array() ) as $source ) {
			if ( empty( $source['is_official'] ) && ( $source['origin'] ?? '' ) !== 'official' ) {
				continue;
			}

			$score = (float) ( $source['effective_match'] ?? $source['match_score'] ?? 0 );
			if ( $score > $max ) {
				$max = $score;
			}
		}

		return $max;
	}

	/**
	 * @param array $sources Source analysis.
	 * @return bool
	 */
	public static function has_fetched_official_source( $sources ) {
		foreach ( (array) ( $sources['external'] ?? array() ) as $source ) {
			if ( empty( $source['is_official'] ) && ( $source['origin'] ?? '' ) !== 'official' ) {
				continue;
			}
			if ( ! empty( $source['fetched'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array $sources   Source analysis.
	 * @param float $threshold Official verification threshold.
	 * @return bool
	 */
	public static function has_verified_official_source( $sources, $threshold ) {
		foreach ( self::get_checked_evidence_sources( $sources, $threshold ) as $source ) {
			if ( ! empty( $source['is_official'] ) || ( $source['origin'] ?? '' ) === 'official' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count distinct gaming-news domains among accepted sources.
	 *
	 * @param array $sources Source analysis.
	 * @return int
	 */
	public static function count_independent_news_domains( $sources ) {
		$domains = array();

		foreach ( (array) ( $sources['external'] ?? array() ) as $source ) {
			$origin = $source['origin'] ?? '';
			if ( empty( $source['is_news'] ) && ! in_array( $origin, array( 'news', 'rss' ), true ) ) {
				continue;
			}
			$score = (float) ( $source['effective_match'] ?? $source['match_score'] ?? 0 );
			if ( $score < 0.28 ) {
				continue;
			}
			$domain = isset( $source['domain'] ) ? PIV_Sources::normalize_domain( $source['domain'] ) : '';
			if ( '' !== $domain ) {
				$domains[ $domain ] = true;
			}
		}

		return count( $domains );
	}

	/**
	 * Extract Open Graph title/description.
	 *
	 * @param string $html HTML.
	 * @param string $type title|description.
	 * @return string
	 */
	public static function extract_og_content( $html, $type ) {
		$property = 'og:' . $type;
		$patterns = array(
			'#<meta[^>]+property=["\']' . preg_quote( $property, '#' ) . '["\'][^>]+content=["\']([^"\']+)#i',
			'#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . preg_quote( $property, '#' ) . '["\']#i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $match ) ) {
				return trim( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) );
			}
		}

		return '';
	}
}
