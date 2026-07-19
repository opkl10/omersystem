<?php
/**
 * Background verification queue — avoids blocking admin saves and page views.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Queue {

	const OPTION_QUEUE  = 'piv_verify_queue';
	const HOOK_PROCESS  = 'piv_process_verify_queue';
	const CRON_SCHEDULE = 'piv_five_minutes';
	const OPTION_TOKEN  = 'piv_queue_async_token';
	const LOCK_KEY      = 'piv_queue_running';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process_batch' ) );

		// Async loopback runner — processes the queue even when WP-Cron never fires.
		add_action( 'wp_ajax_piv_run_queue', array( __CLASS__, 'handle_async_run' ) );
		add_action( 'wp_ajax_nopriv_piv_run_queue', array( __CLASS__, 'handle_async_run' ) );

		// Watchdog: any admin page view restarts a stalled queue.
		add_action( 'admin_init', array( __CLASS__, 'admin_watchdog' ) );
	}

	/**
	 * Secret token that authorizes async queue runs (loopback requests are unauthenticated).
	 *
	 * @return string
	 */
	public static function get_async_token() {
		$token = get_option( self::OPTION_TOKEN );
		if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
			$token = wp_generate_password( 64, false, false );
			update_option( self::OPTION_TOKEN, $token, false );
		}

		return $token;
	}

	/**
	 * Fire a non-blocking loopback request that processes the queue in the background.
	 */
	public static function dispatch_async() {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'piv_run_queue',
					'token'  => self::get_async_token(),
				),
			)
		);
	}

	/**
	 * Async endpoint: process one batch, then chain the next run until the queue drains.
	 */
	public static function handle_async_run() {
		$token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! hash_equals( self::get_async_token(), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// One runner at a time.
		if ( get_transient( self::LOCK_KEY ) ) {
			wp_die();
		}
		set_transient( self::LOCK_KEY, 1, 2 * MINUTE_IN_SECONDS );

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}

		self::process_batch();

		delete_transient( self::LOCK_KEY );

		if ( self::queue_count() > 0 ) {
			self::dispatch_async();
		}

		wp_die();
	}

	/**
	 * Restart queue processing from normal admin traffic when cron is dead.
	 */
	public static function admin_watchdog() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		if ( self::queue_count() > 0 ) {
			if ( get_transient( 'piv_queue_watchdog' ) ) {
				return;
			}
			set_transient( 'piv_queue_watchdog', 1, MINUTE_IN_SECONDS );
			self::kick();
			return;
		}

		// Empty queue: rescue posts stuck in "checking"/"under review" that cron never
		// picked up (they already have a layer, so the unverified backfill skips them).
		if ( get_transient( 'piv_queue_rescue' ) ) {
			return;
		}
		set_transient( 'piv_queue_rescue', 1, 10 * MINUTE_IN_SECONDS );

		if ( class_exists( 'PIV_Cron' ) ) {
			PIV_Cron::recheck_open_posts();
		}
	}

	/**
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'כל 5 דקות (אימות מידע)', 'content-verification-badge' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule the queue processor.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::HOOK_PROCESS );
		}
	}

	/**
	 * Clear scheduled processor.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK_PROCESS );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_PROCESS );
		}
	}

	/**
	 * @return array<int>
	 */
	public static function get_queue() {
		$queue = get_option( self::OPTION_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $queue ) ) ) );
	}

	/**
	 * Add a post to the background verification queue.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $priority If true, prepend to queue.
	 */
	public static function enqueue( $post_id, $priority = false ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		$queue = self::get_queue();
		$queue = array_values( array_diff( $queue, array( $post_id ) ) );

		if ( $priority ) {
			array_unshift( $queue, $post_id );
		} else {
			$queue[] = $post_id;
		}

		update_option( self::OPTION_QUEUE, $queue, false );
		self::kick();
		PIV_Helpers::invalidate_verification_stats_cache();
	}

	/**
	 * Add multiple posts to the queue in one write.
	 *
	 * @param array $post_ids Post IDs.
	 * @param bool  $priority Prepend to queue.
	 * @return int Number of posts added.
	 */
	public static function enqueue_many( $post_ids, $priority = false ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return 0;
		}

		$queue = self::get_queue();
		$queue = array_values( array_diff( $queue, $post_ids ) );

		if ( $priority ) {
			$queue = array_merge( $post_ids, $queue );
		} else {
			$queue = array_merge( $queue, $post_ids );
		}

		$queue = array_values( array_unique( $queue ) );
		update_option( self::OPTION_QUEUE, $queue, false );
		self::kick();
		PIV_Helpers::invalidate_verification_stats_cache();

		return count( $post_ids );
	}

	/**
	 * Ensure a near-term queue run is scheduled, and nudge WP-Cron to fire.
	 */
	public static function kick() {
		if ( ! wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_schedule_single_event( time() + 15, self::HOOK_PROCESS );
		}

		// Low-traffic sites may never trigger wp-cron on their own; fire due events now.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		// Cron-independent path: async loopback runner picks the queue up immediately.
		self::dispatch_async();
	}

	/**
	 * Process a small batch from the queue.
	 */
	public static function process_batch() {
		$settings = PIV_Helpers::get_settings();
		$batch    = max( 1, min( 15, (int) ( $settings['queue_batch_size'] ?? 5 ) ) );
		$queue    = self::get_queue();

		if ( empty( $queue ) ) {
			return;
		}

		$batch_ids = array_slice( $queue, 0, $batch );
		$remaining = array_slice( $queue, $batch );

		update_option( self::OPTION_QUEUE, $remaining, false );

		foreach ( $batch_ids as $post_id ) {
			if ( ! PIV_Helpers::is_eligible_for_verification( $post_id ) ) {
				continue;
			}

			if ( PIV_Helpers::should_run_verification( $post_id ) ) {
				// Force after global reset: ignore stale "already checked" windows.
				PIV_Verifier::verify_post( $post_id, true );
			}
		}

		if ( empty( $remaining ) ) {
			$backfill = (int) apply_filters( 'piv_queue_backfill_batch', 15 );
			if ( $backfill > 0 ) {
				PIV_Helpers::enqueue_eligible_posts(
					array(
						'only_unverified' => true,
						'limit'           => $backfill,
						'priority'        => false,
					)
				);
				$remaining = self::get_queue();
			}
		}

		if ( ! empty( $remaining ) ) {
			wp_schedule_single_event( time() + 20, self::HOOK_PROCESS );
		}
	}

	/**
	 * @return int
	 */
	public static function queue_count() {
		return count( self::get_queue() );
	}
}
