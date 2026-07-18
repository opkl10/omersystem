<?php
/**
 * Background verification queue — avoids blocking admin saves and page views.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Queue {

	const OPTION_QUEUE = 'piv_verify_queue';
	const HOOK_PROCESS = 'piv_process_verify_queue';
	const CRON_SCHEDULE = 'piv_five_minutes';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process_batch' ) );
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
