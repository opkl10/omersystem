<?php
/**
 * RSS feed matching for verification.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_RSS {

	const OPTION_FEEDS = 'piv_rss_feeds';

	/**
	 * Default gaming news RSS feeds.
	 *
	 * @return array
	 */
	public static function default_feeds() {
		return array(
			'https://feeds.feedburner.com/ign/all',
			'https://www.eurogamer.net/feed',
			'https://www.gamespot.com/feeds/news/',
			'https://www.pcgamer.com/rss/',
			'https://www.vg247.com/feed/',
			'https://www.rockpapershotgun.com/feed',
			'https://www.gamesradar.com/rss/',
			'https://kotaku.com/rss',
			'https://www.polygon.com/rss/index.xml',
			'https://www.theverge.com/rss/games/index.xml',
			'https://www.dualshockers.com/feed/',
			'https://wccftech.com/feed/',
			'https://www.gematsu.com/feed',
			'https://www.pushsquare.com/feeds/latest',
			'https://www.nintendolife.com/feeds/latest',
			'https://www.purexbox.com/feeds/latest',
			'https://www.destructoid.com/feed/',
			'https://www.shacknews.com/feed',
			'https://www.siliconera.com/feed/',
			'https://www.gamesindustry.biz/feed/news',
			'https://il.ign.com/feed.xml',
		);
	}

	/**
	 * Saved feed list (or defaults).
	 *
	 * @return array
	 */
	public static function get_feeds() {
		$saved = get_option( self::OPTION_FEEDS, array() );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			$saved = self::default_feeds();
		}

		return apply_filters( 'piv_rss_feeds', self::normalize_feed_list( $saved ) );
	}

	/**
	 * @param array $feeds Raw feed URLs.
	 * @return array
	 */
	public static function normalize_feed_list( $feeds ) {
		$out = array();
		foreach ( (array) $feeds as $feed ) {
			$feed = esc_url_raw( trim( (string) $feed ) );
			if ( $feed && preg_match( '#^https?://#i', $feed ) ) {
				$out[] = $feed;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Discover article URLs from RSS feeds that match the post.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 * @return array{feeds:string[],urls:string[],items:array,scan_log:array}
	 */
	public static function discover_for_post( $post, $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = PIV_Helpers::get_settings();
		}

		if ( 'yes' !== ( $settings['check_rss'] ?? 'yes' ) ) {
			return self::empty_result();
		}

		$feeds = self::get_feeds();
		if ( empty( $feeds ) ) {
			return self::empty_result();
		}

		$full_scan = ! empty( $settings['force_full_scan'] );
		$max_feeds = max( 1, min( 80, (int) ( $settings['rss_feeds_per_post'] ?? 20 ) ) );
		if ( $full_scan ) {
			$max_feeds = max( $max_feeds, count( $feeds ) );
		}
		$max_items = max( 5, min( 40, (int) ( $settings['rss_max_items_per_feed'] ?? 15 ) ) );
		// Soft threshold for discovery — full content match happens after fetch.
		$min_score = max( 0.1, (float) ( $settings['rss_match_threshold'] ?? 0.14 ) );
		$collect   = max( 1, min( 80, (int) ( $settings['rss_url_collect_limit'] ?? 8 ) ) );
		if ( $full_scan ) {
			$collect = max( $collect, count( $feeds ) * 2 );
		}

		if ( ! empty( $settings['bypass_search_cache'] ) ) {
			$cache_key = '';
		} else {
			$nonce     = isset( $settings['_cache_nonce'] ) ? (string) $settings['_cache_nonce'] : PIV_Helpers::get_cache_nonce( (int) $post->ID );
			$cache_key = 'piv_rss_' . md5( (int) $post->ID . '|' . $nonce . '|' . wp_json_encode( $feeds ) . '|' . $max_items . '|' . $min_score );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! function_exists( 'fetch_feed' ) ) {
			include_once ABSPATH . WPINC . '/feed.php';
		}

		$post_text = wp_strip_all_tags( $post->post_title . ' ' . $post->post_excerpt );
		$urls      = array();
		$items     = array();
		$scan_log  = array();
		$used      = array_slice( $feeds, 0, $max_feeds );

		foreach ( $used as $feed_url ) {
			// Full/manual scan visits every feed; collect only caps how many matches we keep.
			if ( ! $full_scan && count( $urls ) >= $collect ) {
				break;
			}

			$matched = array();
			$feed    = fetch_feed( $feed_url );

			if ( is_wp_error( $feed ) ) {
				$scan_log[ $feed_url ] = array(
					'scanned' => false,
					'found'   => 0,
					'error'   => $feed->get_error_message(),
				);
				continue;
			}

			$count = $feed->get_item_quantity( $max_items );
			$list  = $feed->get_items( 0, $count );

			foreach ( (array) $list as $item ) {
				$link  = esc_url_raw( (string) $item->get_permalink() );
				$title = trim( wp_strip_all_tags( (string) $item->get_title() ) );
				if ( ! $link || '' === $title ) {
					continue;
				}

				if ( ! PIV_Sources::is_external_url( $link ) ) {
					continue;
				}

				$key_score   = PIV_Verifier::key_token_similarity( $post_text, $title, $link );
				$title_score = PIV_Verifier::text_similarity(
					PIV_Verifier::normalize_text( $post_text ),
					PIV_Verifier::normalize_text( $title )
				);
				$score = max( (float) $key_score, (float) $title_score );

				// Also accept when at least one distinctive title token appears in the RSS title.
				$title_tokens = PIV_Verifier::get_distinctive_title_tokens( $post );
				$src_tokens   = PIV_Verifier::expand_language_aliases( PIV_Verifier::extract_key_tokens( $title, $link ) );
				$title_hits   = array_values( array_intersect( $title_tokens, $src_tokens ) );
				if ( $score < $min_score && count( $title_hits ) < 1 ) {
					continue;
				}
				if ( count( $title_hits ) >= 1 ) {
					$score = max( $score, 0.16 );
				}

				$matched[] = array(
					'url'   => $link,
					'title' => $title,
					'score' => $score,
					'feed'  => $feed_url,
				);
			}

			usort(
				$matched,
				static function ( $a, $b ) {
					return $b['score'] <=> $a['score'];
				}
			);

			$matched = array_slice( $matched, 0, 2 );
			$scan_log[ $feed_url ] = array(
				'scanned' => true,
				'found'   => count( $matched ),
				'urls'    => wp_list_pluck( $matched, 'url' ),
			);

			foreach ( $matched as $row ) {
				if ( in_array( $row['url'], $urls, true ) ) {
					continue;
				}
				if ( count( $urls ) >= $collect ) {
					break;
				}
				$urls[]  = $row['url'];
				$items[] = $row;
			}
		}

		$result = array(
			'feeds'    => $used,
			'urls'     => $urls,
			'items'    => $items,
			'scan_log' => $scan_log,
		);

		if ( ! empty( $cache_key ) ) {
			$ttl = empty( $urls ) ? 20 * MINUTE_IN_SECONDS : 90 * MINUTE_IN_SECONDS;
			set_transient( $cache_key, $result, $ttl );
		}

		return $result;
	}

	/**
	 * @return array
	 */
	private static function empty_result() {
		return array(
			'feeds'    => array(),
			'urls'     => array(),
			'items'    => array(),
			'scan_log' => array(),
		);
	}
}
