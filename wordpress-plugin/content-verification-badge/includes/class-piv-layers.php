<?php
/**
 * Verification layer definitions.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Layers {

	const INITIAL_REPORT  = 'initial_report';
	const UNDER_REVIEW    = 'under_review';
	const TRUSTED_SOURCE  = 'trusted_source';
	const VERIFIED        = 'verified';

	/**
	 * Layer metadata keyed by slug.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			self::INITIAL_REPORT => array(
				'label'       => __( 'דיווח ראשוני', 'content-verification-badge' ),
				'short_label' => __( 'ראשוני', 'content-verification-badge' ),
				'description' => __( 'מידע מוקדם בלי כיסוי חיצוני שפורסם עדיין.', 'content-verification-badge' ),
				'icon'        => '🟡',
				'color'       => '#f5b301',
				'bg'          => '#fff9e6',
				'priority'    => 1,
			),
			self::UNDER_REVIEW => array(
				'label'       => __( 'נמצא בבדיקה', 'content-verification-badge' ),
				'short_label' => __( 'בבדיקה', 'content-verification-badge' ),
				'description' => __( 'המערכת בודקת את המידע מול מקורות חיצוניים.', 'content-verification-badge' ),
				'icon'        => '🔵',
				'color'       => '#1e88e5',
				'bg'          => '#e8f3fd',
				'priority'    => 2,
			),
			self::TRUSTED_SOURCE => array(
				'label'       => __( 'דיווח ממקור מהימן', 'content-verification-badge' ),
				'short_label' => __( 'מקור מהימן', 'content-verification-badge' ),
				'description' => __( 'המידע כבר פורסם במקורות חדשות / רשמיים חיצוניים.', 'content-verification-badge' ),
				'icon'        => '🟠',
				'color'       => '#fb8c00',
				'bg'          => '#fff3e0',
				'priority'    => 3,
			),
			self::VERIFIED => array(
				'label'       => __( 'מידע מאומת', 'content-verification-badge' ),
				'short_label' => __( 'מאומת', 'content-verification-badge' ),
				'description' => __( 'אומת מול מקור רשמי של המפתחת / הסטודיו / החברה שנפתח ונבדק.', 'content-verification-badge' ),
				'icon'        => '🟢',
				'color'       => '#2e7d32',
				'bg'          => '#e8f5e9',
				'priority'    => 4,
			),
		);
	}

	/**
	 * Get one layer definition.
	 *
	 * @param string $slug Layer slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Human-readable label for a layer slug.
	 *
	 * @param string $slug Layer slug.
	 * @return string
	 */
	public static function label( $slug ) {
		$layer = self::get( $slug );
		return $layer ? $layer['label'] : '';
	}
}
