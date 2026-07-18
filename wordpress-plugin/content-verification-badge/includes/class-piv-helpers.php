<?php
/**
 * Display helpers and settings accessors.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Helpers {

	const OPTION_SETTINGS = 'piv_settings';

	/**
	 * Nested display contexts where badges must stay hidden.
	 *
	 * @var int
	 */
	private static $badge_suppress_depth = 0;

	/**
	 * Post types that get automatic verification.
	 *
	 * @return array
	 */
	public static function post_types() {
		return apply_filters( 'piv_post_types', array( 'post' ) );
	}

	/**
	 * Plugin settings with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'review_window'       => 15,
			'match_verified'      => 0.55,
			'match_verified_official' => 0.25,
			'match_verified_news' => 0.32,
			'match_review'        => 0.45,
			'recheck_hours'       => 24,
			'queue_batch_size'    => 5,
			'max_source_fetches'  => 6,
			'verify_on_view'      => 'no',
			'show_evidence'       => 'yes',
			'show_timestamp'      => 'yes',
			'search_enabled'      => 'yes',
			'search_provider'     => 'auto',
			'search_max_results'  => 6,
			'check_official_sites' => 'yes',
			'official_domains_per_post' => 18,
			'official_search_max_results' => 2,
			'max_official_fetches' => 14,
			'smart_official_scan' => 'yes',
			'official_url_collect_limit' => 14,
			'check_news_sites' => 'yes',
			'news_domains_per_post' => 18,
			'news_search_max_results' => 2,
			'max_news_fetches' => 12,
			'smart_news_scan' => 'yes',
			'news_url_collect_limit' => 14,
			'check_rss' => 'yes',
			'rss_feeds_per_post' => 20,
			'rss_max_items_per_feed' => 15,
			'rss_match_threshold' => 0.14,
			'rss_url_collect_limit' => 8,
			'allow_manual_override' => 'yes',
			'gemini_enabled'      => 'no',
			'gemini_api_key'      => '',
			'gemini_model'        => 'gemini-3.1-pro-preview',
			'gemini_min_confidence' => 0.72,
			'gemini_max_compares' => 8,
			'gemini_required'     => 'no',
			'google_api_key'      => '',
			'google_cx'           => '',
			'bing_api_key'        => '',
			'search_site_restrict' => '',
			'show_on_title'        => 'yes',
			'show_in_content'      => 'no',
			'included_categories'        => '',
			'excluded_categories'        => '',
			'excluded_category_keywords' => 'ביקורת,ביקורות,סקירה,סקירות,דעה,review,reviews,opinion',
			'excluded_display_widgets'   => 'category_squares,posts,archive-posts,loop-grid,loop-carousel,portfolio,related-posts,wp-widget-recent-posts',
			'exclude_review_box_posts'   => 'yes',
			'exclude_review_like_posts'  => 'yes',
		);

		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Lower outdated high verification thresholds that blocked "מידע מאומת".
	 * Also expands exclusion keywords so old reviews are skipped.
	 */
	public static function maybe_migrate_verification_thresholds() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return;
		}

		$changed = false;
		$engine  = (string) get_option( 'piv_match_engine_version', '0' );

		// 1.11 rebuild: Gemini-required was zeroing matches on API errors — turn off once.
		if ( version_compare( $engine, '1.11.0', '<' ) ) {
			if ( 'yes' === ( $saved['gemini_required'] ?? 'no' ) ) {
				$saved['gemini_required'] = 'no';
				$changed                  = true;
			}
			update_option( 'piv_match_engine_version', '1.11.0', false );
		}

		if ( isset( $saved['match_verified'] ) && (float) $saved['match_verified'] >= 0.85 ) {
			$saved['match_verified'] = 0.55;
			$changed = true;
		}
		if ( isset( $saved['match_verified_official'] ) && (float) $saved['match_verified_official'] >= 0.34 ) {
			$saved['match_verified_official'] = 0.25;
			$changed = true;
		}
		if ( ! isset( $saved['match_verified_news'] ) ) {
			$saved['match_verified_news'] = 0.32;
			$changed = true;
		}
		if ( isset( $saved['match_review'] ) && (float) $saved['match_review'] >= 0.55 ) {
			$saved['match_review'] = 0.45;
			$changed = true;
		}

		// Raise older tight discovery caps so background scans find more sources.
		$raise = array(
			'search_max_results'           => 6,
			'official_domains_per_post'    => 18,
			'news_domains_per_post'        => 18,
			'official_search_max_results'  => 2,
			'news_search_max_results'      => 2,
			'max_official_fetches'         => 14,
			'max_news_fetches'             => 12,
			'max_source_fetches'           => 6,
			'official_url_collect_limit'   => 14,
			'news_url_collect_limit'       => 14,
		);
		foreach ( $raise as $key => $min ) {
			$current = isset( $saved[ $key ] ) ? (int) $saved[ $key ] : 0;
			if ( $current > 0 && $current < $min ) {
				$saved[ $key ] = $min;
				$changed       = true;
			}
		}

		// Ensure review-related exclusion keywords exist even on older saved settings.
		$keyword_extras   = array( 'ביקורות', 'סקירה', 'סקירות', 'reviews' );
		$raw_keywords     = isset( $saved['excluded_category_keywords'] )
			? (string) $saved['excluded_category_keywords']
			: '';
		$parts            = preg_split( '/[\s,]+/u', $raw_keywords );
		$parts            = array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
		$keywords_changed = false;

		if ( empty( $parts ) ) {
			$parts = array( 'ביקורת', 'ביקורות', 'סקירה', 'סקירות', 'דעה', 'review', 'reviews', 'opinion' );
			$saved['excluded_category_keywords'] = implode( ',', $parts );
			$keywords_changed = true;
			$changed          = true;
		} else {
			$lower = array_map(
				static function ( $item ) {
					return mb_strtolower( $item, 'UTF-8' );
				},
				$parts
			);
			foreach ( $keyword_extras as $extra ) {
				if ( ! in_array( mb_strtolower( $extra, 'UTF-8' ), $lower, true ) ) {
					$parts[]          = $extra;
					$keywords_changed = true;
					$changed          = true;
				}
			}
			if ( $keywords_changed ) {
				$saved['excluded_category_keywords'] = implode( ',', $parts );
			}
		}

		$should_purge = false;
		if ( ! isset( $saved['exclude_review_like_posts'] ) ) {
			$saved['exclude_review_like_posts'] = 'yes';
			$changed      = true;
			$should_purge = true;
		}

		if ( $changed ) {
			update_option( self::OPTION_SETTINGS, $saved );
		}

		// Clear badges from old reviews after stronger exclusion rules are introduced.
		if ( $should_purge || $keywords_changed ) {
			self::purge_verification_for_excluded_posts();
		}
	}

	/**
	 * Exact category slugs/names that skip verification (optional).
	 *
	 * @return array
	 */
	public static function get_excluded_categories() {
		$settings = self::get_settings();
		$raw      = isset( $settings['excluded_categories'] ) ? (string) $settings['excluded_categories'] : '';
		$parts    = preg_split( '/[\s,]+/u', $raw );
		$parts    = array_filter( array_map( 'trim', (array) $parts ) );

		return apply_filters( 'piv_excluded_categories', array_values( array_unique( $parts ) ) );
	}

	/**
	 * Keywords — any category whose name/slug contains one of these is excluded.
	 *
	 * @return array
	 */
	public static function get_excluded_category_keywords() {
		$settings = self::get_settings();
		$raw      = isset( $settings['excluded_category_keywords'] )
			? (string) $settings['excluded_category_keywords']
			: 'ביקורת,דעה,review,opinion';

		$parts = preg_split( '/[\s,]+/u', $raw );
		$parts = array_filter( array_map( 'trim', (array) $parts ) );

		if ( empty( $parts ) ) {
			$parts = array( 'ביקורת', 'דעה', 'review', 'opinion' );
		}

		return apply_filters( 'piv_excluded_category_keywords', array_values( array_unique( $parts ) ) );
	}

	/**
	 * Optional category slugs/names that limit verification scope.
	 * Empty = all published news posts (except exclusions).
	 *
	 * @return array
	 */
	public static function get_included_categories() {
		$settings = self::get_settings();
		$raw      = isset( $settings['included_categories'] ) ? (string) $settings['included_categories'] : '';
		$parts    = preg_split( '/[\s,]+/u', $raw );
		$parts    = array_filter( array_map( 'trim', (array) $parts ) );

		return apply_filters( 'piv_included_categories', array_values( array_unique( $parts ) ) );
	}

	/**
	 * Whether a post is inside the configured verification scope.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_in_verification_scope( $post_id ) {
		$included = self::get_included_categories();
		if ( empty( $included ) ) {
			return true;
		}

		$terms = wp_get_post_terms( $post_id, 'category' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}

		foreach ( $terms as $term ) {
			$slug = mb_strtolower( $term->slug, 'UTF-8' );
			$name = mb_strtolower( $term->name, 'UTF-8' );

			foreach ( $included as $needle ) {
				$needle = mb_strtolower( $needle, 'UTF-8' );
				if ( '' === $needle ) {
					continue;
				}
				if ( $slug === $needle || $name === $needle || false !== mb_strpos( $name, $needle, 0, 'UTF-8' ) ) {
					return true;
				}
			}
		}

		return (bool) apply_filters( 'piv_post_in_verification_scope', false, $post_id, $included );
	}

	/**
	 * Whether a published post should enter the verification pipeline.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_eligible_for_verification( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		if ( ! in_array( get_post_type( $post_id ), self::post_types(), true ) ) {
			return false;
		}

		if ( 'publish' !== get_post_status( $post_id ) ) {
			return false;
		}

		if ( self::is_excluded_post( $post_id ) ) {
			return false;
		}

		if ( ! self::is_in_verification_scope( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Count published posts in verification scope.
	 *
	 * @return array{total:int,eligible:int,verified:int,unverified:int,queue:int}
	 */
	public static function get_verification_stats( $force_refresh = false ) {
		if ( $force_refresh ) {
			self::invalidate_verification_stats_cache();
		}

		$cached = get_transient( 'piv_verification_stats' );
		if ( is_array( $cached ) ) {
			$cached['queue'] = PIV_Queue::queue_count();
			return $cached;
		}

		$stats = array(
			'total'      => 0,
			'eligible'   => 0,
			'verified'   => 0,
			'unverified' => 0,
			'queue'      => PIV_Queue::queue_count(),
		);

		$paged    = 1;
		$per_page = 100;

		do {
			$query = new WP_Query(
				array(
					'post_type'              => self::post_types(),
					'post_status'            => 'publish',
					'posts_per_page'         => $per_page,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'no_found_rows'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post_id ) {
				$stats['total']++;
				if ( ! self::is_eligible_for_verification( $post_id ) ) {
					continue;
				}

				$stats['eligible']++;
				if ( self::get_layer( $post_id ) ) {
					$stats['verified']++;
				} else {
					$stats['unverified']++;
				}
			}

			$paged++;
		} while ( $paged <= (int) $query->max_num_pages );

		set_transient( 'piv_verification_stats', $stats, 2 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Clear cached coverage stats (after verification or queue changes).
	 */
	public static function invalidate_verification_stats_cache() {
		delete_transient( 'piv_verification_stats' );
	}

	/**
	 * Coverage stats with computed progress percent for live UI.
	 *
	 * @param bool $force_refresh Skip cached counts.
	 * @return array
	 */
	public static function get_coverage_payload( $force_refresh = false ) {
		$stats   = self::get_verification_stats( $force_refresh );
		$percent = 0;

		if ( ! empty( $stats['eligible'] ) ) {
			$percent = (int) min( 100, round( ( $stats['verified'] / $stats['eligible'] ) * 100 ) );
		}

		return array(
			'stats'   => $stats,
			'percent' => $percent,
			'active'  => ! empty( $stats['queue'] ) || ! empty( $stats['unverified'] ),
		);
	}

	/**
	 * Adjust limits by verification context.
	 *
	 * Manual/precision: scan every configured official + news domain.
	 * Precision: deepest scan — more results per domain, longer fetches, strict match.
	 * Background/queue keeps the normal/smart limits from settings.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $context  manual|precision|background.
	 * @return array
	 */
	public static function apply_verification_context( $settings, $context ) {
		if ( ! in_array( $context, array( 'manual', 'precision' ), true ) ) {
			return $settings;
		}

		$official_count = count( PIV_Sources::get_primary_domains() );
		$news_count     = count( PIV_Sources::get_news_domains() );
		$rss_count      = count( PIV_RSS::get_feeds() );
		$precision      = ( 'precision' === $context );

		$settings['force_full_scan']             = true;
		$settings['smart_official_scan']         = 'no';
		$settings['smart_news_scan']             = 'no';
		$settings['official_domains_per_post']   = max( 1, $official_count );
		$settings['news_domains_per_post']       = max( 1, $news_count );
		$settings['official_search_max_results'] = $precision ? 3 : 2;
		$settings['news_search_max_results']     = $precision ? 3 : 2;
		// High enough that we never stop mid-list before every domain is queried.
		$settings['official_url_collect_limit']  = max( $official_count * ( $precision ? 3 : 2 ), 50 );
		$settings['news_url_collect_limit']      = max( $news_count * ( $precision ? 3 : 2 ), 100 );
		$settings['max_official_fetches']        = max( $official_count, $precision ? 50 : 30 );
		$settings['max_news_fetches']            = max( $news_count, $precision ? 60 : 40 );
		$settings['max_source_fetches']          = max( $precision ? 12 : 8, (int) ( $settings['max_source_fetches'] ?? 8 ) );
		$settings['search_max_results']          = max( $precision ? 12 : 8, (int) ( $settings['search_max_results'] ?? 8 ) );
		$settings['bypass_search_cache']         = true;
		$settings['bypass_fetch_cache']          = true;
		$settings['strict_relevance']            = $precision;
		$settings['fetch_timeout']               = $precision ? 12 : 8;
		$settings['precision_mode']              = $precision;
		if ( $precision && PIV_Gemini::is_enabled( $settings ) ) {
			// More Gemini compares on precision — but never required (API errors must not zero-out matches).
			$settings['gemini_max_compares'] = max( 12, (int) ( $settings['gemini_max_compares'] ?? 8 ) );
		}
		$settings['check_rss']                   = 'yes';
		$settings['rss_feeds_per_post']          = max( 1, $rss_count );
		$settings['rss_url_collect_limit']       = max( $rss_count * 2, 40 );
		$settings['rss_max_items_per_feed']      = max( $precision ? 25 : 15, (int) ( $settings['rss_max_items_per_feed'] ?? 15 ) );

		return $settings;
	}

	/**
	 * Queue eligible posts for background verification.
	 *
	 * @param array $args {
	 *     @type bool $only_unverified Only posts without a stored layer.
	 *     @type int  $limit           Max posts to enqueue. 0 = no limit.
	 *     @type bool $priority        Prepend to queue.
	 * }
	 * @return int Number of posts enqueued.
	 */
	public static function enqueue_eligible_posts( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'only_unverified' => true,
				'limit'           => 0,
				'priority'        => false,
			)
		);

		$enqueued   = 0;
		$paged      = 1;
		$per_page   = 100;
		$meta_query = array();
		$to_enqueue = array();

		if ( $args['only_unverified'] ) {
			$meta_query = array(
				'relation' => 'OR',
				array(
					'key'     => PIV_META_LAYER,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => PIV_META_LAYER,
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		do {
			$query_args = array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $paged,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( ! empty( $meta_query ) ) {
				$query_args['meta_query'] = $meta_query;
			}

			$query = new WP_Query( $query_args );
			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post_id ) {
				if ( ! self::is_eligible_for_verification( $post_id ) ) {
					continue;
				}

				$to_enqueue[] = $post_id;

				if ( $args['limit'] > 0 && count( $to_enqueue ) >= (int) $args['limit'] ) {
					break 2;
				}
			}

			$paged++;
		} while ( $paged <= (int) $query->max_num_pages );

		if ( ! empty( $to_enqueue ) ) {
			$enqueued = PIV_Queue::enqueue_many( $to_enqueue, (bool) $args['priority'] );
		}

		return $enqueued;
	}

	/**
	 * Whether a category term matches exclusion rules.
	 *
	 * @param WP_Term $term Category term.
	 * @return bool
	 */
	public static function is_excluded_category_term( $term ) {
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		$slug = mb_strtolower( $term->slug, 'UTF-8' );
		$name = mb_strtolower( $term->name, 'UTF-8' );

		foreach ( self::get_excluded_categories() as $exact ) {
			$exact = mb_strtolower( $exact, 'UTF-8' );
			if ( $slug === $exact || $name === $exact ) {
				return true;
			}
		}

		foreach ( self::get_excluded_category_keywords() as $keyword ) {
			$keyword = mb_strtolower( $keyword, 'UTF-8' );
			if ( '' === $keyword ) {
				continue;
			}
			if ( false !== mb_strpos( $name, $keyword, 0, 'UTF-8' ) || false !== mb_strpos( $slug, $keyword, 0, 'UTF-8' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Post meta keys that mark a post as excluded when truthy.
	 *
	 * @return array
	 */
	public static function get_excluded_post_meta_keys() {
		$keys = array( 'grp_enable', 'grp_enabled', 'enable_review_box', 'review_box_enable' );

		return apply_filters( 'piv_excluded_post_meta_keys', $keys );
	}

	/**
	 * Meta keys whose mere presence (with a non-empty value) marks a review post.
	 * Useful for old Game Reviews Pro posts without an enable flag.
	 *
	 * @return array
	 */
	public static function get_excluded_review_score_meta_keys() {
		$keys = array(
			'grp_score',
			'grp_final_score',
			'grp_rating',
			'_grp_score',
			'_grp_final_score',
			'game_review_score',
			'review_score',
		);

		return apply_filters( 'piv_excluded_review_score_meta_keys', $keys );
	}

	/**
	 * Whether a meta value should count as "enabled".
	 *
	 * @param mixed $value Meta value.
	 * @return bool
	 */
	public static function is_truthy_meta_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'yes', 'true', 'on', 'enabled' ), true );
	}

	/**
	 * Taxonomies scanned for excluded category/tag names.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_exclusion_taxonomies( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return array();
		}

		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		$taxonomies = array_filter( (array) $taxonomies );

		return apply_filters( 'piv_exclusion_taxonomies', array_values( $taxonomies ), $post_id, $post_type );
	}

	/**
	 * Whether a post is excluded via review-box or other meta flags.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded_by_post_meta( $post_id ) {
		$settings = self::get_settings();
		if ( 'yes' !== ( $settings['exclude_review_box_posts'] ?? 'yes' ) ) {
			return false;
		}

		foreach ( self::get_excluded_post_meta_keys() as $meta_key ) {
			if ( self::is_truthy_meta_value( get_post_meta( $post_id, $meta_key, true ) ) ) {
				return true;
			}
		}

		foreach ( self::get_excluded_review_score_meta_keys() as $meta_key ) {
			$score = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== (string) $score && null !== $score ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether title/slug clearly mark the post as a review (incl. very old posts).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded_by_review_signal( $post_id ) {
		$settings = self::get_settings();
		if ( 'yes' !== ( $settings['exclude_review_like_posts'] ?? 'yes' ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$title = (string) $post->post_title;
		$slug  = (string) $post->post_name;

		// Strict title patterns — avoid catching news that merely mentions reviews.
		$title_patterns = array(
			'/^\s*\[?\s*ביקורת/u',
			'/ביקורת\s*[:|\-–—]/u',
			'/^\s*\[?\s*סקירה/u',
			'/סקירה\s*[:|\-–—]/u',
			'/^\s*\[?\s*review\b/i',
			'/\breview\s*[:|\-–—]/i',
		);

		foreach ( $title_patterns as $pattern ) {
			if ( preg_match( $pattern, $title ) ) {
				return true;
			}
		}

		$slug_patterns = array(
			'/(^|[-_\/])reviews?([-_\/]|$)/i',
			'/(^|[-_])biqoret([-_]|$)/i',
			'/(^|[-_])skira([-_]|$)/i',
			'/ביקורת/u',
			'/סקירה/u',
		);

		foreach ( $slug_patterns as $pattern ) {
			if ( preg_match( $pattern, $slug ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'piv_exclude_review_like_post', false, $post_id, $post );
	}

	/**
	 * Whether a post belongs to an excluded category (ביקורת / דעה).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		if ( apply_filters( 'piv_exclude_post', false, $post_id ) ) {
			return true;
		}

		if ( self::is_excluded_by_post_meta( $post_id ) ) {
			return true;
		}

		if ( self::is_excluded_by_review_signal( $post_id ) ) {
			return true;
		}

		foreach ( self::get_exclusion_taxonomies( $post_id ) as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( self::is_excluded_category_term( $term ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Clear verification data from posts that should never be checked.
	 *
	 * @param int $limit Max posts per run. 0 = no limit.
	 * @return int Number of posts cleared.
	 */
	public static function purge_verification_for_excluded_posts( $limit = 0 ) {
		$args = array(
			'post_type'              => self::post_types(),
			'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'         => $limit > 0 ? $limit : -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
			'meta_query'             => array(
				array(
					'key'     => PIV_META_LAYER,
					'compare' => 'EXISTS',
				),
			),
		);

		$query   = new WP_Query( $args );
		$cleared = 0;

		foreach ( $query->posts as $post_id ) {
			if ( ! self::is_excluded_post( $post_id ) ) {
				continue;
			}

			self::clear_verification_meta( $post_id );
			$cleared++;
		}

		return $cleared;
	}

	/**
	 * Remove stored verification data for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function clear_verification_meta( $post_id ) {
		delete_post_meta( $post_id, PIV_META_LAYER );
		delete_post_meta( $post_id, PIV_META_CHECKED_AT );
		delete_post_meta( $post_id, PIV_META_RESULT );
		delete_post_meta( $post_id, PIV_META_STATUS );
		delete_post_meta( $post_id, PIV_META_CONTENT_HASH );
	}

	/**
	 * Full reset: clear result, manual override, and bust caches so the next scan is fresh.
	 *
	 * @param int  $post_id        Post ID.
	 * @param bool $clear_manual   Also clear manual layer/evidence/note.
	 * @return void
	 */
	public static function reset_verification( $post_id, $clear_manual = true ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		$result = self::get_result( $post_id );
		$urls   = array();

		if ( is_array( $result ) ) {
			foreach ( array( 'urls', 'cited_urls' ) as $key ) {
				if ( empty( $result[ $key ] ) || ! is_array( $result[ $key ] ) ) {
					continue;
				}
				foreach ( $result[ $key ] as $url ) {
					$urls[] = (string) $url;
				}
			}
			if ( ! empty( $result['sources']['external'] ) && is_array( $result['sources']['external'] ) ) {
				foreach ( $result['sources']['external'] as $source ) {
					if ( ! empty( $source['url'] ) ) {
						$urls[] = (string) $source['url'];
					}
				}
			}
			if ( ! empty( $result['best_match']['url'] ) ) {
				$urls[] = (string) $result['best_match']['url'];
			}
		}

		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			delete_transient( 'piv_src_' . md5( $url ) );
		}

		self::clear_verification_meta( $post_id );

		if ( $clear_manual ) {
			delete_post_meta( $post_id, PIV_META_MANUAL_LAYER );
			delete_post_meta( $post_id, PIV_META_MANUAL_EVIDENCE );
			delete_post_meta( $post_id, PIV_META_MANUAL_NOTE );
		}

		update_post_meta( $post_id, PIV_META_CACHE_NONCE, (string) time() . '-' . wp_generate_password( 6, false ) );
		update_post_meta( $post_id, PIV_META_STATUS, 'checking' );
		self::invalidate_verification_stats_cache();
	}

	/**
	 * Cache-bust token for a post (changes on reset).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_cache_nonce( $post_id ) {
		$post_nonce = (string) get_post_meta( absint( $post_id ), PIV_META_CACHE_NONCE, true );
		$global     = (string) get_option( 'piv_global_cache_nonce', '' );

		return $global ? ( $global . '|' . $post_nonce ) : $post_nonce;
	}

	/**
	 * Immediate global reset: wipe all verification results, manual overrides, queue and caches.
	 *
	 * @param bool $requeue Re-enqueue eligible posts for a fresh scan.
	 * @return array{cleared:int,enqueued:int}
	 */
	public static function reset_all_verifications( $requeue = true ) {
		$keys = array(
			PIV_META_LAYER,
			PIV_META_CHECKED_AT,
			PIV_META_RESULT,
			PIV_META_STATUS,
			PIV_META_CONTENT_HASH,
			PIV_META_MANUAL_LAYER,
			PIV_META_MANUAL_EVIDENCE,
			PIV_META_MANUAL_NOTE,
			PIV_META_CACHE_NONCE,
		);

		$cleared = 0;
		foreach ( $keys as $key ) {
			$found = (int) self::count_posts_with_meta( $key );
			delete_metadata( 'post', 0, $key, '', true );
			$cleared = max( $cleared, $found );
		}

		update_option( 'piv_global_cache_nonce', (string) time() . '-' . wp_generate_password( 8, false ), false );
		update_option( PIV_Queue::OPTION_QUEUE, array(), false );
		self::flush_plugin_transients();
		self::invalidate_verification_stats_cache();

		$enqueued = 0;
		if ( $requeue ) {
			$enqueued = self::enqueue_eligible_posts(
				array(
					'only_unverified' => true,
					'limit'           => 0,
					'priority'        => false,
				)
			);
		}

		return array(
			'cleared'  => $cleared,
			'enqueued' => $enqueued,
		);
	}

	/**
	 * Count posts that currently have a meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return int
	 */
	private static function count_posts_with_meta( $meta_key ) {
		$query = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Delete plugin transients from the options table.
	 */
	public static function flush_plugin_transients() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_piv_%'
			   OR option_name LIKE '_transient_timeout_piv_%'"
		);

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'transient' );
		}
	}

	/**
	 * Hash post fields that affect verification.
	 *
	 * @param WP_Post|object $post Post object.
	 * @return string
	 */
	public static function get_post_content_hash( $post ) {
		if ( ! $post ) {
			return '';
		}

		$payload = wp_strip_all_tags( (string) $post->post_title ) . "\n"
			. wp_strip_all_tags( (string) $post->post_excerpt ) . "\n"
			. wp_strip_all_tags( (string) $post->post_content );

		return md5( $payload );
	}

	/**
	 * Whether post content changed since the last verification.
	 *
	 * @param int            $post_id Post ID.
	 * @param WP_Post|null   $post    Optional post object.
	 * @return bool
	 */
	public static function post_content_changed( $post_id, $post = null ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return true;
		}

		if ( ! $post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post ) {
			return true;
		}

		$stored = (string) get_post_meta( $post_id, PIV_META_CONTENT_HASH, true );
		if ( '' === $stored ) {
			return true;
		}

		return $stored !== self::get_post_content_hash( $post );
	}

	/**
	 * Whether a post should run verification now.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Force run.
	 * @return bool
	 */
	public static function should_run_verification( $post_id, $force = false ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		if ( $force ) {
			return self::is_eligible_for_verification( $post_id );
		}

		if ( ! self::is_eligible_for_verification( $post_id ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( self::post_content_changed( $post_id, $post ) ) {
			return true;
		}

		if ( ! self::get_layer( $post_id ) ) {
			return true;
		}

		$settings   = self::get_settings();
		$layer      = self::get_layer( $post_id );
		$checked_at = (int) get_post_meta( $post_id, PIV_META_CHECKED_AT, true );
		$recheck    = max( 1, (int) ( $settings['recheck_hours'] ?? 24 ) ) * HOUR_IN_SECONDS;

		if ( PIV_Layers::UNDER_REVIEW === $layer ) {
			$review_window = max( 1, (int) ( $settings['review_window'] ?? 15 ) ) * MINUTE_IN_SECONDS;
			if ( ! $checked_at || ( time() - $checked_at ) >= $review_window ) {
				return true;
			}
		}

		if ( PIV_Layers::VERIFIED === $layer && $checked_at && ( time() - $checked_at ) < $recheck ) {
			return false;
		}

		if ( $checked_at && ( time() - $checked_at ) < $recheck ) {
			return false;
		}

		return true;
	}

	/**
	 * Get verification layer slug for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_layer( $post_id ) {
		if ( ! $post_id ) {
			return '';
		}

		$layer = (string) get_post_meta( $post_id, PIV_META_LAYER, true );
		if ( $layer && PIV_Layers::get( $layer ) ) {
			return $layer;
		}

		return '';
	}

	/**
	 * Get stored verification result array.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_result( $post_id ) {
		if ( ! $post_id ) {
			return array();
		}

		$raw = get_post_meta( $post_id, PIV_META_RESULT, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$data = json_decode( $raw, true );
			return is_array( $data ) ? $data : array();
		}

		if ( is_array( $raw ) ) {
			return $raw;
		}

		return array();
	}

	/**
	 * Compact a verification result so post meta can store it reliably.
	 * Full-scan payloads (scan_log for every domain) were too large and often
	 * failed to persist — leaving a layer badge with no sources in the UI.
	 *
	 * @param array $result Full in-memory result.
	 * @return array
	 */
	public static function slim_verification_result( $result ) {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$slim_source = static function ( $source ) {
			if ( ! is_array( $source ) ) {
				return null;
			}
			$url = isset( $source['url'] ) ? (string) $source['url'] : '';
			if ( '' === $url ) {
				return null;
			}

			return array(
				'url'            => $url,
				'domain'         => (string) ( $source['domain'] ?? '' ),
				'origin'         => (string) ( $source['origin'] ?? '' ),
				'title'          => mb_substr( (string) ( $source['title'] ?? '' ), 0, 180, 'UTF-8' ),
				'body_excerpt'   => mb_substr( (string) ( $source['body_excerpt'] ?? '' ), 0, 160, 'UTF-8' ),
				'match_score'    => (float) ( $source['match_score'] ?? 0 ),
				'title_score'    => (float) ( $source['title_score'] ?? 0 ),
				'body_score'     => (float) ( $source['body_score'] ?? 0 ),
				'ai_same_story'  => ! empty( $source['ai_same_story'] ),
				'ai_confidence'  => (float) ( $source['ai_confidence'] ?? 0 ),
				'ai_reason'      => mb_substr( (string) ( $source['ai_reason'] ?? '' ), 0, 180, 'UTF-8' ),
				'fetched'        => ! empty( $source['fetched'] ),
				'http_ok'        => ! empty( $source['http_ok'] ),
				'accepted'       => ! empty( $source['accepted'] ),
				'context_ok'     => ! empty( $source['context_ok'] ),
				'context_reason' => (string) ( $source['context_reason'] ?? '' ),
				'is_official'    => ! empty( $source['is_official'] ),
				'is_news'        => ! empty( $source['is_news'] ),
				'rss_hint'       => ! empty( $source['rss_hint'] ),
				'error'          => mb_substr( (string) ( $source['error'] ?? '' ), 0, 120, 'UTF-8' ),
			);
		};

		$slim_list = static function ( $list, $limit ) use ( $slim_source ) {
			$out = array();
			foreach ( array_slice( (array) $list, 0, $limit ) as $item ) {
				$row = $slim_source( $item );
				if ( $row ) {
					$out[] = $row;
				}
			}
			return $out;
		};

		$slim_discovery = static function ( $payload ) {
			if ( ! is_array( $payload ) ) {
				return array(
					'domains' => array(),
					'urls'    => array(),
				);
			}

			$found = 0;
			foreach ( (array) ( $payload['scan_log'] ?? array() ) as $row ) {
				$found += (int) ( $row['found'] ?? 0 );
			}

			return array(
				'domains'    => array_slice( array_values( (array) ( $payload['domains'] ?? array() ) ), 0, 80 ),
				'urls'       => array_slice( array_values( (array) ( $payload['urls'] ?? array() ) ), 0, 40 ),
				'found_total'=> $found,
				'query'      => isset( $payload['query'] ) ? (string) $payload['query'] : '',
				'provider'   => isset( $payload['provider'] ) ? (string) $payload['provider'] : '',
			);
		};

		$sources = isset( $result['sources'] ) && is_array( $result['sources'] ) ? $result['sources'] : array();

		$diag = is_array( $result['discovery_diag'] ?? null ) ? $result['discovery_diag'] : array();

		return array(
			'layer'            => (string) ( $result['layer'] ?? '' ),
			'checked_at'       => (int) ( $result['checked_at'] ?? 0 ),
			'urls'             => array_slice( array_values( (array) ( $result['urls'] ?? array() ) ), 0, 40 ),
			'cited_urls'       => array_slice( array_values( (array) ( $result['cited_urls'] ?? array() ) ), 0, 20 ),
			'discovery_diag'   => array(
				'candidates'   => (int) ( $diag['candidates'] ?? 0 ),
				'search_raw'   => (int) ( $diag['search_raw'] ?? 0 ),
				'search_kept'  => (int) ( $diag['search_kept'] ?? 0 ),
				'official'     => (int) ( $diag['official'] ?? 0 ),
				'news'         => (int) ( $diag['news'] ?? 0 ),
				'rss'          => (int) ( $diag['rss'] ?? 0 ),
				'provider'     => (string) ( $diag['provider'] ?? '' ),
				'search_error' => (string) ( $diag['search_error'] ?? '' ),
				'sample_urls'  => array_slice( array_values( (array) ( $diag['sample_urls'] ?? array() ) ), 0, 8 ),
			),
			'search'           => $slim_discovery( $result['search'] ?? array() ),
			'official_search'  => $slim_discovery( $result['official_search'] ?? array() ),
			'news_search'      => $slim_discovery( $result['news_search'] ?? array() ),
			'rss_search'       => array(
				'urls'  => array_slice( array_values( (array) ( $result['rss_search']['urls'] ?? array() ) ), 0, 30 ),
				'count' => count( (array) ( $result['rss_search']['urls'] ?? array() ) ),
			),
			'manual'           => is_array( $result['manual'] ?? null ) ? $result['manual'] : array(),
			'sources'          => array(
				'external'         => $slim_list( $sources['external'] ?? array(), 20 ),
				'attempted'        => $slim_list( $sources['attempted'] ?? array(), 40 ),
				'rejected_samples' => $slim_list( $sources['rejected_samples'] ?? array(), 5 ),
				'counts'           => is_array( $sources['counts'] ?? null ) ? $sources['counts'] : array(),
				'best_match'       => $slim_source( $sources['best_match'] ?? null ),
				'max_match'        => (float) ( $sources['max_match'] ?? 0 ),
				'has_conflicts'    => ! empty( $sources['has_conflicts'] ),
			),
			'evidence'         => $slim_list( $result['evidence'] ?? array(), 12 ),
			'reasons'          => array_slice( array_values( (array) ( $result['reasons'] ?? array() ) ), 0, 12 ),
			'best_match'       => $slim_source( $result['best_match'] ?? null ),
			'has_conflicts'    => ! empty( $result['has_conflicts'] ),
			'post_age_minutes' => (int) ( $result['post_age_minutes'] ?? 0 ),
			'_slim'            => true,
		);
	}

	/**
	 * Persist verification result JSON and verify it round-trips.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $result  Result payload.
	 * @return bool
	 */
	public static function save_verification_result( $post_id, $result ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! is_array( $result ) ) {
			return false;
		}

		$slim = self::slim_verification_result( $result );
		$json = wp_json_encode( $slim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json || '' === $json ) {
			return false;
		}

		update_post_meta( $post_id, PIV_META_RESULT, $json );

		$stored = get_post_meta( $post_id, PIV_META_RESULT, true );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		$decoded = json_decode( $stored, true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}

		// Require that evidence/attempted survived when we had them in memory.
		if ( ! empty( $slim['evidence'] ) && empty( $decoded['evidence'] ) ) {
			return false;
		}
		if ( ! empty( $slim['sources']['attempted'] ) && empty( $decoded['sources']['attempted'] ) && empty( $decoded['urls'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Downgrade stale "verified/trusted" layers that have no stored sources.
	 * Fixes posts stuck after failed oversized JSON saves.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when a repair was applied.
	 */
	public static function repair_inconsistent_verification( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		$layer = self::get_layer( $post_id );
		if ( ! in_array( $layer, array( PIV_Layers::VERIFIED, PIV_Layers::TRUSTED_SOURCE ), true ) ) {
			return false;
		}

		$result   = self::get_result( $post_id );
		$evidence = ! empty( $result['evidence'] ) && is_array( $result['evidence'] ) ? $result['evidence'] : array();
		$checked  = self::get_checked_sources( $post_id );
		$accepted = array_filter(
			$checked,
			static function ( $row ) {
				return ! empty( $row['accepted'] ) && ! empty( $row['url'] );
			}
		);

		// Trusted/verified without a visible evidence URL is always wrong.
		$has_real_evidence = false;
		foreach ( array_merge( $evidence, $accepted ) as $row ) {
			if ( ! empty( $row['url'] ) && ( ! empty( $row['title'] ) || ! empty( $row['domain'] ) ) ) {
				$has_real_evidence = true;
				break;
			}
		}
		if ( $has_real_evidence ) {
			return false;
		}

		$new_layer = PIV_Layers::INITIAL_REPORT;

		update_post_meta( $post_id, PIV_META_LAYER, $new_layer );

		$reasons = isset( $result['reasons'] ) && is_array( $result['reasons'] ) ? $result['reasons'] : array();
		array_unshift(
			$reasons,
			__( 'תוקן אוטומטית: השכבה הייתה מאומתת בלי מקורות שמורים — הורדה עד בדיקה מחדש', 'content-verification-badge' )
		);
		$result['layer']   = $new_layer;
		$result['reasons'] = array_slice( $reasons, 0, 12 );
		$result['evidence'] = array();
		self::save_verification_result( $post_id, $result );
		self::invalidate_verification_stats_cache();

		return true;
	}

	/**
	 * Build verification badge HTML.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    Display args.
	 * @return string
	 */
	public static function get_badge_html( $post_id, $args = array() ) {
		unset( $args );
		return self::get_inline_badge_html( $post_id );
	}

	/**
	 * Glue title text and badge so they stay on one line when possible.
	 *
	 * @param string $badge Badge HTML.
	 * @return string
	 */
	public static function get_badge_glue() {
		return '<span class="piv-title-badge-glue" aria-hidden="true">&#8288;&nbsp;</span>';
	}

	/**
	 * Append a badge inside the nearest wrapping title element.
	 *
	 * @param string $html  Title HTML.
	 * @param string $badge Badge HTML.
	 * @return string
	 */
	public static function append_badge_to_markup( $html, $badge ) {
		if ( '' === $html || '' === $badge ) {
			return $html;
		}

		$insert = self::get_badge_glue() . $badge;

		if ( preg_match( '#</a>\s*(</[^>]+>\s*)*$#i', $html ) ) {
			return preg_replace( '#</a>(\s*(?:</[^>]+>\s*)*)$#i', $insert . '</a>$1', $html, 1 );
		}

		if ( preg_match( '#</(h[1-6]|p|div|span)([^>]*)>\s*$#i', $html ) ) {
			return preg_replace( '#</(h[1-6]|p|div|span)([^>]*)>\s*$#i', $insert . '</$1$2>', $html, 1 );
		}

		return $html . $insert;
	}

	/**
	 * Append a badge after plain title text.
	 *
	 * @param string $title Plain or filtered title text.
	 * @param string $badge Badge HTML.
	 * @return string
	 */
	public static function append_badge_to_title_text( $title, $badge ) {
		if ( '' === $title || '' === $badge ) {
			return $title;
		}

		return $title . self::get_badge_glue() . $badge;
	}

	/**
	 * Compact badge: background + dot + current status only.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_inline_badge_html( $post_id ) {
		if ( self::is_excluded_post( $post_id ) ) {
			return '';
		}

		self::repair_inconsistent_verification( $post_id );

		$layer_slug = self::get_layer( $post_id );
		if ( ! $layer_slug ) {
			return '';
		}

		$layer = PIV_Layers::get( $layer_slug );
		if ( ! $layer ) {
			return '';
		}

		$aria_label = $layer['label'] . ' — ' . $layer['description'];

		return sprintf(
			'<span class="piv-marker piv-marker--%1$s" style="--piv-color:%2$s;--piv-bg:%3$s;" role="status" aria-label="%4$s"><span class="piv-marker__dot" aria-hidden="true">%5$s</span><span class="piv-marker__text">%6$s</span></span>',
			esc_attr( $layer_slug ),
			esc_attr( $layer['color'] ),
			esc_attr( $layer['bg'] ),
			esc_attr( $aria_label ),
			esc_html( $layer['icon'] ),
			esc_html( $layer['label'] )
		);
	}

	/**
	 * Elementor widget names that should never show verification badges.
	 *
	 * @return array
	 */
	public static function get_excluded_display_widgets() {
		$settings = self::get_settings();
		$defaults = array(
			'category_squares',
			'posts',
			'archive-posts',
			'loop-grid',
			'loop-carousel',
			'portfolio',
			'related-posts',
			'wp-widget-recent-posts',
		);
		$raw      = isset( $settings['excluded_display_widgets'] )
			? (string) $settings['excluded_display_widgets']
			: implode( ',', $defaults );

		$parts = preg_split( '/[\s,]+/', $raw );
		$parts = array_filter( array_map( 'sanitize_key', (array) $parts ) );
		$parts = array_values( array_unique( array_merge( $defaults, $parts ) ) );

		return apply_filters( 'piv_excluded_elementor_widgets', $parts );
	}

	/**
	 * Whether badge output is temporarily suppressed.
	 *
	 * @return bool
	 */
	public static function is_badge_display_suppressed() {
		return self::$badge_suppress_depth > 0;
	}

	/**
	 * Hide badges while a listing widget renders.
	 */
	public static function suppress_badge_display() {
		self::$badge_suppress_depth++;
	}

	/**
	 * Restore badge display after a listing widget render.
	 */
	public static function restore_badge_display() {
		if ( self::$badge_suppress_depth > 0 ) {
			self::$badge_suppress_depth--;
		}
	}

	/**
	 * Layers that may show a compact badge on the homepage / listing cards.
	 *
	 * @return array
	 */
	public static function get_homepage_badge_layers() {
		return apply_filters( 'piv_homepage_badge_layers', array( PIV_Layers::INITIAL_REPORT ) );
	}

	/**
	 * Whether a layer may show on homepage listings.
	 *
	 * @param string $layer_slug Layer slug.
	 * @return bool
	 */
	public static function is_homepage_badge_layer( $layer_slug ) {
		return $layer_slug && in_array( $layer_slug, self::get_homepage_badge_layers(), true );
	}

	/**
	 * Compact badge HTML for homepage listing cards (safe to output beside escaped titles).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_homepage_badge_html( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || is_admin() || wp_doing_ajax() ) {
			return '';
		}

		if ( self::is_excluded_post( $post_id ) ) {
			return '';
		}

		$layer = self::get_layer( $post_id );
		if ( ! self::is_homepage_badge_layer( $layer ) ) {
			return '';
		}

		return self::get_inline_badge_html( $post_id );
	}

	/**
	 * Allowed HTML for badge output in listing widgets.
	 *
	 * @return array
	 */
	public static function get_badge_allowed_html() {
		return array(
			'span' => array(
				'class'       => true,
				'style'       => true,
				'role'        => true,
				'aria-label'  => true,
				'aria-hidden' => true,
			),
		);
	}

	/**
	 * Sources checked during the last verification run.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_checked_sources( $post_id ) {
		$result = self::get_result( $post_id );
		$items  = array();
		$seen   = array();

		$push = static function ( $source, $accepted_default = null ) use ( &$items, &$seen ) {
			if ( empty( $source['url'] ) ) {
				return;
			}

			$url = (string) $source['url'];
			if ( isset( $seen[ $url ] ) ) {
				return;
			}

			$seen[ $url ] = true;
			$accepted     = array_key_exists( 'accepted', $source )
				? ! empty( $source['accepted'] )
				: ( null !== $accepted_default ? (bool) $accepted_default : true );

			$items[] = array(
				'url'            => $url,
				'domain'         => isset( $source['domain'] ) ? (string) $source['domain'] : PIV_Sources::domain_from_url( $url ),
				'origin'         => isset( $source['origin'] ) ? (string) $source['origin'] : '',
				'title'          => isset( $source['title'] ) ? (string) $source['title'] : '',
				'body_excerpt'   => isset( $source['body_excerpt'] ) ? (string) $source['body_excerpt'] : '',
				'match_score'    => isset( $source['match_score'] ) ? (float) $source['match_score'] : 0,
				'title_score'    => isset( $source['title_score'] ) ? (float) $source['title_score'] : 0,
				'body_score'     => isset( $source['body_score'] ) ? (float) $source['body_score'] : 0,
				'ai_same_story'  => ! empty( $source['ai_same_story'] ),
				'ai_confidence'  => isset( $source['ai_confidence'] ) ? (float) $source['ai_confidence'] : 0,
				'ai_reason'      => isset( $source['ai_reason'] ) ? (string) $source['ai_reason'] : '',
				'fetched'        => ! empty( $source['fetched'] ),
				'accepted'       => $accepted,
				'context_reason' => isset( $source['context_reason'] ) ? (string) $source['context_reason'] : '',
				'is_official'    => ! empty( $source['is_official'] ),
				'is_news'        => ! empty( $source['is_news'] ),
				'error'          => isset( $source['error'] ) ? (string) $source['error'] : '',
			);
		};

		// Only matched/accepted sources — never candidates that didn't fit.
		if ( ! empty( $result['evidence'] ) && is_array( $result['evidence'] ) ) {
			foreach ( $result['evidence'] as $source ) {
				$push( $source, true );
			}
		}

		if ( ! empty( $result['sources']['external'] ) && is_array( $result['sources']['external'] ) ) {
			foreach ( $result['sources']['external'] as $source ) {
				if ( empty( $source['accepted'] ) && empty( $result['evidence'] ) ) {
					// external list is matched-only in current versions.
				}
				$push( $source, true );
			}
		}

		if ( ! empty( $result['sources']['attempted'] ) && is_array( $result['sources']['attempted'] ) ) {
			foreach ( $result['sources']['attempted'] as $source ) {
				if ( empty( $source['accepted'] ) ) {
					continue;
				}
				$push( $source, true );
			}
		}

		return apply_filters( 'piv_checked_sources', $items, $post_id, $result );
	}

	/**
	 * Human-readable label for an admin source row.
	 *
	 * @param array $source Source row.
	 * @return string
	 */
	public static function format_source_admin_label( $source ) {
		$parts = array();

		if ( ! empty( $source['domain'] ) ) {
			$parts[] = (string) $source['domain'];
		}

		if ( array_key_exists( 'accepted', $source ) ) {
			$parts[] = ! empty( $source['accepted'] )
				? __( 'אושר', 'content-verification-badge' )
				: __( 'לא התאים', 'content-verification-badge' );
		}

		if ( ! empty( $source['is_official'] ) ) {
			$parts[] = __( 'רשמי', 'content-verification-badge' );
		} elseif ( ! empty( $source['is_news'] ) ) {
			$parts[] = __( 'חדשות', 'content-verification-badge' );
		}

		if ( ! empty( $source['origin'] ) && 'cited' === $source['origin'] ) {
			$parts[] = __( 'מהפוסט', 'content-verification-badge' );
		} elseif ( ! empty( $source['origin'] ) && 'manual' === $source['origin'] ) {
			$parts[] = __( 'ראיה ידנית', 'content-verification-badge' );
		} elseif ( ! empty( $source['origin'] ) && 'official' === $source['origin'] ) {
			$parts[] = __( 'אתר רשמי', 'content-verification-badge' );
		} elseif ( ! empty( $source['origin'] ) && 'news' === $source['origin'] ) {
			$parts[] = __( 'חדשות גיימינג', 'content-verification-badge' );
		} elseif ( ! empty( $source['origin'] ) && 'rss' === $source['origin'] ) {
			$parts[] = __( 'RSS', 'content-verification-badge' );
		} elseif ( ! empty( $source['origin'] ) && 'discovered' === $source['origin'] ) {
			$parts[] = __( 'מחיפוש', 'content-verification-badge' );
		} elseif ( ! empty( $source['origin'] ) && 'queued' === $source['origin'] ) {
			$parts[] = __( 'ברשימת בדיקה', 'content-verification-badge' );
		}

		if ( ! empty( $source['match_score'] ) ) {
			$label     = (int) round( (float) $source['match_score'] * 100 ) . '%';
			$title_pct = ! empty( $source['title_score'] ) ? (int) round( (float) $source['title_score'] * 100 ) : 0;
			$body_pct  = ! empty( $source['body_score'] ) ? (int) round( (float) $source['body_score'] * 100 ) : 0;
			if ( $title_pct || $body_pct ) {
				$label .= sprintf(
					/* translators: 1: title match percent, 2: body match percent */
					__( ' (כותרת %1$d%% / גוף %2$d%%)', 'content-verification-badge' ),
					$title_pct,
					$body_pct
				);
			}
			$parts[] = $label;
		}

		if ( array_key_exists( 'ai_same_story', $source ) || ! empty( $source['ai_confidence'] ) ) {
			if ( ! empty( $source['ai_same_story'] ) ) {
				$parts[] = sprintf(
					/* translators: %d: AI confidence percent */
					__( 'Gemini: אותו סיפור %d%%', 'content-verification-badge' ),
					(int) round( (float) ( $source['ai_confidence'] ?? 0 ) * 100 )
				);
			} else {
				$parts[] = __( 'Gemini: לא אותו סיפור', 'content-verification-badge' );
			}
		}

		if ( empty( $source['fetched'] ) && empty( $source['match_score'] ) ) {
			$parts[] = __( 'לא נפתח', 'content-verification-badge' );
		}

		return implode( ' · ', array_filter( $parts ) );
	}

	/**
	 * Whether badge should render in the current front-end context.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function should_show_frontend_badge( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || is_admin() || wp_doing_ajax() ) {
			return false;
		}

		if ( self::is_badge_display_suppressed() ) {
			return false;
		}

		if ( ! in_array( get_post_type( $post_id ), self::post_types(), true ) ) {
			return false;
		}

		if ( self::is_excluded_post( $post_id ) ) {
			return false;
		}

		$layer = self::get_layer( $post_id );
		if ( ! $layer ) {
			return false;
		}

		// Singular post page only — listing/homepage cards never get auto title injection.
		if ( is_singular() && (int) get_queried_object_id() === $post_id ) {
			return true;
		}

		return (bool) apply_filters( 'piv_should_show_badge', false, $post_id );
	}

	/**
	 * Whether Elementor is currently rendering frontend widgets.
	 *
	 * @return bool
	 */
	public static function is_elementor_frontend_rendering() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return false;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;
		if ( ! $plugin || empty( $plugin->frontend ) ) {
			return false;
		}

		return (bool) did_action( 'elementor/frontend/before_render' )
			|| ( method_exists( $plugin->frontend, 'has_elementor_in_page' ) && $plugin->frontend->has_elementor_in_page() );
	}

	/**
	 * Build evidence links list.
	 *
	 * @param array $sources Source analysis.
	 * @return string
	 */
	public static function get_evidence_html( $sources ) {
		if ( empty( $sources ) || empty( $sources['counts']['external'] ) ) {
			return '';
		}

		$items = array();
		$list  = ! empty( $sources['external'] ) ? $sources['external'] : array();

		foreach ( $list as $source ) {
			if ( empty( $source['url'] ) || empty( $source['domain'] ) ) {
				continue;
			}

			$label = $source['domain'];
			if ( ! empty( $source['is_official'] ) ) {
				$label .= ' (' . __( 'רשמי', 'content-verification-badge' ) . ')';
			}
			if ( ! empty( $source['origin'] ) && 'discovered' === $source['origin'] ) {
				$label .= ' (' . __( 'נמצא אוטומטית', 'content-verification-badge' ) . ')';
			}
			if ( isset( $source['match_score'] ) && $source['match_score'] > 0 ) {
				$label .= ' — ' . (int) round( $source['match_score'] * 100 ) . '%';
			}

			$items[] = sprintf(
				'<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
				esc_url( $source['url'] ),
				esc_html( $label )
			);
		}

		if ( empty( $items ) ) {
			return '';
		}

		return '<details class="piv-badge__evidence"><summary>' . esc_html__( 'צפה במקורות', 'content-verification-badge' ) . '</summary><ul>' . implode( '', $items ) . '</ul></details>';
	}
}
