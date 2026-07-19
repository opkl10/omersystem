<?php
/**
 * Admin settings page and AJAX recheck.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_piv_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'update_option_' . PIV_Helpers::OPTION_SETTINGS, array( __CLASS__, 'on_settings_updated' ) );
		add_action( 'wp_ajax_piv_recheck_post', array( __CLASS__, 'ajax_recheck_post' ) );
		add_action( 'wp_ajax_piv_enqueue_all_posts', array( __CLASS__, 'ajax_enqueue_all_posts' ) );
		add_action( 'wp_ajax_piv_reset_all_verifications', array( __CLASS__, 'ajax_reset_all_verifications' ) );
		add_action( 'wp_ajax_piv_get_coverage_stats', array( __CLASS__, 'ajax_get_coverage_stats' ) );
		add_action( 'wp_ajax_piv_get_report_rows', array( __CLASS__, 'ajax_get_report_rows' ) );
		add_action( 'wp_ajax_piv_test_connections', array( __CLASS__, 'ajax_test_connections' ) );
	}

	/**
	 * AJAX: live-test every external dependency and return exact errors.
	 */
	public static function ajax_test_connections() {
		check_ajax_referer( 'piv_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה', 'content-verification-badge' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$settings = PIV_Helpers::get_settings();
		$results  = array();

		// --- Google CSE ---
		if ( empty( $settings['google_api_key'] ) || empty( $settings['google_cx'] ) ) {
			$results['google'] = array(
				'ok'      => false,
				'message' => __( 'לא הוגדרו מפתח API ו-CX', 'content-verification-badge' ),
			);
		} else {
			delete_transient( PIV_Search::GOOGLE_QUOTA_TRANSIENT );
			PIV_Search::$last_google_error = '';
			$urls = PIV_Search::search_google_cse( 'playstation 5 news', 5, $settings );
			if ( '' !== PIV_Search::$last_google_error ) {
				$results['google'] = array(
					'ok'      => false,
					'message' => PIV_Search::$last_google_error,
				);
			} elseif ( empty( $urls ) ) {
				$results['google'] = array(
					'ok'      => false,
					'message' => __( 'החיבור עובד אבל חזרו 0 תוצאות — כנראה מנוע החיפוש (CX) לא מוגדר עם "Search the entire web"', 'content-verification-badge' ),
				);
			} else {
				$results['google'] = array(
					'ok'      => true,
					'message' => sprintf(
						/* translators: %d: number of results */
						__( 'החיבור תקין — חזרו %d תוצאות', 'content-verification-badge' ),
						count( $urls )
					),
				);
			}
		}

		// --- Gemini ---
		$results['gemini'] = PIV_Gemini::test_connection( $settings );

		// --- Google News RSS (free discovery path) ---
		$rss_response = wp_remote_get(
			'https://news.google.com/rss/search?q=playstation&hl=en-US&gl=US&ceid=US:en',
			array( 'timeout' => 10 )
		);
		if ( is_wp_error( $rss_response ) ) {
			$results['google_news'] = array(
				'ok'      => false,
				'message' => $rss_response->get_error_message(),
			);
		} else {
			$rss_body               = (string) wp_remote_retrieve_body( $rss_response );
			$results['google_news'] = array(
				'ok'      => false !== stripos( $rss_body, '<item' ),
				'message' => false !== stripos( $rss_body, '<item' )
					? __( 'החיבור תקין', 'content-verification-badge' )
					: ( 'HTTP ' . wp_remote_retrieve_response_code( $rss_response ) ),
			);
		}

		// --- Loopback (async queue runner) ---
		$loopback = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 8,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'piv_run_queue',
					'token'  => 'connectivity-test',
				),
			)
		);
		if ( is_wp_error( $loopback ) ) {
			$results['loopback'] = array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'בקשות loopback חסומות (%s) — עיבוד הרקע תלוי ב-WP-Cron בלבד', 'content-verification-badge' ),
					$loopback->get_error_message()
				),
			);
		} else {
			// A 403 means the endpoint answered (bad token as expected) — loopback works.
			$results['loopback'] = array(
				'ok'      => true,
				'message' => __( 'החיבור תקין — עיבוד רקע ללא תלות ב-cron זמין', 'content-verification-badge' ),
			);
		}

		// --- WP-Cron state ---
		$cron_disabled      = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$results['wp_cron'] = array(
			'ok'      => ! $cron_disabled,
			'message' => $cron_disabled
				? __( 'DISABLE_WP_CRON מופעל — ודא cron אמיתי בשרת, או הסתמך על עיבוד הרקע של התוסף', 'content-verification-badge' )
				: __( 'WP-Cron פעיל', 'content-verification-badge' ),
		);

		wp_send_json_success( array( 'results' => $results ) );
	}

	public static function add_menu() {
		add_options_page(
			__( 'אימות מידע', 'content-verification-badge' ),
			__( 'אימות מידע', 'content-verification-badge' ),
			'manage_options',
			'content-verification-badge',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'piv_settings_group',
			PIV_Helpers::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);
		register_setting(
			'piv_settings_group',
			PIV_Sources::OPTION_PRIMARY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_domains' ),
			)
		);
		register_setting(
			'piv_settings_group',
			PIV_Sources::OPTION_NEWS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_domains' ),
			)
		);
	}

	/**
	 * Save settings directly (more reliable than options.php alone).
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'אין הרשאה', 'content-verification-badge' ) );
		}

		check_admin_referer( 'piv_save_settings' );

		$settings_input = isset( $_POST[ PIV_Helpers::OPTION_SETTINGS ] )
			? (array) wp_unslash( $_POST[ PIV_Helpers::OPTION_SETTINGS ] )
			: array();
		$domains_input  = isset( $_POST[ PIV_Sources::OPTION_PRIMARY ] )
			? wp_unslash( $_POST[ PIV_Sources::OPTION_PRIMARY ] )
			: '';
		$news_input     = isset( $_POST[ PIV_Sources::OPTION_NEWS ] )
			? wp_unslash( $_POST[ PIV_Sources::OPTION_NEWS ] )
			: '';
		$rss_input      = isset( $_POST[ PIV_RSS::OPTION_FEEDS ] )
			? wp_unslash( $_POST[ PIV_RSS::OPTION_FEEDS ] )
			: '';

		$settings = self::sanitize_settings( $settings_input );
		$domains  = self::sanitize_domains( $domains_input );
		$news     = self::sanitize_domains( $news_input, PIV_Sources::OPTION_NEWS );
		$rss      = self::sanitize_feeds( $rss_input );

		update_option( PIV_Helpers::OPTION_SETTINGS, $settings, false );
		update_option( PIV_Sources::OPTION_PRIMARY, $domains, false );
		update_option( PIV_Sources::OPTION_NEWS, $news, false );
		update_option( PIV_RSS::OPTION_FEEDS, $rss, false );

		PIV_Helpers::purge_verification_for_excluded_posts();
		PIV_Helpers::invalidate_verification_stats_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'content-verification-badge',
					'piv-settings-saved' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Remove stale verification data when exclusion rules change.
	 */
	public static function on_settings_updated() {
		PIV_Helpers::purge_verification_for_excluded_posts();
	}

	/**
	 * @param array $input Settings input.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = PIV_Helpers::get_settings();

		$sanitized = array(
			'review_window'              => max( 1, min( 120, absint( $input['review_window'] ?? $existing['review_window'] ) ) ),
			'match_verified'             => max( 0.3, min( 1, (float) ( $input['match_verified'] ?? $existing['match_verified'] ) ) ),
			'match_verified_official'    => max( 0.12, min( 0.95, (float) ( $input['match_verified_official'] ?? $existing['match_verified_official'] ) ) ),
			'match_verified_news'        => max( 0.18, min( 0.95, (float) ( $input['match_verified_news'] ?? ( $existing['match_verified_news'] ?? 0.32 ) ) ) ),
			'match_review'               => max( 0.2, min( 0.95, (float) ( $input['match_review'] ?? $existing['match_review'] ) ) ),
			'recheck_hours'              => max( 1, absint( $input['recheck_hours'] ?? $existing['recheck_hours'] ) ),
			'queue_batch_size'           => max( 1, min( 15, absint( $input['queue_batch_size'] ?? $existing['queue_batch_size'] ) ) ),
			'max_source_fetches'         => max( 1, min( 5, absint( $input['max_source_fetches'] ?? $existing['max_source_fetches'] ) ) ),
			'verify_on_view'             => ! empty( $input['verify_on_view'] ) ? 'yes' : 'no',
			'show_evidence'              => ! empty( $input['show_evidence'] ) ? 'yes' : ( $existing['show_evidence'] ?? 'yes' ),
			'show_timestamp'             => ! empty( $input['show_timestamp'] ) ? 'yes' : ( $existing['show_timestamp'] ?? 'yes' ),
			'show_on_title'              => ! empty( $input['show_on_title'] ) ? 'yes' : 'no',
			'show_in_content'            => ! empty( $input['show_in_content'] ) ? 'yes' : ( $existing['show_in_content'] ?? 'no' ),
			'search_enabled'             => ! empty( $input['search_enabled'] ) ? 'yes' : 'no',
			'search_provider'            => sanitize_key( $input['search_provider'] ?? $existing['search_provider'] ),
			'search_max_results'         => max( 1, min( 10, absint( $input['search_max_results'] ?? $existing['search_max_results'] ) ) ),
			'check_official_sites'       => ! empty( $input['check_official_sites'] ) ? 'yes' : 'no',
			'official_domains_per_post'  => max( 1, min( 30, absint( $input['official_domains_per_post'] ?? $existing['official_domains_per_post'] ) ) ),
			'official_search_max_results'=> max( 1, min( 3, absint( $input['official_search_max_results'] ?? $existing['official_search_max_results'] ) ) ),
			'max_official_fetches'       => max( 1, min( 30, absint( $input['max_official_fetches'] ?? $existing['max_official_fetches'] ) ) ),
			'smart_official_scan'        => ! empty( $input['smart_official_scan'] ) ? 'yes' : 'no',
			'official_url_collect_limit' => max( 1, min( 30, absint( $input['official_url_collect_limit'] ?? $existing['official_url_collect_limit'] ) ) ),
			'check_news_sites'           => ! empty( $input['check_news_sites'] ) ? 'yes' : 'no',
			'news_domains_per_post'      => max( 1, min( 30, absint( $input['news_domains_per_post'] ?? $existing['news_domains_per_post'] ) ) ),
			'news_search_max_results'    => max( 1, min( 3, absint( $input['news_search_max_results'] ?? $existing['news_search_max_results'] ) ) ),
			'max_news_fetches'           => max( 1, min( 15, absint( $input['max_news_fetches'] ?? $existing['max_news_fetches'] ) ) ),
			'smart_news_scan'            => ! empty( $input['smart_news_scan'] ) ? 'yes' : 'no',
			'news_url_collect_limit'     => max( 1, min( 30, absint( $input['news_url_collect_limit'] ?? $existing['news_url_collect_limit'] ) ) ),
			'check_rss'                  => ! empty( $input['check_rss'] ) ? 'yes' : 'no',
			'rss_feeds_per_post'         => max( 1, min( 40, absint( $input['rss_feeds_per_post'] ?? ( $existing['rss_feeds_per_post'] ?? 20 ) ) ) ),
			'rss_max_items_per_feed'     => max( 5, min( 40, absint( $input['rss_max_items_per_feed'] ?? ( $existing['rss_max_items_per_feed'] ?? 15 ) ) ) ),
			'rss_match_threshold'        => max( 0.12, min( 0.8, (float) ( $input['rss_match_threshold'] ?? ( $existing['rss_match_threshold'] ?? 0.2 ) ) ) ),
			'rss_url_collect_limit'      => max( 1, min( 30, absint( $input['rss_url_collect_limit'] ?? ( $existing['rss_url_collect_limit'] ?? 8 ) ) ) ),
			'allow_manual_override'      => ! empty( $input['allow_manual_override'] ) ? 'yes' : 'no',
			'gemini_enabled'             => ! empty( $input['gemini_enabled'] ) ? 'yes' : 'no',
			'gemini_api_key'             => ( static function () use ( $input, $existing ) {
				$submitted = isset( $input['gemini_api_key'] ) ? sanitize_text_field( $input['gemini_api_key'] ) : '';
				if ( '' === $submitted ) {
					return (string) ( $existing['gemini_api_key'] ?? '' );
				}
				return $submitted;
			} )(),
			'gemini_model'               => sanitize_text_field( $input['gemini_model'] ?? $existing['gemini_model'] ?? 'gemini-2.5-flash' ),
			'gemini_min_confidence'      => max( 0.5, min( 0.95, (float) ( $input['gemini_min_confidence'] ?? $existing['gemini_min_confidence'] ?? 0.72 ) ) ),
			'gemini_max_compares'        => max( 1, min( 20, absint( $input['gemini_max_compares'] ?? $existing['gemini_max_compares'] ?? 8 ) ) ),
			'gemini_required'            => ! empty( $input['gemini_required'] ) ? 'yes' : 'no',
			'google_api_key'             => sanitize_text_field( $input['google_api_key'] ?? $existing['google_api_key'] ),
			'google_cx'                  => sanitize_text_field( $input['google_cx'] ?? $existing['google_cx'] ),
			'bing_api_key'               => sanitize_text_field( $input['bing_api_key'] ?? $existing['bing_api_key'] ),
			'search_site_restrict'       => sanitize_text_field( $input['search_site_restrict'] ?? $existing['search_site_restrict'] ),
			'included_categories'        => sanitize_text_field( $input['included_categories'] ?? $existing['included_categories'] ),
			'excluded_categories'        => sanitize_text_field( $input['excluded_categories'] ?? $existing['excluded_categories'] ),
			'excluded_category_keywords' => sanitize_text_field( $input['excluded_category_keywords'] ?? $existing['excluded_category_keywords'] ),
			'excluded_display_widgets'   => sanitize_text_field( $input['excluded_display_widgets'] ?? $existing['excluded_display_widgets'] ),
			'exclude_review_box_posts'   => ! empty( $input['exclude_review_box_posts'] ) ? 'yes' : 'no',
			'exclude_review_like_posts'  => ! empty( $input['exclude_review_like_posts'] ) ? 'yes' : 'no',
		);

		return array_merge( $existing, $sanitized );
	}

	/**
	 * @param mixed $input Domain textarea value.
	 * @return array
	 */
	/**
	 * @param mixed $input Feed textarea.
	 * @return array
	 */
	public static function sanitize_feeds( $input ) {
		if ( is_array( $input ) ) {
			$feeds = PIV_RSS::normalize_feed_list( $input );
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $input );
			$feeds = PIV_RSS::normalize_feed_list( $lines );
		}

		if ( empty( $feeds ) ) {
			$existing = get_option( PIV_RSS::OPTION_FEEDS, array() );
			if ( is_array( $existing ) && ! empty( $existing ) ) {
				return $existing;
			}
			return PIV_RSS::default_feeds();
		}

		return $feeds;
	}

	public static function sanitize_domains( $input, $option_name = null ) {
		if ( is_array( $input ) ) {
			return PIV_Sources::normalize_domain_list( $input );
		}

		$lines   = preg_split( '/\r\n|\r|\n/', (string) $input );
		$domains = PIV_Sources::normalize_domain_list( $lines );

		if ( empty( $domains ) ) {
			$option = $option_name ? $option_name : PIV_Sources::OPTION_PRIMARY;
			$existing = get_option( $option, array() );
			if ( is_array( $existing ) && ! empty( $existing ) ) {
				return $existing;
			}
		}

		return $domains;
	}

	public static function assets( $hook ) {
		if ( 'settings_page_content-verification-badge' !== $hook
			&& 'settings_page_content-verification-report' !== $hook
			&& 'post.php' !== $hook
			&& 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'piv-admin',
			PIV_PLUGIN_URL . 'assets/css/piv-admin.css',
			array(),
			PIV_VERSION
		);

		$page = 'post';
		if ( 'settings_page_content-verification-badge' === $hook ) {
			$page = 'settings';
		} elseif ( 'settings_page_content-verification-report' === $hook ) {
			$page = 'report';
		}

		wp_enqueue_script(
			'piv-admin',
			PIV_PLUGIN_URL . 'assets/js/piv-admin.js',
			array( 'jquery' ),
			PIV_VERSION,
			true
		);

		wp_localize_script(
			'piv-admin',
			'pivAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'page'         => $page,
				'pollInterval' => 3000,
				'nonce'        => wp_create_nonce( 'piv_admin_actions' ),
				'i18n'         => array(
					'checking'       => __( 'סורק את כל האתרים...', 'content-verification-badge' ),
					'resetting'      => __( 'מאפס ובודק מחדש...', 'content-verification-badge' ),
					'precisionScan'  => __( 'מריץ סריקה מדויקת מלאה...', 'content-verification-badge' ),
					'done'           => __( 'הבדיקה הושלמה', 'content-verification-badge' ),
					'error'          => __( 'שגיאה בבדיקה', 'content-verification-badge' ),
					'confirmReset'      => __( 'לאפס את תוצאת האימות ולסרוק מחדש בלי שמירה ישנה / מקורות לא רלוונטיים?', 'content-verification-badge' ),
					'confirmPrecision'  => __( 'להריץ סריקה מדויקת מלאה? זה מאפס תוצאה ישנה, סורק את כל האתרים ברשימה, קורא תוכן ומשווה — יכול לקחת 1–5 דקות.', 'content-verification-badge' ),
					'confirmResetAll'   => __( 'לאפס עכשיו את כל תוצאות האימות באתר (כולל אימות ידני וקאש) ולהתחיל סריקה מחדש?', 'content-verification-badge' ),
					'resettingAll'      => __( 'מאפס את כל האימותים...', 'content-verification-badge' ),
					'resetAllDone'      => __( 'האיפוס הכללי הושלם', 'content-verification-badge' ),
					'resetAllError'     => __( 'שגיאה באיפוס הכללי', 'content-verification-badge' ),
					'queueing'       => __( 'מוסיף לתור...', 'content-verification-badge' ),
					'queueDone'      => __( 'הפוסטים נוספו לתור הבדיקה', 'content-verification-badge' ),
					'queueError'     => __( 'שגיאה בהוספה לתור', 'content-verification-badge' ),
					'liveUpdating'   => __( 'מתעדכן בזמן אמת…', 'content-verification-badge' ),
					'liveComplete'   => __( 'הבדיקה הושלמה', 'content-verification-badge' ),
					'coverageLabel'  => __( '%1$d%% הושלמו (%2$d מתוך %3$d)', 'content-verification-badge' ),
					'sourcesOne'     => __( 'מקור אחד', 'content-verification-badge' ),
					'sourcesMany'    => __( '%d מקורות', 'content-verification-badge' ),
					'notChecked'     => __( 'טרם נבדק', 'content-verification-badge' ),
					'excluded'       => __( 'מוחרג (ביקורת/דעה)', 'content-verification-badge' ),
				),
			)
		);
	}

	/**
	 * Render shared coverage progress markup.
	 *
	 * @param array $payload Coverage payload from PIV_Helpers::get_coverage_payload().
	 */
	public static function render_coverage_progress( $payload ) {
		$stats   = isset( $payload['stats'] ) ? $payload['stats'] : array();
		$percent = isset( $payload['percent'] ) ? (int) $payload['percent'] : 0;
		$active  = ! empty( $payload['active'] );
		?>
		<div class="piv-coverage-progress<?php echo $active ? ' is-active' : ''; ?>" data-piv-coverage-progress>
			<div class="piv-coverage-progress__track" aria-hidden="true">
				<div class="piv-coverage-progress__bar" style="width: <?php echo esc_attr( (string) $percent ); ?>%;"></div>
			</div>
			<div class="piv-coverage-progress__meta">
				<span class="piv-coverage-progress__label" data-piv-coverage-label>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: percent, 2: verified count, 3: eligible count */
							__( '%1$d%% הושלמו (%2$d מתוך %3$d)', 'content-verification-badge' ),
							$percent,
							(int) ( $stats['verified'] ?? 0 ),
							(int) ( $stats['eligible'] ?? 0 )
						)
					);
					?>
				</span>
				<span class="piv-coverage-progress__status" data-piv-coverage-status aria-live="polite">
					<?php echo $active ? esc_html__( 'מתעדכן בזמן אמת…', 'content-verification-badge' ) : esc_html__( 'הבדיקה הושלמה', 'content-verification-badge' ); ?>
				</span>
			</div>
		</div>
		<ul class="piv-coverage-stats" data-piv-coverage-stats>
			<li><strong data-stat="eligible"><?php echo esc_html( (string) ( $stats['eligible'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'פוסטים בכיסוי', 'content-verification-badge' ); ?></li>
			<li><strong data-stat="verified"><?php echo esc_html( (string) ( $stats['verified'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'כבר נבדקו', 'content-verification-badge' ); ?></li>
			<li><strong data-stat="unverified"><?php echo esc_html( (string) ( $stats['unverified'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'ממתינים לבדיקה', 'content-verification-badge' ); ?></li>
			<li><strong data-stat="queue"><?php echo esc_html( (string) ( $stats['queue'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'בתור כרגע', 'content-verification-badge' ); ?></li>
		</ul>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = PIV_Helpers::get_settings();
		$primary  = self::get_domains_textarea_value();
		$news     = self::get_news_domains_textarea_value();
		$rss      = self::get_rss_feeds_textarea_value();
		$coverage = PIV_Helpers::get_coverage_payload();
		?>
		<div class="wrap piv-admin-wrap">
			<h1><?php esc_html_e( 'אימות מידע — הגדרות', 'content-verification-badge' ); ?></h1>
			<?php
			if ( ! empty( $_GET['piv-settings-saved'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'ההגדרות נשמרו בהצלחה.', 'content-verification-badge' ) . '</p></div>';
			}
			settings_errors();
			?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'options-general.php?page=content-verification-report' ) ); ?>">
					<?php esc_html_e( 'צפה בדוח אימות לפי פוסטים', 'content-verification-badge' ); ?>
				</a>
			</p>
			<p><?php esc_html_e( 'המערכת מחפשת מקורות חיצוניים לפי כותרת ותוכן הפוסט — גם כשאין קישורים בפוסט. לאחר מכן משווה את המידע ומציגה תג אימות.', 'content-verification-badge' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'piv_save_settings' ); ?>
				<input type="hidden" name="action" value="piv_save_settings">

				<h2><?php esc_html_e( 'ספים', 'content-verification-badge' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="piv_review_window"><?php esc_html_e( 'חלון "נמצא בבדיקה" (דקות)', 'content-verification-badge' ); ?></label></th>
						<td><input type="number" min="1" max="120" id="piv_review_window" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[review_window]" value="<?php echo esc_attr( $settings['review_window'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_match_verified"><?php esc_html_e( 'סף התאמה כללי (לשכבת "ממקור מהימן") (0–1)', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0.3" max="1" id="piv_match_verified" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[match_verified]" value="<?php echo esc_attr( $settings['match_verified'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'ברירת מחדל: 0.55. ערך 0.90 גבוה מדי לעברית מול אנגלית.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_match_verified_official"><?php esc_html_e( 'סף "מידע מאומת" — רק מקור רשמי (מפתחת/סטודיו/חברה) (0–1)', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0.12" max="0.95" id="piv_match_verified_official" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[match_verified_official]" value="<?php echo esc_attr( $settings['match_verified_official'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'ברירת מחדל: 0.25 — PlayStation / Xbox / Nintendo וכו\'.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_match_verified_news"><?php esc_html_e( 'סף "מאומת" מאתר חדשות (0–1)', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0.18" max="0.95" id="piv_match_verified_news" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[match_verified_news]" value="<?php echo esc_attr( $settings['match_verified_news'] ?? 0.32 ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'כתבה רלוונטית אחת, או שתי כתבות ממקורות שונים — מספיק לאימות.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_match_review"><?php esc_html_e( 'סף "נמצא בבדיקה" (0–1)', 'content-verification-badge' ); ?></label></th>
						<td><input type="number" step="0.01" min="0.2" max="0.95" id="piv_match_review" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[match_review]" value="<?php echo esc_attr( $settings['match_review'] ); ?>" class="small-text"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'ביצועים', 'content-verification-badge' ); ?></h2>
				<p class="description"><?php esc_html_e( 'הבדיקות רצות ברקע — לא מעכבות שמירת פוסטים או טעינת עמודים. עם 100+ כתבות מומלץ להשאיר את ברירות המחדל.', 'content-verification-badge' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="piv_recheck_hours"><?php esc_html_e( 'לא לבדוק שוב לפני (שעות)', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="168" id="piv_recheck_hours" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[recheck_hours]" value="<?php echo esc_attr( $settings['recheck_hours'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'אם התוכן לא השתנה — לא מריצים בדיקה מחדש לפני מספר השעות הזה.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_queue_batch"><?php esc_html_e( 'פוסטים לרקע בכל ריצה', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="15" id="piv_queue_batch" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[queue_batch_size]" value="<?php echo esc_attr( $settings['queue_batch_size'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'ברירת מחדל: 5. עם 100 כתבות — כ-7 דקות לסיום תור מלא (במקום שעות).', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_max_fetches"><?php esc_html_e( 'אתרים לפתיחה לכל פוסט', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="5" id="piv_max_fetches" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[max_source_fetches]" value="<?php echo esc_attr( $settings['max_source_fetches'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'פחות = מהיר יותר. מספיק לרוב הכתבות.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'בדיקה בצפייה', 'content-verification-badge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[verify_on_view]" value="yes" <?php checked( $settings['verify_on_view'], 'yes' ); ?>> <?php esc_html_e( 'תור רקע גם כשמבקרים פוסט שלא נבדק (לא מומלץ)', 'content-verification-badge' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'חיפוש מקורות חיצוני', 'content-verification-badge' ); ?></h2>
				<p class="description"><?php esc_html_e( 'ברירת המחדל: חיפוש אוטומטי לפי כותרת הפוסט. מומלץ להגדיר Google Custom Search לתוצאות מדויקות יותר (חינם עד 100 חיפושים ביום).', 'content-verification-badge' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'חיפוש חיצוני', 'content-verification-badge' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[search_enabled]" value="yes" <?php checked( $settings['search_enabled'], 'yes' ); ?>> <?php esc_html_e( 'חפש מקורות אוטומטית לפי כותרת הפוסט', 'content-verification-badge' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_search_provider"><?php esc_html_e( 'מנוע חיפוש', 'content-verification-badge' ); ?></label></th>
						<td>
							<select id="piv_search_provider" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[search_provider]">
								<option value="auto" <?php selected( $settings['search_provider'], 'auto' ); ?>><?php esc_html_e( 'אוטומטי (Google → Bing → DuckDuckGo)', 'content-verification-badge' ); ?></option>
								<option value="google_cse" <?php selected( $settings['search_provider'], 'google_cse' ); ?>>Google Custom Search</option>
								<option value="bing" <?php selected( $settings['search_provider'], 'bing' ); ?>>Bing Web Search</option>
								<option value="duckduckgo" <?php selected( $settings['search_provider'], 'duckduckgo' ); ?>>DuckDuckGo (ללא מפתח)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_search_max"><?php esc_html_e( 'מספר תוצאות לבדיקה', 'content-verification-badge' ); ?></label></th>
						<td><input type="number" min="1" max="10" id="piv_search_max" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[search_max_results]" value="<?php echo esc_attr( $settings['search_max_results'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_google_key"><?php esc_html_e( 'Google API Key', 'content-verification-badge' ); ?></label></th>
						<td><input type="text" id="piv_google_key" class="regular-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[google_api_key]" value="<?php echo esc_attr( $settings['google_api_key'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_google_cx"><?php esc_html_e( 'Google Search Engine ID (cx)', 'content-verification-badge' ); ?></label></th>
						<td><input type="text" id="piv_google_cx" class="regular-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[google_cx]" value="<?php echo esc_attr( $settings['google_cx'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_bing_key"><?php esc_html_e( 'Bing API Key', 'content-verification-badge' ); ?></label></th>
						<td><input type="text" id="piv_bing_key" class="regular-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[bing_api_key]" value="<?php echo esc_attr( $settings['bing_api_key'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Gemini AI', 'content-verification-badge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[gemini_enabled]" value="yes" <?php checked( $settings['gemini_enabled'] ?? 'no', 'yes' ); ?>>
								<?php esc_html_e( 'הפעל השוואת משמעות עם Gemini (מומלץ לדיוק)', 'content-verification-badge' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'אחרי שהבוט מוצא ופותח מקור, Gemini בודק אם זה באמת אותו סיפור כמו הפוסט — לא רק מילים דומות.', 'content-verification-badge' ); ?>
								<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'קבל מפתח ב־Google AI Studio', 'content-verification-badge' ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_gemini_key"><?php esc_html_e( 'Gemini API Key', 'content-verification-badge' ); ?></label></th>
						<td><input type="password" id="piv_gemini_key" class="regular-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[gemini_api_key]" value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_gemini_model"><?php esc_html_e( 'מודל Gemini', 'content-verification-badge' ); ?></label></th>
						<td>
							<select id="piv_gemini_model" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[gemini_model]">
								<?php
								$gemini_model = (string) ( $settings['gemini_model'] ?? 'gemini-2.5-flash' );
								$models       = array(
									'gemini-2.5-flash'       => 'Gemini 2.5 Flash (מומלץ — מכסה חינמית נדיבה)',
									'gemini-3-flash-preview' => 'Gemini 3 Flash (מהיר, זול)',
									'gemini-2.5-pro'         => 'Gemini 2.5 Pro (דורש חיוב)',
									'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro (הכי חזק — דורש חיוב, אין כמעט מכסה חינמית)',
								);
								foreach ( $models as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $gemini_model, $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'בתוכנית חינם השתמש ב-2.5 Flash. מודלי Pro דורשים חיוב פעיל ב-Google — בלי חיוב הם מחזירים שגיאת 429 (quota). אם המודל שנבחר נכשל, התוסף עובר אוטומטית ל-2.5 Flash.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_gemini_conf"><?php esc_html_e( 'סף ביטחון Gemini (0.5–0.95)', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0.5" max="0.95" id="piv_gemini_conf" class="small-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[gemini_min_confidence]" value="<?php echo esc_attr( $settings['gemini_min_confidence'] ?? 0.72 ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_gemini_max"><?php esc_html_e( 'מקס׳ השוואות AI לפוסט', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="20" id="piv_gemini_max" class="small-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[gemini_max_compares]" value="<?php echo esc_attr( $settings['gemini_max_compares'] ?? 8 ); ?>">
							<p class="description"><?php esc_html_e( 'מגביל עלות. בסריקה מדויקת המגבלה עולה אוטומטית.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Gemini חובה לאישור', 'content-verification-badge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[gemini_required]" value="yes" <?php checked( $settings['gemini_required'] ?? 'no', 'yes' ); ?>>
								<?php esc_html_e( 'אשר מקור רק אם Gemini אישר שזה אותו סיפור', 'content-verification-badge' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'מומלץ לכבות. כשפעיל — שגיאת API או מגבלת השוואות עלולה להשאיר 0 מקורות.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_site_restrict"><?php esc_html_e( 'הגבלת חיפוש לדומיינים', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="text" id="piv_site_restrict" class="large-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[search_site_restrict]" value="<?php echo esc_attr( $settings['search_site_restrict'] ); ?>" placeholder="ign.com, kotaku.com, polygon.com">
							<p class="description"><?php esc_html_e( 'אופציונלי — מגביל רק את החיפוש הכללי. חיפוש באתרים הרשמיים רץ בנפרד לפי הרשימה למטה.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'אתרים רשמיים', 'content-verification-badge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[check_official_sites]" value="yes" <?php checked( $settings['check_official_sites'], 'yes' ); ?>> <?php esc_html_e( 'חפש גם באתרים הרשמיים (PlayStation, Xbox, Steam וכו\')', 'content-verification-badge' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'סריקה חכמה', 'content-verification-badge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[smart_official_scan]" value="yes" <?php checked( $settings['smart_official_scan'], 'yes' ); ?>> <?php esc_html_e( 'סרוק רק אתרים רלוונטיים למותג בפוסט (מומלץ — הרבה יותר מהיר)', 'content-verification-badge' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_official_domains"><?php esc_html_e( 'אתרים רשמיים לסריקה', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="30" id="piv_official_domains" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[official_domains_per_post]" value="<?php echo esc_attr( $settings['official_domains_per_post'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'עד 30 אתרים. עם סריקה חכמה — בדרך כלל רק 1–3 אתרים רלוונטיים. חיפושים מקובצים (5 אתרים בקריאה אחת).', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_official_results"><?php esc_html_e( 'תוצאות לכל אתר רשמי', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="3" id="piv_official_results" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[official_search_max_results]" value="<?php echo esc_attr( $settings['official_search_max_results'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'כמה עמודים לשלוף מתוך כל אתר רשמי (חיפוש פנימי).', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_official_collect"><?php esc_html_e( 'עצור אחרי מספר קישורים', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="30" id="piv_official_collect" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[official_url_collect_limit]" value="<?php echo esc_attr( $settings['official_url_collect_limit'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'ברירת מחדל: 6. מפסיק לחפש ברגע שנמצאו מספיק קישורים רשמיים.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_max_official_fetches"><?php esc_html_e( 'עמודים רשמיים לפתיחה', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="30" id="piv_max_official_fetches" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[max_official_fetches]" value="<?php echo esc_attr( $settings['max_official_fetches'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'כמה עמודים מאתרים רשמיים לפתוח ולהשוות בפועל (עד 30).', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'חדשות גיימינג', 'content-verification-badge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[check_news_sites]" value="yes" <?php checked( $settings['check_news_sites'], 'yes' ); ?>> <?php esc_html_e( 'חפש גם באתרי חדשות גיימינג מהרשימה (IGN, Kotaku, Polygon וכו\')', 'content-verification-badge' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'סריקה חכמה — חדשות', 'content-verification-badge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[smart_news_scan]" value="yes" <?php checked( $settings['smart_news_scan'], 'yes' ); ?>> <?php esc_html_e( 'קודם אתרים מהפוסט + מובילים מהרשימה (מומלץ)', 'content-verification-badge' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_news_domains"><?php esc_html_e( 'אתרי חדשות לסריקה', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="30" id="piv_news_domains" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[news_domains_per_post]" value="<?php echo esc_attr( $settings['news_domains_per_post'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'כמה אתרי חדשות לסרוק בכל פוסט (site:domain + כותרת).', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_news_results"><?php esc_html_e( 'תוצאות לכל אתר חדשות', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="3" id="piv_news_results" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[news_search_max_results]" value="<?php echo esc_attr( $settings['news_search_max_results'] ); ?>" class="small-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_news_collect"><?php esc_html_e( 'עצור אחרי קישורי חדשות', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="30" id="piv_news_collect" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[news_url_collect_limit]" value="<?php echo esc_attr( $settings['news_url_collect_limit'] ); ?>" class="small-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_max_news_fetches"><?php esc_html_e( 'עמודי חדשות לפתיחה', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="number" min="1" max="15" id="piv_max_news_fetches" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[max_news_fetches]" value="<?php echo esc_attr( $settings['max_news_fetches'] ); ?>" class="small-text">
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'כיסוי פוסטי חדשות', 'content-verification-badge' ); ?></h2>
				<p class="description"><?php esc_html_e( 'ברירת מחדל: כל הפוסטים המפורסמים נכנסים לבדיקה (מלבד ביקורות/דעה). אפשר להגביל לקטגוריות מסוימות בלבד.', 'content-verification-badge' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="piv_included_categories"><?php esc_html_e( 'קטגוריות לבדיקה (אופציונלי)', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="text" id="piv_included_categories" class="large-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[included_categories]" value="<?php echo esc_attr( $settings['included_categories'] ); ?>" placeholder="">
							<p class="description"><?php esc_html_e( 'ריק = כל פוסטי החדשות. אם תמלא — רק פוסטים בקטגוריות האלה ייבדקו (שם או slug, מופרד בפסיקים).', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'קטגוריות מוחרגות', 'content-verification-badge' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="piv_excluded_keywords"><?php esc_html_e( 'מילות מפתח בקטגוריה', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="text" id="piv_excluded_keywords" class="large-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[excluded_category_keywords]" value="<?php echo esc_attr( $settings['excluded_category_keywords'] ); ?>" placeholder="ביקורת,ביקורות,דעה,review,reviews,opinion">
							<p class="description"><?php esc_html_e( 'כל קטגוריה או תגית ששם/slug שלהם מכילים אחת מהמילים — לא תיבדק. נסרקות כל הקטגוריות והתגיות של הפוסט.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_excluded_categories"><?php esc_html_e( 'קטגוריות מדויקות (אופציונלי)', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="text" id="piv_excluded_categories" class="large-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[excluded_categories]" value="<?php echo esc_attr( $settings['excluded_categories'] ); ?>" placeholder="">
							<p class="description"><?php esc_html_e( 'שמות או slugs מדויקים מופרדים בפסיקים — בנוסף למילות המפתח.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'ביקורות משחק', 'content-verification-badge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[exclude_review_box_posts]" value="yes" <?php checked( $settings['exclude_review_box_posts'], 'yes' ); ?>> <?php esc_html_e( 'דלג על פוסטים עם תיבת ביקורת / ציון ביקורת (Game Reviews Pro)', 'content-verification-badge' ); ?></label>
							<br>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[exclude_review_like_posts]" value="yes" <?php checked( $settings['exclude_review_like_posts'] ?? 'yes', 'yes' ); ?>> <?php esc_html_e( 'דלג גם על ביקורות ישנות לפי כותרת/סלאג (ביקורת:, סקירה:, review)', 'content-verification-badge' ); ?></label>
							<p class="description"><?php esc_html_e( 'כולל ביקורות ישנות בלי תיבת ביקורת מודרנית — לא יקבלו דירוג אימות.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'תצוגה', 'content-verification-badge' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'הצג ליד כותרת', 'content-verification-badge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[show_on_title]" value="yes" <?php checked( $settings['show_on_title'], 'yes' ); ?>> <?php esc_html_e( 'הצג סימון צבע ליד כותרת הפוסט בלבד', 'content-verification-badge' ); ?></label>
							<p class="description"><?php esc_html_e( 'הסימון מופיע רק ליד הכותרת — לא מעל או מתחת לתוכן.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_excluded_display_widgets"><?php esc_html_e( 'הסתר בווידג\'טים', 'content-verification-badge' ); ?></label></th>
						<td>
							<input type="text" id="piv_excluded_display_widgets" class="large-text" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[excluded_display_widgets]" value="<?php echo esc_attr( $settings['excluded_display_widgets'] ); ?>" placeholder="category_squares,posts,loop-grid">
							<p class="description"><?php esc_html_e( 'בווידג\'טי רשימות של Elementor התג לא מוזרק — כדי למנוע שבירת HTML ואייטמים שנראים משוכפלים.', 'content-verification-badge' ); ?></p>
							<p class="description"><?php esc_html_e( 'שמות ווידג\'ט Elementor שלא יציגו את הסימון (למשל מגזין קטגוריה / 5 פוסטים חשובים). מופרד בפסיקים.', 'content-verification-badge' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'אתרים רשמיים לבדיקה (דומיין אחד בכל שורה)', 'content-verification-badge' ); ?></h2>
				<p class="description"><?php esc_html_e( 'המערכת מחפשת בהם אוטומטית לפי כותרת הפוסט (site:domain + כותרת). הוסף דומיינים של מפרסמים/פלטפורמות שחשובים לך.', 'content-verification-badge' ); ?></p>
				<p><textarea name="<?php echo esc_attr( PIV_Sources::OPTION_PRIMARY ); ?>" rows="10" class="large-text code"><?php echo esc_textarea( $primary ); ?></textarea></p>

				<h2><?php esc_html_e( 'אתרי חדשות גיימינג לבדיקה (דומיין אחד בכל שורה)', 'content-verification-badge' ); ?></h2>
				<p class="description"><?php esc_html_e( 'בכל בדיקת פוסט המערכת מחפשת גם באתרים אלה אם קיימת כתבה תואמת לכותרת. ברירת מחדל: IGN, Kotaku, Polygon, GameSpot ועוד.', 'content-verification-badge' ); ?></p>
				<p><textarea name="<?php echo esc_attr( PIV_Sources::OPTION_NEWS ); ?>" rows="10" class="large-text code"><?php echo esc_textarea( $news ); ?></textarea></p>

				<h2><?php esc_html_e( 'פידי RSS לאימות', 'content-verification-badge' ); ?></h2>
				<p class="description"><?php esc_html_e( 'כתובת פיד אחת בכל שורה. המערכת בודקת כותרות בפידים ומשווה לכותרת הפוסט — בלי תלות בחיפוש Google/Bing.', 'content-verification-badge' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'הפעל בדיקת RSS', 'content-verification-badge' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[check_rss]" value="yes" <?php checked( $settings['check_rss'] ?? 'yes', 'yes' ); ?>> <?php esc_html_e( 'סרוק פידי RSS בכל בדיקת פוסט', 'content-verification-badge' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_rss_feeds_per_post"><?php esc_html_e( 'כמה פידים לסרוק לפוסט', 'content-verification-badge' ); ?></label></th>
						<td><input type="number" min="1" max="40" id="piv_rss_feeds_per_post" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[rss_feeds_per_post]" value="<?php echo esc_attr( $settings['rss_feeds_per_post'] ?? 20 ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_rss_max_items"><?php esc_html_e( 'פריטים לכל פיד', 'content-verification-badge' ); ?></label></th>
						<td><input type="number" min="5" max="40" id="piv_rss_max_items" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[rss_max_items_per_feed]" value="<?php echo esc_attr( $settings['rss_max_items_per_feed'] ?? 15 ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="piv_rss_threshold"><?php esc_html_e( 'סף התאמת RSS (0–1)', 'content-verification-badge' ); ?></label></th>
						<td><input type="number" step="0.01" min="0.12" max="0.8" id="piv_rss_threshold" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[rss_match_threshold]" value="<?php echo esc_attr( $settings['rss_match_threshold'] ?? 0.2 ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'אימות ידני בפוסט', 'content-verification-badge' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( PIV_Helpers::OPTION_SETTINGS ); ?>[allow_manual_override]" value="yes" <?php checked( $settings['allow_manual_override'] ?? 'yes', 'yes' ); ?>> <?php esc_html_e( 'אפשר לעורך לבחור שכבה ידנית + קישור ראיה', 'content-verification-badge' ); ?></label></td>
					</tr>
				</table>
				<p><textarea name="<?php echo esc_attr( PIV_RSS::OPTION_FEEDS ); ?>" rows="12" class="large-text code"><?php echo esc_textarea( $rss ); ?></textarea></p>

				<?php submit_button( __( 'שמור הגדרות', 'content-verification-badge' ) ); ?>
			</form>

			<div class="piv-coverage-panel" data-piv-live-coverage>
				<h2><?php esc_html_e( 'כיסוי פוסטי חדשות', 'content-verification-badge' ); ?></h2>
				<p class="description"><?php esc_html_e( 'הוספה לתור אינה דורשת "שמור שינויים" — לחץ על הכפתור למטה. שמירת ההגדרות למעלה נעשית בנפרד.', 'content-verification-badge' ); ?></p>
				<?php self::render_coverage_progress( $coverage ); ?>
				<p>
					<button type="button" class="button button-primary" id="piv-enqueue-all-btn">
						<?php esc_html_e( 'הוסף את כל פוסטי החדשות לתור הבדיקה', 'content-verification-badge' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="piv-reset-all-btn">
						<?php esc_html_e( 'אפס הכל עכשיו', 'content-verification-badge' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="piv-test-connections-btn">
						<?php esc_html_e( 'בדיקת חיבורים', 'content-verification-badge' ); ?>
					</button>
					<span class="description" id="piv-enqueue-all-status" aria-live="polite"></span>
				</p>
				<div id="piv-test-connections-results" aria-live="polite"></div>
				<p class="description"><?php esc_html_e( '"אפס הכל עכשיו" מוחק מיד את כל תוצאות האימות, אימות ידני והקאש — ואז מכניס מחדש את הפוסטים לתור סריקה נקייה.', 'content-verification-badge' ); ?></p>
				<p class="description"><?php esc_html_e( 'הבדיקות רצות ברקע — 5 פוסטים כל כמה שניות (ברירת מחדל). הסרגל והמספרים מתעדכנים אוטומטית.', 'content-verification-badge' ); ?></p>
			</div>

			<hr>
			<h2><?php esc_html_e( 'שכבות אימות', 'content-verification-badge' ); ?></h2>
			<ul class="piv-layer-legend">
				<?php foreach ( PIV_Layers::all() as $slug => $layer ) : ?>
					<li class="piv-layer-legend__item piv-layer-legend__item--<?php echo esc_attr( $slug ); ?>">
						<span><?php echo esc_html( $layer['icon'] . ' ' . $layer['label'] ); ?></span>
						<em><?php echo esc_html( $layer['description'] ); ?></em>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Raw domain list for the settings textarea (saved values, not merged defaults).
	 *
	 * @return string
	 */
	public static function get_domains_textarea_value() {
		$saved = get_option( PIV_Sources::OPTION_PRIMARY, null );

		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return implode( "\n", $saved );
		}

		return implode( "\n", PIV_Sources::default_primary_domains() );
	}

	/**
	 * Raw gaming news domain list for the settings textarea.
	 *
	 * @return string
	 */
	public static function get_news_domains_textarea_value() {
		$saved = get_option( PIV_Sources::OPTION_NEWS, null );

		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return implode( "\n", $saved );
		}

		return implode( "\n", PIV_Sources::default_news_domains() );
	}

	/**
	 * RSS feed URLs for settings textarea.
	 *
	 * @return string
	 */
	public static function get_rss_feeds_textarea_value() {
		$saved = get_option( PIV_RSS::OPTION_FEEDS, null );

		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return implode( "\n", $saved );
		}

		return implode( "\n", PIV_RSS::default_feeds() );
	}

	public static function ajax_enqueue_all_posts() {
		check_ajax_referer( 'piv_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה', 'content-verification-badge' ) ), 403 );
		}

		$only_unverified = empty( $_POST['force_all'] );
		$enqueued        = PIV_Helpers::enqueue_eligible_posts(
			array(
				'only_unverified' => $only_unverified,
				'limit'           => 0,
				'priority'        => false,
			)
		);

		wp_send_json_success(
			array(
				'enqueued' => $enqueued,
				'coverage' => PIV_Helpers::get_coverage_payload( true ),
				'message'  => $enqueued > 0
					? sprintf(
						/* translators: %d: number of posts */
						_n( 'פוסט אחד נוסף לתור', '%d פוסטים נוספו לתור', $enqueued, 'content-verification-badge' ),
						$enqueued
					)
					: __( 'אין פוסטים חדשים להוספה — כולם כבר בתור או שכבר נבדקו.', 'content-verification-badge' ),
			)
		);
	}

	/**
	 * AJAX: wipe every verification result and restart the queue.
	 */
	public static function ajax_reset_all_verifications() {
		check_ajax_referer( 'piv_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה', 'content-verification-badge' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$result = PIV_Helpers::reset_all_verifications( true );

		wp_send_json_success(
			array(
				'cleared'  => (int) ( $result['cleared'] ?? 0 ),
				'enqueued' => (int) ( $result['enqueued'] ?? 0 ),
				'coverage' => PIV_Helpers::get_coverage_payload( true ),
				'message'  => sprintf(
					/* translators: 1: posts cleared, 2: posts enqueued */
					__( 'אופסה בדיקת %1$d פוסטים. %2$d פוסטים נכנסו לתור סריקה מחדש.', 'content-verification-badge' ),
					(int) ( $result['cleared'] ?? 0 ),
					(int) ( $result['enqueued'] ?? 0 )
				),
			)
		);
	}

	public static function ajax_recheck_post() {
		check_ajax_referer( 'piv_admin_actions', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה', 'content-verification-badge' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( ! empty( $_POST['precision'] ) ? 600 : 300 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$reset     = ! empty( $_POST['reset'] );
		$precision = ! empty( $_POST['precision'] );
		$context   = $precision ? 'precision' : 'manual';

		// Precision scan always starts clean so old weak results cannot stick.
		if ( $precision ) {
			$reset = true;
		}

		$result = PIV_Verifier::verify_post(
			$post_id,
			true,
			array(
				'context'      => $context,
				'reset'        => $reset,
				'clear_manual' => $reset,
			)
		);
		$html   = PIV_Helpers::get_badge_html( $post_id );

		wp_send_json_success(
			array(
				'layer'   => isset( $result['layer'] ) ? $result['layer'] : '',
				'label'   => PIV_Layers::label( isset( $result['layer'] ) ? $result['layer'] : '' ),
				'html'    => $html,
				'reasons' => isset( $result['reasons'] ) ? $result['reasons'] : array(),
				'reset'   => $reset,
			)
		);
	}

	public static function ajax_get_coverage_stats() {
		check_ajax_referer( 'piv_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה', 'content-verification-badge' ) ), 403 );
		}

		$force = ! empty( $_POST['force'] );
		wp_send_json_success( PIV_Helpers::get_coverage_payload( $force ) );
	}

	public static function ajax_get_report_rows() {
		check_ajax_referer( 'piv_admin_actions', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'אין הרשאה', 'content-verification-badge' ) ), 403 );
		}

		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
		$post_ids = array_values( array_filter( $post_ids ) );
		if ( empty( $post_ids ) ) {
			wp_send_json_success( array( 'rows' => array() ) );
		}

		$rows = array();
		foreach ( $post_ids as $post_id ) {
			$row = PIV_Admin_Report::format_row_for_client( $post_id );
			if ( ! empty( $row ) ) {
				$rows[ (string) $post_id ] = $row;
			}
		}

		wp_send_json_success(
			array(
				'rows'     => $rows,
				'coverage' => PIV_Helpers::get_coverage_payload( ! empty( $_POST['force'] ) ),
			)
		);
	}
}
