<?php
/**
 * External source discovery via web search.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Search {

	/**
	 * Discover external sources for a post by searching the web.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function discover_sources( $post, $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = PIV_Helpers::get_settings();
		}

		if ( 'yes' !== ( $settings['search_enabled'] ?? 'yes' ) ) {
			return self::empty_result( '', 'disabled' );
		}

		$queries = self::build_search_queries( $post );
		if ( empty( $queries ) ) {
			return self::empty_result( '', 'empty_query' );
		}

		$nonce      = isset( $settings['_cache_nonce'] ) ? (string) $settings['_cache_nonce'] : PIV_Helpers::get_cache_nonce( (int) $post->ID );
		$cache_key  = 'piv_search_' . md5( wp_json_encode( $queries ) . '|' . $nonce . '|' . (int) $post->ID );
		$skip_cache = ! empty( $settings['bypass_search_cache'] );
		if ( ! $skip_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$max       = max( 1, min( 10, (int) ( $settings['search_max_results'] ?? 5 ) ) );
		$fetch_max = max( $max * 3, $max + 5 );
		$provider  = (string) ( $settings['search_provider'] ?? 'auto' );
		$urls      = array();
		$used      = '';
		$query     = $queries[0];

		$providers_used = array();

		foreach ( $queries as $try_query ) {
			$query = $try_query;
			$batch = array();

			if ( self::can_use_google( $settings ) && in_array( $provider, array( 'auto', 'google_cse' ), true ) ) {
				$batch = self::search_google_cse( $try_query, $fetch_max, $settings );
				if ( ! empty( $batch ) ) {
					$used             = 'google_cse';
					$providers_used[] = 'google_cse';
				}
			}

			if ( empty( $batch ) && self::can_use_bing( $settings ) && in_array( $provider, array( 'auto', 'bing' ), true ) ) {
				$batch = self::search_bing( $try_query, $fetch_max, $settings );
				if ( ! empty( $batch ) ) {
					$used             = 'bing';
					$providers_used[] = 'bing';
				}
			}

			// Free providers — work without API keys (Google/Yahoo News RSS + DDG/Bing HTML).
			if ( empty( $batch ) || count( $urls ) < $fetch_max ) {
				$free = self::search_free_web( $try_query, $fetch_max );
				if ( ! empty( $free['urls'] ) ) {
					$batch            = array_merge( $batch, $free['urls'] );
					$used             = $used ? $used . '+' . $free['provider'] : $free['provider'];
					$providers_used[] = $free['provider'];
				}
			}

			foreach ( $batch as $url ) {
				if ( ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}

			if ( count( $urls ) >= $fetch_max ) {
				break;
			}
		}

		$raw_urls = array_values( array_unique( $urls ) );
		$filtered = self::filter_article_urls_for_post( $raw_urls, $post, $max, 0.0, true );

		$result = array(
			'query'           => $query,
			'queries'         => $queries,
			'provider'        => $used ? $used : ( ! empty( $providers_used ) ? implode( '+', array_unique( $providers_used ) ) : '' ),
			'providers'       => array_values( array_unique( $providers_used ) ),
			'raw_count'       => count( $raw_urls ),
			'urls'            => $filtered,
			'discovered'      => ! empty( $filtered ),
			'error'           => empty( $raw_urls ) ? 'no_results' : ( empty( $filtered ) ? 'filtered_out' : '' ),
		);

		if ( ! $skip_cache ) {
			$ttl = empty( $result['urls'] ) ? 20 * MINUTE_IN_SECONDS : 3 * HOUR_IN_SECONDS;
			set_transient( $cache_key, $result, $ttl );
		}

		return $result;
	}

	/**
	 * Search configured official domains for pages matching the post title.
	 *
	 * @param WP_Post $post       Post object.
	 * @param array   $settings   Plugin settings.
	 * @param array   $cited_urls URLs cited in the post.
	 * @return array{domains: string[], urls: string[]}
	 */
	public static function discover_official_urls( $post, $settings, $cited_urls = array() ) {
		if ( 'yes' !== ( $settings['check_official_sites'] ?? 'yes' ) ) {
			return self::empty_domain_scan();
		}

		if ( 'yes' !== ( $settings['search_enabled'] ?? 'yes' ) ) {
			return self::empty_domain_scan();
		}

		$max_domains = max( 1, min( 150, (int) ( $settings['official_domains_per_post'] ?? 10 ) ) );
		$smart_scan  = 'yes' === ( $settings['smart_official_scan'] ?? 'yes' );
		$domains     = PIV_Sources::get_official_domains_for_post( $post, $max_domains, $cited_urls, $smart_scan );

		return self::discover_in_domain_list(
			$post,
			$settings,
			$domains,
			array(
				'per_domain'    => max( 1, min( 3, (int) ( $settings['official_search_max_results'] ?? 1 ) ) ),
				'collect_limit' => max( 1, min( 150, (int) ( $settings['official_url_collect_limit'] ?? 6 ) ) ),
				'cache_prefix'  => 'piv_official_',
			)
		);
	}

	/**
	 * Search configured gaming news domains for pages matching the post.
	 *
	 * @param WP_Post $post       Post object.
	 * @param array   $settings   Plugin settings.
	 * @param array   $cited_urls URLs cited in the post.
	 * @return array
	 */
	public static function discover_news_urls( $post, $settings, $cited_urls = array() ) {
		if ( 'yes' !== ( $settings['check_news_sites'] ?? 'yes' ) ) {
			return self::empty_domain_scan();
		}

		if ( 'yes' !== ( $settings['search_enabled'] ?? 'yes' ) ) {
			return self::empty_domain_scan();
		}

		$max_domains = max( 1, min( 150, (int) ( $settings['news_domains_per_post'] ?? 10 ) ) );
		$smart_scan  = 'yes' === ( $settings['smart_news_scan'] ?? 'yes' );
		$domains     = PIV_Sources::get_news_domains_for_post( $post, $max_domains, $cited_urls, $smart_scan );

		return self::discover_in_domain_list(
			$post,
			$settings,
			$domains,
			array(
				'per_domain'    => max( 1, min( 3, (int) ( $settings['news_search_max_results'] ?? 1 ) ) ),
				'collect_limit' => max( 1, min( 150, (int) ( $settings['news_url_collect_limit'] ?? 6 ) ) ),
				'cache_prefix'  => 'piv_news_',
			)
		);
	}

	/**
	 * @return array
	 */
	private static function empty_domain_scan() {
		return array(
			'domains'  => array(),
			'urls'     => array(),
			'scan_log' => array(),
		);
	}

	/**
	 * Search a list of domains for pages matching the post query.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 * @param array   $domains  Domains to scan.
	 * @param array   $options  Scan options.
	 * @return array
	 */
	public static function discover_in_domain_list( $post, $settings, $domains, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'per_domain'    => 1,
				'collect_limit' => 6,
				'chunk_size'    => 5,
				'cache_prefix'  => 'piv_scoped_',
			)
		);

		$domains = array_values( array_filter( (array) $domains ) );
		if ( empty( $domains ) ) {
			return self::empty_domain_scan();
		}

		$queries = self::build_search_queries( $post );
		if ( empty( $queries ) ) {
			$fallback = self::clean_query( wp_strip_all_tags( $post->post_title ) );
			if ( '' !== $fallback ) {
				$queries = array( $fallback );
			}
		}
		if ( empty( $queries ) ) {
			return array(
				'domains'  => $domains,
				'urls'     => array(),
				'scan_log' => array(),
			);
		}

		$full_scan     = ! empty( $settings['force_full_scan'] );
		$per_domain    = max( 1, min( 3, (int) $options['per_domain'] ) );
		$collect_limit = max( 1, min( 300, (int) $options['collect_limit'] ) );
		if ( $full_scan ) {
			// Always query every domain; collect_limit only caps stored URLs.
			$collect_limit = max( $collect_limit, count( $domains ) * $per_domain );
		}
		$chunk_size = max( 1, min( 5, (int) $options['chunk_size'] ) );
		$urls       = array();
		$scan_log   = array();

		foreach ( array_chunk( $domains, $chunk_size ) as $chunk ) {
			// Manual/full scan: never skip remaining domains.
			if ( ! $full_scan && count( $urls ) >= $collect_limit ) {
				break;
			}

			$remaining = max( 1, $collect_limit - count( $urls ) );
			$chunk_max = $full_scan
				? max( 1, $per_domain * count( $chunk ) )
				: min( $per_domain * count( $chunk ), $remaining );
			$found     = array();

			foreach ( $queries as $query ) {
				$batch = self::search_within_domains( $query, $chunk, max( $chunk_max, 4 ), $settings, (string) $options['cache_prefix'], $post );
				foreach ( $batch as $url ) {
					if ( ! in_array( $url, $found, true ) ) {
						$found[] = $url;
					}
				}
				if ( count( $found ) >= $chunk_max ) {
					break;
				}
			}

			foreach ( $chunk as $domain ) {
				$domain_urls = array();
				foreach ( $found as $url ) {
					$url_domain = PIV_Sources::domain_from_url( $url );
					if ( $url_domain && PIV_Sources::domain_in_list( $url_domain, array( $domain ) ) ) {
						$domain_urls[] = $url;
					}
				}

				$domain_urls = array_slice( $domain_urls, 0, $per_domain );

				$scan_log[ $domain ] = array(
					'scanned' => true,
					'found'   => count( $domain_urls ),
					'urls'    => $domain_urls,
				);

				foreach ( $domain_urls as $url ) {
					if ( ! in_array( $url, $urls, true ) ) {
						$urls[] = $url;
					}
				}
			}
		}

		return array(
			'domains'  => $domains,
			'urls'     => array_values( array_unique( $urls ) ),
			'scan_log' => $scan_log,
		);
	}

	/**
	 * Search inside a single official domain.
	 *
	 * @param string $query    Search terms.
	 * @param string $domain   Official domain.
	 * @param int    $max      Max URLs.
	 * @param array  $settings Plugin settings.
	 * @return array
	 */
	public static function search_within_domain( $query, $domain, $max, $settings, $post = null ) {
		$domain = PIV_Sources::normalize_domain( $domain );
		$query  = trim( (string) $query );
		if ( '' === $domain || '' === $query ) {
			return array();
		}

		$nonce     = isset( $settings['_cache_nonce'] ) ? (string) $settings['_cache_nonce'] : ( $post instanceof WP_Post ? PIV_Helpers::get_cache_nonce( (int) $post->ID ) : '' );
		$cache_key = 'piv_official_' . md5( $domain . '|' . $query . '|' . $max . '|' . $nonce );
		if ( $post instanceof WP_Post ) {
			$cache_key .= '|' . (int) $post->ID;
		}
		$skip_cache = ! empty( $settings['bypass_search_cache'] );
		if ( ! $skip_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$fetch_max                      = max( $max * 3, $max + 5 );
		$scoped                         = $settings;
		$scoped['search_site_restrict'] = $domain;
		$provider                       = (string) ( $settings['search_provider'] ?? 'auto' );
		$urls                           = array();

		if ( self::can_use_google( $settings ) && in_array( $provider, array( 'auto', 'google_cse' ), true ) ) {
			$urls = self::search_google_cse( $query, $fetch_max, $scoped );
		}

		if ( empty( $urls ) && self::can_use_bing( $settings ) && in_array( $provider, array( 'auto', 'bing' ), true ) ) {
			$urls = self::search_bing( $query, $fetch_max, $scoped );
		}

		if ( empty( $urls ) ) {
			$free = self::search_free_web( $query, $fetch_max, $domain );
			$urls = $free['urls'];
		}

		$urls = self::filter_article_urls_for_post(
			array_values( array_unique( $urls ) ),
			$post,
			$max,
			0.0,
			true
		);
		if ( ! $skip_cache ) {
			$ttl = empty( $urls ) ? 20 * MINUTE_IN_SECONDS : 3 * HOUR_IN_SECONDS;
			set_transient( $cache_key, $urls, $ttl );
		}

		return $urls;
	}

	/**
	 * Search inside multiple official domains in one API call (up to 5).
	 *
	 * @param string $query    Search terms.
	 * @param array  $domains  Official domains.
	 * @param int    $max      Max URLs total.
	 * @param array  $settings Plugin settings.
	 * @return array
	 */
	public static function search_within_domains( $query, $domains, $max, $settings, $cache_prefix = 'piv_official_', $post = null ) {
		$domains = array_values(
			array_filter(
				array_map(
					static function ( $domain ) {
						return PIV_Sources::normalize_domain( $domain );
					},
					(array) $domains
				)
			)
		);
		$query = trim( (string) $query );
		if ( empty( $domains ) || '' === $query || $max < 1 ) {
			return array();
		}

		$nonce     = isset( $settings['_cache_nonce'] ) ? (string) $settings['_cache_nonce'] : ( $post instanceof WP_Post ? PIV_Helpers::get_cache_nonce( (int) $post->ID ) : '' );
		$cache_key = $cache_prefix . md5( implode( '|', $domains ) . '|' . $query . '|' . $max . '|' . $nonce );
		if ( $post instanceof WP_Post ) {
			$cache_key .= '|' . (int) $post->ID;
		}
		$skip_cache = ! empty( $settings['bypass_search_cache'] );
		if ( ! $skip_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$fetch_max                      = max( $max * 3, $max + 8 );
		$scoped                         = $settings;
		$scoped['search_site_restrict'] = implode( ', ', array_slice( $domains, 0, 5 ) );
		$provider                       = (string) ( $settings['search_provider'] ?? 'auto' );
		$urls                           = array();

		if ( self::can_use_google( $settings ) && in_array( $provider, array( 'auto', 'google_cse' ), true ) ) {
			$urls = self::search_google_cse( $query, $fetch_max, $scoped );
		}

		if ( empty( $urls ) && self::can_use_bing( $settings ) && in_array( $provider, array( 'auto', 'bing' ), true ) ) {
			$urls = self::search_bing( $query, $fetch_max, $scoped );
		}

		if ( empty( $urls ) ) {
			foreach ( array_slice( $domains, 0, 5 ) as $domain ) {
				$free = self::search_free_web( $query, max( 2, (int) ceil( $fetch_max / max( 1, count( $domains ) ) ) ), $domain );
				foreach ( $free['urls'] as $url ) {
					if ( ! in_array( $url, $urls, true ) ) {
						$urls[] = $url;
					}
				}
				if ( count( $urls ) >= $fetch_max ) {
					break;
				}
			}
		}

		$urls = self::filter_article_urls_for_post(
			array_values( array_unique( $urls ) ),
			$post,
			$max,
			0.0,
			true
		);
		if ( ! $skip_cache ) {
			$ttl = empty( $urls ) ? 20 * MINUTE_IN_SECONDS : 3 * HOUR_IN_SECONDS;
			set_transient( $cache_key, $urls, $ttl );
		}

		return $urls;
	}

	/**
	 * Build a search query from post title and content.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function build_query( $post ) {
		$parts = array( wp_strip_all_tags( $post->post_title ) );

		$excerpt = trim( wp_strip_all_tags( $post->post_excerpt ) );
		if ( '' !== $excerpt ) {
			$parts[] = mb_substr( $excerpt, 0, 120, 'UTF-8' );
		} else {
			$content = trim( wp_strip_all_tags( $post->post_content ) );
			if ( '' !== $content ) {
				$parts[] = mb_substr( $content, 0, 120, 'UTF-8' );
			}
		}

		$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
		if ( ! empty( $tags ) ) {
			$parts[] = implode( ' ', array_slice( $tags, 0, 3 ) );
		}

		$query = self::clean_query( implode( ' ', $parts ) );
		$query = apply_filters( 'piv_search_query', $query, $post );

		return trim( $query );
	}

	/**
	 * Prefer Latin/English queries for foreign sites; keep Hebrew as fallback.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function build_search_queries( $post ) {
		$full  = self::clean_query( self::build_query( $post ) );
		$latin = self::build_latin_query( $post );
		$alias = self::build_alias_query( $post );

		$queries = array();
		foreach ( array( $latin, $alias, $full ) as $query ) {
			$query = trim( (string) $query );
			if ( '' === $query ) {
				continue;
			}
			if ( ! in_array( $query, $queries, true ) ) {
				$queries[] = $query;
			}
		}

		return apply_filters( 'piv_search_queries', $queries, $post );
	}

	/**
	 * Latin/game-name tokens for searching English news sites.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function build_latin_query( $post ) {
		$text = wp_strip_all_tags(
			$post->post_title . ' ' .
			$post->post_excerpt . ' ' .
			mb_substr( wp_strip_all_tags( $post->post_content ), 0, 400, 'UTF-8' )
		);

		$tokens = array();
		if ( preg_match_all( '/\b[a-zA-Z][a-zA-Z0-9]{2,}\b/u', $text, $matches ) ) {
			$tokens = array_merge( $tokens, $matches[0] );
		}
		if ( preg_match_all( '/\b\d{1,4}\b/u', $text, $matches ) ) {
			$tokens = array_merge( $tokens, $matches[0] );
		}

		$stop = array(
			'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'has', 'have',
			'http', 'https', 'www', 'com', 'html',
		);

		$out = array();
		foreach ( $tokens as $token ) {
			$token = strtolower( $token );
			if ( in_array( $token, $stop, true ) ) {
				continue;
			}
			if ( strlen( $token ) < 3 && ! preg_match( '/^\d+$/', $token ) ) {
				continue;
			}
			$out[] = $token;
		}

		$out = array_values( array_unique( $out ) );
		if ( empty( $out ) ) {
			return '';
		}

		return implode( ' ', array_slice( $out, 0, 10 ) );
	}

	/**
	 * Map common Hebrew gaming terms to English search keywords.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function build_alias_query( $post ) {
		$text = mb_strtolower(
			wp_strip_all_tags( $post->post_title . ' ' . $post->post_excerpt . ' ' . mb_substr( wp_strip_all_tags( $post->post_content ), 0, 400, 'UTF-8' ) ),
			'UTF-8'
		);

		$map = array(
			'פלייסטיישן'     => 'PlayStation',
			'פס5'            => 'PS5',
			'פס4'            => 'PS4',
			'אקסבוקס'        => 'Xbox',
			'נינטנדו'        => 'Nintendo',
			'סוויץ'          => 'Switch',
			'סטים'           => 'Steam',
			'אפיק'           => 'Epic Games',
			'פורטנייט'       => 'Fortnite',
			'מיינקראפט'      => 'Minecraft',
			'קול אוף דיוטי'   => 'Call of Duty',
			'קוד'            => 'Call of Duty',
			'גטה'            => 'GTA',
			'ויצ׳ר'          => 'Witcher',
			'ויצ\'ר'         => 'Witcher',
			'אלדן רינג'      => 'Elden Ring',
			'רזידנט איבל'    => 'Resident Evil',
			'סילנט היל'      => 'Silent Hill',
			'ספיידרמן'       => 'Spider-Man',
			'האלו'           => 'Halo',
			'אוברווטש'       => 'Overwatch',
			'ואלורנט'        => 'Valorant',
			'דיאבלו'         => 'Diablo',
			'וורזון'         => 'Warzone',
			'נדחה'           => 'delayed',
			'הכרזה'          => 'announced',
			'הוכרז'          => 'announced',
			'הכריזה'         => 'announced',
			'הכריז'          => 'announced',
			'טריילר'         => 'trailer',
			'עדכון'          => 'update',
			'דלף'            => 'leak',
			'דליפה'          => 'leak',
			'ביטול'          => 'cancelled',
			'בוטל'           => 'cancelled',
			'הושק'           => 'released',
			'יצא לאור'       => 'release',
			'תאריך יציאה'    => 'release date',
			'מחיר'           => 'price',
			'הנחה'           => 'sale discount',
		);

		$parts = array();
		foreach ( $map as $hebrew => $english ) {
			if ( '' === $english ) {
				continue;
			}
			if ( false !== mb_strpos( $text, $hebrew, 0, 'UTF-8' ) ) {
				$parts[] = $english;
			}
		}

		$latin = self::build_latin_query( $post );
		if ( '' !== $latin ) {
			$parts[] = $latin;
		}

		$parts = array_values( array_unique( array_filter( array_map( 'trim', $parts ) ) ) );
		if ( empty( $parts ) ) {
			return '';
		}

		return self::clean_query( implode( ' ', $parts ) );
	}

	/**
	 * Clean and shorten a search query.
	 *
	 * @param string $query Raw query.
	 * @return string
	 */
	public static function clean_query( $query ) {
		$query = html_entity_decode( (string) $query, ENT_QUOTES, 'UTF-8' );
		$query = preg_replace( '/\s+/', ' ', $query );
		$query = trim( $query );

		$stop_words = array(
			'של', 'על', 'את', 'זה', 'כי', 'גם', 'או', 'לא', 'כן', 'עם', 'אל', 'מן', 'כל',
			'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'has', 'have',
		);

		$words = preg_split( '/\s+/u', $query );
		$words = array_filter(
			$words,
			static function ( $word ) use ( $stop_words ) {
				$word = mb_strtolower( $word, 'UTF-8' );
				return mb_strlen( $word, 'UTF-8' ) > 2 && ! in_array( $word, $stop_words, true );
			}
		);

		$query = implode( ' ', array_slice( array_values( $words ), 0, 12 ) );

		return mb_substr( $query, 0, 180, 'UTF-8' );
	}

	/**
	 * @param string $query    Search query.
	 * @param array  $settings Plugin settings.
	 * @return string
	 */
	public static function apply_site_restrict( $query, $settings ) {
		$sites = isset( $settings['search_site_restrict'] ) ? trim( (string) $settings['search_site_restrict'] ) : '';
		if ( '' === $sites ) {
			return $query;
		}

		$domains = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $sites ) ) );
		if ( empty( $domains ) ) {
			return $query;
		}

		$site_parts = array();
		foreach ( array_slice( $domains, 0, 5 ) as $domain ) {
			$domain        = PIV_Sources::normalize_domain( $domain );
			$site_parts[]  = 'site:' . $domain;
		}

		return '(' . implode( ' OR ', $site_parts ) . ') ' . $query;
	}

	/**
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function can_use_google( $settings ) {
		return ! empty( $settings['google_api_key'] ) && ! empty( $settings['google_cx'] );
	}

	/**
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function can_use_bing( $settings ) {
		unset( $settings );
		// Microsoft retired the Bing Web Search API (August 2025) — the endpoint
		// no longer answers, so skip it instead of waiting on a dead request.
		return false;
	}

	/**
	 * Google Custom Search JSON API.
	 *
	 * @param string $query    Query.
	 * @param int    $max      Max results.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public static function search_google_cse( $query, $max, $settings ) {
		$query = self::apply_site_restrict( $query, $settings );

		$url = add_query_arg(
			array(
				'key' => $settings['google_api_key'],
				'cx'  => $settings['google_cx'],
				'q'   => $query,
				'num' => $max,
			),
			'https://www.googleapis.com/customsearch/v1'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return array();
		}

		$urls = array();
		foreach ( $data['items'] as $item ) {
			if ( empty( $item['link'] ) ) {
				continue;
			}
			$url = esc_url_raw( $item['link'] );
			$title = isset( $item['title'] ) ? (string) $item['title'] : '';
			if ( $url && PIV_Sources::is_external_url( $url ) && self::is_allowed_result_url( $url, $title ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Bing Web Search API v7.
	 *
	 * @param string $query    Query.
	 * @param int    $max      Max results.
	 * @param array  $settings Settings.
	 * @return array
	 */
	public static function search_bing( $query, $max, $settings ) {
		$query = self::apply_site_restrict( $query, $settings );

		$url = add_query_arg(
			array(
				'q'     => $query,
				'count' => $max,
				'mkt'   => 'he-IL',
			),
			'https://api.bing.microsoft.com/v7.0/search'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Ocp-Apim-Subscription-Key' => $settings['bing_api_key'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['webPages']['value'] ) || ! is_array( $data['webPages']['value'] ) ) {
			return array();
		}

		$urls = array();
		foreach ( $data['webPages']['value'] as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$url   = esc_url_raw( $item['url'] );
			$title = isset( $item['name'] ) ? (string) $item['name'] : '';
			if ( $url && PIV_Sources::is_external_url( $url ) && self::is_allowed_result_url( $url, $title ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Free web search stack (no API keys): DDG → Google News(+DDG resolve) → Bing HTML.
	 *
	 * @param string $query Query.
	 * @param int    $max   Max results.
	 * @param string $site  Optional site: domain restrict.
	 * @return array{urls:string[],provider:string}
	 */
	public static function search_free_web( $query, $max, $site = '' ) {
		$query = trim( (string) $query );
		$max   = max( 1, (int) $max );
		$site  = PIV_Sources::normalize_domain( $site );
		$q     = $site ? ( 'site:' . $site . ' ' . $query ) : $query;

		// DuckDuckGo HTML is the most reliable free provider right now.
		$urls = self::search_duckduckgo_html( $q, $max );
		if ( ! empty( $urls ) ) {
			return array(
				'urls'     => $urls,
				'provider' => 'duckduckgo',
			);
		}

		// Google News finds stories; publisher URLs are resolved via site: + DDG.
		$urls = self::search_google_news_rss( $query, $max, $site );
		if ( ! empty( $urls ) ) {
			return array(
				'urls'     => $urls,
				'provider' => 'google_news',
			);
		}

		$urls = self::search_bing_html( $q, $max );
		return array(
			'urls'     => $urls,
			'provider' => ! empty( $urls ) ? 'bing_html' : '',
		);
	}

	/**
	 * Google News RSS → resolve publisher article URLs via site-scoped DDG.
	 *
	 * @param string $query Query.
	 * @param int    $max   Max results.
	 * @param string $site  Optional domain filter.
	 * @return array
	 */
	public static function search_google_news_rss( $query, $max, $site = '' ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}

		$site = PIV_Sources::normalize_domain( $site );
		$q    = $site ? ( 'site:' . $site . ' ' . $query ) : $query;

		$response = wp_remote_get(
			add_query_arg(
				array(
					'q'    => $q,
					'hl'   => 'en-US',
					'gl'   => 'US',
					'ceid' => 'US:en',
				),
				'https://news.google.com/rss/search'
			),
			array(
				'timeout' => 12,
				'headers' => array(
					'User-Agent' => 'Mozilla/5.0 (compatible; ContentVerificationBadge/' . PIV_VERSION . ')',
					'Accept'     => 'application/rss+xml, application/xml, text/xml',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$xml = (string) wp_remote_retrieve_body( $response );
		if ( '' === $xml || false === stripos( $xml, '<item' ) ) {
			return array();
		}

		$urls  = array();
		$items = array();
		if ( preg_match_all( '#<item>(.*?)</item>#is', $xml, $matches ) ) {
			$items = $matches[1];
		}

		foreach ( array_slice( $items, 0, max( 6, $max * 2 ) ) as $item_xml ) {
			$title = '';
			if ( preg_match( '#<title><!\[CDATA\[(.*?)\]\]></title>#is', $item_xml, $m )
				|| preg_match( '#<title>(.*?)</title>#is', $item_xml, $m ) ) {
				$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
			}
			$source_domain = '';
			if ( preg_match( '#<source[^>]+url=["\'](https?://[^"\']+)["\']#i', $item_xml, $m ) ) {
				$source_domain = PIV_Sources::domain_from_url( $m[1] );
			}
			if ( $site && $source_domain && ! PIV_Sources::domain_in_list( $source_domain, array( $site ) ) ) {
				continue;
			}
			if ( '' === $title ) {
				continue;
			}

			// Prefer resolving the item's own <link> — no third-party search engine involved.
			$item_link = '';
			if ( preg_match( '#<link>\s*(?:<!\[CDATA\[)?\s*(https?://[^<\]\s]+)#i', $item_xml, $m ) ) {
				$item_link = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
			}
			$direct = self::resolve_google_news_url( $item_link );
			if ( '' !== $direct ) {
				$direct_domain = PIV_Sources::domain_from_url( $direct );
				$domain_ok     = ! $site || PIV_Sources::domain_in_list( $direct_domain, array( $site ) );
				if ( $domain_ok
					&& PIV_Sources::is_external_url( $direct )
					&& self::is_allowed_result_url( $direct, $title )
					&& ! in_array( $direct, $urls, true ) ) {
					$urls[] = $direct;
					if ( count( $urls ) >= $max ) {
						return $urls;
					}
					continue;
				}
			}

			// Titles look like: "Story headline - Publisher".
			$clean = preg_replace( '/\s+-\s+[^-]+$/u', '', $title );
			$clean = self::clean_query( $clean );
			if ( '' === $clean ) {
				continue;
			}

			$resolve_domain = $site ? $site : $source_domain;
			$resolve_query  = $resolve_domain ? ( 'site:' . $resolve_domain . ' ' . $clean ) : $clean;
			$resolved       = self::search_duckduckgo_html( $resolve_query, 2 );

			foreach ( $resolved as $url ) {
				if ( $resolve_domain ) {
					$url_domain = PIV_Sources::domain_from_url( $url );
					if ( ! PIV_Sources::domain_in_list( $url_domain, array( $resolve_domain ) ) ) {
						continue;
					}
				}
				if ( ! self::is_allowed_result_url( $url ) || ! PIV_Sources::is_article_url( $url ) ) {
					continue;
				}
				if ( ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
				if ( count( $urls ) >= $max ) {
					return $urls;
				}
			}
		}

		return $urls;
	}

	/**
	 * Resolve a Google News redirect URL to the publisher article URL.
	 *
	 * Tries, in order: non-Google URLs pass through; base64 payload decoding
	 * (older /rss/articles/CBMi... format embeds the target URL); HTTP
	 * redirect following. Returns '' when the target cannot be determined.
	 *
	 * @param string $url Google News item link.
	 * @return string
	 */
	public static function resolve_google_news_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}
		if ( false === strpos( $host, 'news.google.' ) ) {
			// RSS already gave the publisher URL directly.
			return esc_url_raw( $url );
		}

		$cache_key = 'piv_gnews_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$resolved = '';

		// 1) Older format: the article id is base64 and contains the target URL.
		if ( preg_match( '#/(?:rss/)?articles/([A-Za-z0-9_\-]+)#', $url, $m ) ) {
			$decoded = base64_decode( strtr( $m[1], '-_', '+/' ), false );
			if ( is_string( $decoded )
				&& preg_match_all( '#https?://[^\x00-\x20"\'\\\\]+#', $decoded, $mm ) ) {
				foreach ( $mm[0] as $candidate ) {
					$candidate_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
					if ( '' === $candidate_host || false !== strpos( $candidate_host, 'google.' ) ) {
						continue;
					}
					$resolved = esc_url_raw( $candidate );
					break;
				}
			}
		}

		// 2) Newer format: follow HTTP redirects to the publisher.
		if ( '' === $resolved ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 5,
					'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$final = '';
				if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) ) {
					$response_object = $response['http_response']->get_response_object();
					if ( $response_object && ! empty( $response_object->url ) ) {
						$final = (string) $response_object->url;
					}
				}
				$final_host = strtolower( (string) wp_parse_url( $final, PHP_URL_HOST ) );
				if ( '' !== $final && '' !== $final_host && false === strpos( $final_host, 'google.' ) ) {
					$resolved = esc_url_raw( $final );
				}
			}
		}

		set_transient( $cache_key, $resolved, '' === $resolved ? HOUR_IN_SECONDS : DAY_IN_SECONDS );

		return $resolved;
	}

	/**
	 * DuckDuckGo HTML results (no API key required).
	 *
	 * @param string $query Query.
	 * @param int    $max   Max results.
	 * @return array
	 */
	public static function search_duckduckgo_html( $query, $max ) {
		$headers = array(
			'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'Accept'     => 'text/html,application/xhtml+xml',
		);

		$response = wp_remote_post(
			'https://html.duckduckgo.com/html/',
			array(
				'timeout' => 12,
				'body'    => array(
					'q'  => $query,
					'kl' => 'wt-wt',
				),
				'headers' => $headers,
			)
		);

		$html = '';
		if ( ! is_wp_error( $response ) ) {
			$html = (string) wp_remote_retrieve_body( $response );
		}

		if ( '' === $html || false === strpos( $html, 'result__a' ) ) {
			$lite = wp_remote_get(
				add_query_arg( array( 'q' => $query ), 'https://lite.duckduckgo.com/lite/' ),
				array(
					'timeout' => 12,
					'headers' => $headers,
				)
			);
			if ( ! is_wp_error( $lite ) ) {
				$html = (string) wp_remote_retrieve_body( $lite );
			}
		}

		if ( '' === $html ) {
			return array();
		}

		$urls = array();

		if ( preg_match_all( '#(?:class="result__a"|rel="nofollow")[^>]*href="([^"]+)"#i', $html, $matches )
			|| preg_match_all( '#href="(https?://[^"]+)"#i', $html, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$url = self::decode_duckduckgo_href( $href );
				if ( ! $url || ! PIV_Sources::is_external_url( $url ) || ! self::is_allowed_result_url( $url ) ) {
					continue;
				}
				$host = (string) wp_parse_url( $url, PHP_URL_HOST );
				if ( preg_match( '/duckduckgo\.|google\.|yahoo\.|bing\./i', $host ) ) {
					continue;
				}
				if ( ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
				if ( count( $urls ) >= $max ) {
					break;
				}
			}
		}

		return $urls;
	}

	/**
	 * Bing HTML results (no API key).
	 *
	 * @param string $query Query.
	 * @param int    $max   Max results.
	 * @return array
	 */
	public static function search_bing_html( $query, $max ) {
		$url = add_query_arg(
			array(
				'q' => $query,
			),
			'https://www.bing.com/search'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					'Accept'     => 'text/html,application/xhtml+xml',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$html = (string) wp_remote_retrieve_body( $response );
		$urls = array();

		if ( preg_match_all( '#<li class="b_algo".*?<a[^>]+href="(https?://[^"]+)"#is', $html, $matches )
			|| preg_match_all( '#<h2>\s*<a[^>]+href="(https?://[^"]+)"#i', $html, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$url = esc_url_raw( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
				if ( ! $url || ! PIV_Sources::is_external_url( $url ) || ! self::is_allowed_result_url( $url ) ) {
					continue;
				}
				$host = (string) wp_parse_url( $url, PHP_URL_HOST );
				if ( preg_match( '/(?:bing\.|microsoft\.|msn\.)/i', $host ) ) {
					continue;
				}
				if ( ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
				if ( count( $urls ) >= $max ) {
					break;
				}
			}
		}

		return $urls;
	}

	/**
	 * Decode DuckDuckGo redirect URLs.
	 *
	 * @param string $href Raw href.
	 * @return string
	 */
	public static function decode_duckduckgo_href( $href ) {
		$href = html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' );

		if ( preg_match( '/uddg=([^&]+)/', $href, $m ) ) {
			return esc_url_raw( urldecode( $m[1] ) );
		}

		if ( 0 === strpos( $href, '//' ) ) {
			$href = 'https:' . $href;
		}

		return esc_url_raw( $href );
	}

	/**
	 * Filter search hits down to article-like URLs relevant to the post.
	 *
	 * @param array   $urls      Candidate URLs.
	 * @param WP_Post $post      Post object.
	 * @param int     $max       Max URLs to return.
	 * @param float   $min_score Minimum relevance score (0-1).
	 * @return array
	 */
	public static function filter_article_urls_for_post( $urls, $post, $max, $min_score = 0.06, $soft = false ) {
		unset( $soft );
		return PIV_Match::filter_discovery_urls( $urls, $post, $max, $min_score );
	}

	/**
	 * Score how well a URL path/title matches the post query.
	 *
	 * @param string $url        URL.
	 * @param string $query_text Post search text.
	 * @param string $title      Optional result title.
	 * @return float
	 */
	public static function score_url_for_post( $url, $query_text, $title = '' ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = str_replace( array( '-', '_', '/' ), ' ', $path );
		$text = trim( $path . ' ' . (string) $title );

		if ( '' === trim( $query_text ) || '' === trim( $text ) ) {
			return 0.0;
		}

		return PIV_Verifier::key_token_similarity( $query_text, $text, $url );
	}

	/**
	 * Filter out social / search-engine noise from results.
	 *
	 * @param string $url    Result URL.
	 * @param string $title  Optional result title.
	 * @return bool
	 */
	public static function is_allowed_result_url( $url, $title = '' ) {
		$domain = PIV_Sources::domain_from_url( $url );
		if ( '' === $domain ) {
			return false;
		}

		if ( ! PIV_Sources::is_article_url( $url ) ) {
			return false;
		}

		$blocked = array(
			'duckduckgo.com',
			'google.com',
			'bing.com',
			'facebook.com',
			'instagram.com',
			'pinterest.com',
			'tiktok.com',
			'reddit.com',
			'youtube.com',
			'twitter.com',
			'x.com',
			'linkedin.com',
		);

		foreach ( $blocked as $block ) {
			if ( PIV_Sources::domain_in_list( $domain, array( $block ) ) ) {
				return false;
			}
		}

		if ( '' !== $title ) {
			$generic_titles = array( 'home', 'news', 'blog', 'games', 'gaming', 'store', 'support', 'about' );
			$normalized     = strtolower( trim( wp_strip_all_tags( $title ) ) );
			if ( in_array( $normalized, $generic_titles, true ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'piv_search_allow_url', true, $url, $domain );
	}

	/**
	 * @param string $query Search query.
	 * @param string $reason Reason code.
	 * @return array
	 */
	private static function empty_result( $query, $reason ) {
		return array(
			'query'      => $query,
			'provider'   => '',
			'urls'       => array(),
			'discovered' => false,
			'error'      => $reason,
		);
	}
}
