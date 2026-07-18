<?php
/**
 * Scheduled recheck of open verification statuses.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Cron {

	const HOOK_RECHECK = 'piv_recheck_open_posts';

	public static function init() {
		add_action( self::HOOK_RECHECK, array( __CLASS__, 'recheck_open_posts' ) );
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::HOOK_RECHECK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_RECHECK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK_RECHECK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_RECHECK );
		}
	}

	/**
	 * Re-verify posts that are not fully verified yet.
	 */
	public static function recheck_open_posts() {
		$layers = array(
			PIV_Layers::UNDER_REVIEW,
			PIV_Layers::INITIAL_REPORT,
			PIV_Layers::TRUSTED_SOURCE,
		);

		$query = new WP_Query(
			array(
				'post_type'      => PIV_Helpers::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => PIV_META_LAYER,
						'value'   => $layers,
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			if ( PIV_Helpers::is_excluded_post( $post_id ) ) {
				PIV_Helpers::clear_verification_meta( $post_id );
				continue;
			}

			if ( ! PIV_Helpers::is_eligible_for_verification( $post_id ) ) {
				continue;
			}

			if ( PIV_Helpers::should_run_verification( $post_id ) ) {
				PIV_Queue::enqueue( $post_id );
			}
		}

		PIV_Helpers::enqueue_eligible_posts(
			array(
				'only_unverified' => true,
				'limit'           => 50,
				'priority'        => false,
			)
		);
	}
}
