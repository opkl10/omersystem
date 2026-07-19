<?php
/**
 * Plugin Name:       Content Verification Badge
 * Plugin URI:        https://example.com/content-verification-badge
 * Description:       סימון אוטומטי של רמת אימות — מחפש מקורות חיצוניים לפי כותרת הפוסט ומשווה את התוכן.
 * Version:           1.12.2
 * Author:            Omer Okon
 * Author URI:        https://example.com
 * License:           GPL-2.0+
 * Text Domain:       content-verification-badge
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIV_VERSION', '1.12.2' );
define( 'PIV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'PIV_META_LAYER', '_piv_layer' );
define( 'PIV_META_CHECKED_AT', '_piv_checked_at' );
define( 'PIV_META_RESULT', '_piv_result_json' );
define( 'PIV_META_STATUS', '_piv_status' );
define( 'PIV_META_CONTENT_HASH', '_piv_content_hash' );
define( 'PIV_META_MANUAL_LAYER', '_piv_manual_layer' );
define( 'PIV_META_MANUAL_EVIDENCE', '_piv_manual_evidence' );
define( 'PIV_META_MANUAL_NOTE', '_piv_manual_note' );
define( 'PIV_META_CACHE_NONCE', '_piv_cache_nonce' );

require_once PIV_PLUGIN_DIR . 'includes/class-piv-layers.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-sources.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-search.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-rss.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-verifier.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-match.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-gemini.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-helpers.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-cron.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-queue.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-admin.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-admin-report.php';
require_once PIV_PLUGIN_DIR . 'includes/class-piv-meta-box.php';

PIV_Cron::init();
PIV_Queue::init();
PIV_Admin::init();
PIV_Admin_Report::init();
PIV_Meta_Box::init();

add_action( 'plugins_loaded', 'piv_maybe_upgrade_domain_lists', 5 );
add_action( 'admin_notices', 'piv_cron_disabled_notice' );

/**
 * Warn admins when WP-Cron is disabled without an alternative — verification
 * runs entirely through cron, so nothing will ever be checked.
 */
function piv_cron_disabled_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
		return;
	}
	if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && false === strpos( (string) $screen->id, 'piv' ) && ! in_array( $screen->id, array( 'edit-post', 'dashboard', 'plugins' ), true ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'אימות מידע: DISABLE_WP_CRON מופעל באתר. התוסף מסתמך על WP-Cron להרצת בדיקות — ודא שמוגדר cron אמיתי בשרת שקורא ל-wp-cron.php כל דקה, אחרת פוסטים לא ייבדקו.', 'content-verification-badge' );
	echo '</p></div>';
}

/**
 * Add newly shipped domains to saved lists after plugin update.
 */
function piv_maybe_upgrade_domain_lists() {
	PIV_Sources::maybe_upgrade_domain_lists();
	PIV_Helpers::maybe_migrate_verification_thresholds();
}

add_action( 'set_object_terms', 'piv_on_terms_changed', 10, 6 );
add_action( 'updated_post_meta', 'piv_on_exclusion_meta_updated', 10, 4 );
add_action( 'added_post_meta', 'piv_on_exclusion_meta_updated', 10, 4 );

/**
 * Clear verification when a review-box (or similar) flag is enabled.
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $object_id  Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 */
function piv_on_exclusion_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
	unset( $meta_id );

	$enable_keys = PIV_Helpers::get_excluded_post_meta_keys();
	$score_keys  = PIV_Helpers::get_excluded_review_score_meta_keys();

	if ( ! in_array( $meta_key, $enable_keys, true ) && ! in_array( $meta_key, $score_keys, true ) ) {
		return;
	}

	$should_clear = in_array( $meta_key, $score_keys, true )
		? ( '' !== (string) $meta_value && null !== $meta_value )
		: PIV_Helpers::is_truthy_meta_value( $meta_value );

	if ( ! $should_clear ) {
		return;
	}

	$object_id = absint( $object_id );
	if ( ! $object_id || ! in_array( get_post_type( $object_id ), PIV_Helpers::post_types(), true ) ) {
		return;
	}

	PIV_Helpers::clear_verification_meta( $object_id );
}

/**
 * Clear verification when a post is assigned to an excluded category/tag.
 *
 * @param int    $object_id  Object ID.
 * @param array  $terms      Term IDs.
 * @param array  $tt_ids     Term taxonomy IDs.
 * @param string $taxonomy   Taxonomy slug.
 * @param bool   $append     Whether terms are appended.
 * @param array  $old_tt_ids Old term taxonomy IDs.
 */
function piv_on_terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	unset( $terms, $tt_ids, $taxonomy, $append, $old_tt_ids );

	$object_id = absint( $object_id );
	if ( ! $object_id || ! in_array( get_post_type( $object_id ), PIV_Helpers::post_types(), true ) ) {
		return;
	}

	if ( PIV_Helpers::is_excluded_post( $object_id ) ) {
		PIV_Helpers::clear_verification_meta( $object_id );
	}
}

register_activation_hook( __FILE__, 'piv_on_activate' );

/**
 * Schedule cron and queue verification for existing published posts.
 */
function piv_on_activate() {
	PIV_Cron::activate();
	PIV_Queue::activate();

	PIV_Helpers::enqueue_eligible_posts(
		array(
			'only_unverified' => true,
			'limit'           => 0,
			'priority'        => false,
		)
	);

	PIV_Helpers::purge_verification_for_excluded_posts();
}

register_deactivation_hook( __FILE__, 'piv_on_deactivate' );

/**
 * Clear scheduled events on deactivation.
 */
function piv_on_deactivate() {
	PIV_Cron::deactivate();
	PIV_Queue::deactivate();
}

add_action( 'wp_enqueue_scripts', 'piv_register_styles' );
add_action( 'elementor/frontend/after_register_styles', 'piv_register_styles' );

/**
 * Register front-end stylesheet.
 */
function piv_register_styles() {
	wp_register_style(
		'piv-style',
		PIV_PLUGIN_URL . 'assets/css/piv.css',
		array(),
		PIV_VERSION
	);
}

add_action( 'save_post', 'piv_schedule_verification_on_save', 20, 3 );

/**
 * Queue verification when a supported post is published or updated.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an existing post being updated.
 */
function piv_schedule_verification_on_save( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, PIV_Helpers::post_types(), true ) ) {
		return;
	}

	if ( 'publish' !== $post->post_status ) {
		return;
	}

	if ( ! PIV_Helpers::is_eligible_for_verification( $post_id ) ) {
		if ( PIV_Helpers::is_excluded_post( $post_id ) ) {
			PIV_Helpers::clear_verification_meta( $post_id );
		}
		return;
	}

	if ( ! PIV_Helpers::post_content_changed( $post_id, $post ) && PIV_Helpers::get_layer( $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, PIV_META_STATUS, 'checking' );
	update_post_meta( $post_id, PIV_META_LAYER, 'under_review' );

	$settings = PIV_Helpers::get_settings();
	$review   = max( 1, (int) $settings['review_window'] ) * MINUTE_IN_SECONDS;

	PIV_Queue::enqueue( $post_id, true );
	wp_schedule_single_event( time() + $review + 10, 'piv_verify_post', array( $post_id ) );
}

/**
 * Legacy hook — queue a single post verification.
 *
 * @param int $post_id Post ID.
 */
function piv_queue_verification( $post_id ) {
	PIV_Queue::enqueue( $post_id, true );
}

add_action( 'piv_verify_post', 'piv_run_scheduled_verification' );

/**
 * Cron/single-event verification entry point.
 *
 * @param int $post_id Post ID.
 */
function piv_run_scheduled_verification( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return;
	}

	if ( ! PIV_Helpers::should_run_verification( $post_id ) ) {
		return;
	}

	PIV_Verifier::verify_post( $post_id );
}

add_action( 'wp_enqueue_scripts', 'piv_maybe_enqueue_front_styles' );
add_filter( 'the_title', 'piv_append_badge_to_title', 20, 2 );
add_filter( 'render_block', 'piv_render_block_post_title', 10, 2 );
add_action( 'wp', 'piv_maybe_verify_on_view' );
add_action( 'elementor/loaded', 'piv_register_elementor_hooks' );

/**
 * Register Elementor-specific display hooks.
 */
function piv_register_elementor_hooks() {
	add_action( 'elementor/frontend/widget/before_render', 'piv_elementor_widget_before_render', 5 );
	add_action( 'elementor/frontend/widget/after_render', 'piv_elementor_widget_after_render', 99 );
	add_filter( 'elementor/frontend/widget/render_content', 'piv_elementor_widget_render_content', 15, 2 );
}

/**
 * Enqueue badge styles on pages that may show badges.
 */
function piv_maybe_enqueue_front_styles() {
	if ( is_admin() ) {
		return;
	}

	$settings = PIV_Helpers::get_settings();
	if ( 'yes' !== ( $settings['show_on_title'] ?? 'yes' ) ) {
		return;
	}

	if ( is_singular( PIV_Helpers::post_types() ) || is_front_page() || is_home() ) {
		wp_enqueue_style( 'piv-style' );
	}
}

/**
 * Queue verification for published posts that were never checked.
 */
function piv_maybe_verify_on_view() {
	if ( is_admin() || ! is_singular( PIV_Helpers::post_types() ) ) {
		return;
	}

	$settings = PIV_Helpers::get_settings();
	if ( 'yes' !== ( $settings['verify_on_view'] ?? 'no' ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id || PIV_Helpers::is_excluded_post( $post_id ) ) {
		return;
	}

	if ( ! PIV_Helpers::should_run_verification( $post_id ) ) {
		return;
	}

	update_post_meta( $post_id, PIV_META_STATUS, 'checking' );
	update_post_meta( $post_id, PIV_META_LAYER, 'under_review' );
	PIV_Queue::enqueue( $post_id, true );
}

/**
 * Append inline badge after post titles.
 *
 * @param string $title   Post title.
 * @param int    $post_id Post ID.
 * @return string
 */
function piv_append_badge_to_title( $title, $post_id ) {
	$settings = PIV_Helpers::get_settings();
	if ( 'yes' !== ( $settings['show_on_title'] ?? 'yes' ) ) {
		return $title;
	}

	if ( ! apply_filters( 'piv_auto_inject_title_badge', true ) ) {
		return $title;
	}

	// Hard rule: never touch listing/loop titles — only the singular post being viewed.
	if ( ! is_singular( PIV_Helpers::post_types() ) ) {
		return $title;
	}

	if ( doing_filter( 'nav_menu_item_title' ) || doing_filter( 'widget_title' ) ) {
		return $title;
	}

	$post_id = absint( $post_id );
	if ( ! $post_id || (int) get_queried_object_id() !== $post_id ) {
		return $title;
	}

	if ( ! in_array( get_post_type( $post_id ), PIV_Helpers::post_types(), true ) ) {
		return $title;
	}

	if ( PIV_Helpers::is_badge_display_suppressed() ) {
		return $title;
	}

	// Strip accidental HTML injection when a widget escaped the title string.
	if ( false !== strpos( $title, 'piv-marker' ) || false !== strpos( $title, 'piv-title-badge-glue' ) ) {
		$title = preg_replace( '#\s*<span[^>]*piv-(?:marker|title-badge-glue)[\s\S]*$#u', '', $title );
		$title = preg_replace( '#\s*<span[^>]*piv-(?:marker|title-badge-glue)[\s\S]*#u', '', $title );
	}

	if ( ! PIV_Helpers::should_show_frontend_badge( $post_id ) ) {
		return $title;
	}

	$badge = PIV_Helpers::get_inline_badge_html( $post_id );
	if ( ! $badge ) {
		return $title;
	}

	if ( false !== strpos( $title, 'piv-marker' ) || false !== strpos( $title, 'piv-inline-badge' ) ) {
		return $title;
	}

	wp_enqueue_style( 'piv-style' );

	return PIV_Helpers::append_badge_to_title_text( $title, $badge );
}

/**
 * Append badge to Gutenberg post-title blocks.
 *
 * @param string $block_content Block HTML.
 * @param array  $block         Block data.
 * @return string
 */
function piv_render_block_post_title( $block_content, $block ) {
	if ( empty( $block['blockName'] ) || 'core/post-title' !== $block['blockName'] ) {
		return $block_content;
	}

	$settings = PIV_Helpers::get_settings();
	if ( 'yes' !== ( $settings['show_on_title'] ?? 'yes' ) ) {
		return $block_content;
	}

	$post_id = 0;
	if ( ! empty( $block['context']['postId'] ) ) {
		$post_id = (int) $block['context']['postId'];
	} elseif ( in_the_loop() ) {
		$post_id = get_the_ID();
	}

	if ( ! $post_id || ! PIV_Helpers::should_show_frontend_badge( $post_id ) ) {
		return $block_content;
	}

	$badge = PIV_Helpers::get_inline_badge_html( $post_id );
	if ( ! $badge || ( false !== strpos( $block_content, 'piv-marker' ) || false !== strpos( $block_content, 'piv-inline-badge' ) ) ) {
		return $block_content;
	}

	wp_enqueue_style( 'piv-style' );

	return PIV_Helpers::append_badge_to_markup( $block_content, $badge );
}

/**
 * Suppress badge HTML inside Elementor listing/loop widgets.
 * Injecting into the_title there can break card markup and look like duplicated items.
 *
 * @param \Elementor\Element_Base $widget Widget instance.
 */
function piv_elementor_widget_before_render( $widget ) {
	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
		return;
	}

	$widget_name   = $widget->get_name();
	$title_widgets = array( 'theme-post-title', 'post-title' );

	// Only single-post title widgets may receive badges; suppress everywhere else in Elementor.
	if ( ! in_array( $widget_name, $title_widgets, true ) ) {
		PIV_Helpers::suppress_badge_display();
		return;
	}

	// Even title widgets: only on the singular post being viewed.
	if ( ! is_singular( PIV_Helpers::post_types() ) ) {
		PIV_Helpers::suppress_badge_display();
		return;
	}

	wp_enqueue_style( 'piv-style' );
}

/**
 * Restore badge display after Elementor widget render.
 *
 * @param \Elementor\Element_Base $widget Widget instance.
 */
function piv_elementor_widget_after_render( $widget ) {
	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
		return;
	}

	$widget_name   = $widget->get_name();
	$title_widgets = array( 'theme-post-title', 'post-title' );

	if ( ! in_array( $widget_name, $title_widgets, true ) || ! is_singular( PIV_Helpers::post_types() ) ) {
		PIV_Helpers::restore_badge_display();
	}
}

/**
 * Inject badge into Elementor post title widgets (singular posts only).
 *
 * @param string                  $content Widget HTML.
 * @param \Elementor\Element_Base $widget  Widget instance.
 * @return string
 */
function piv_elementor_widget_render_content( $content, $widget ) {
	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
		return $content;
	}

	$title_widgets = array( 'theme-post-title', 'post-title' );
	if ( ! in_array( $widget->get_name(), $title_widgets, true ) ) {
		return $content;
	}

	if ( ! is_singular( PIV_Helpers::post_types() ) ) {
		return $content;
	}

	$settings = PIV_Helpers::get_settings();
	if ( 'yes' !== ( $settings['show_on_title'] ?? 'yes' ) ) {
		return $content;
	}

	$post_id = get_the_ID();
	if ( ! $post_id || (int) get_queried_object_id() !== (int) $post_id ) {
		return $content;
	}

	if ( ! PIV_Helpers::should_show_frontend_badge( $post_id ) ) {
		return $content;
	}

	$badge = PIV_Helpers::get_inline_badge_html( $post_id );
	if ( ! $badge || ( false !== strpos( $content, 'piv-marker' ) || false !== strpos( $content, 'piv-inline-badge' ) ) ) {
		return $content;
	}

	wp_enqueue_style( 'piv-style' );

	return PIV_Helpers::append_badge_to_markup( $content, $badge );
}

add_shortcode( 'piv_badge', 'piv_shortcode_badge' );

/**
 * Shortcode: [piv_badge] or [piv_badge post_id="123"]
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function piv_shortcode_badge( $atts ) {
	$atts = shortcode_atts(
		array(
			'post_id' => get_the_ID(),
		),
		$atts,
		'piv_badge'
	);

	$post_id = absint( $atts['post_id'] );
	if ( ! $post_id ) {
		return '';
	}

	wp_enqueue_style( 'piv-style' );
	return PIV_Helpers::get_badge_html( $post_id );
}

add_action( 'elementor/widgets/register', 'piv_register_elementor_widgets' );

/**
 * Register Elementor widget.
 *
 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
 */
function piv_register_elementor_widgets( $widgets_manager ) {
	if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
		return;
	}

	require_once PIV_PLUGIN_DIR . 'includes/class-piv-elementor-widget.php';
	$widgets_manager->register( new PIV_Elementor_Widget() );
}

add_action( 'elementor/elements/categories_registered', 'piv_add_elementor_category' );

/**
 * Add Elementor widget category.
 *
 * @param \Elementor\Elements_Manager $elements_manager Elements manager.
 */
function piv_add_elementor_category( $elements_manager ) {
	$elements_manager->add_category(
		'piv-widgets',
		array(
			'title' => esc_html__( 'אימות מידע', 'content-verification-badge' ),
			'icon'  => 'fa fa-shield',
		)
	);
}
