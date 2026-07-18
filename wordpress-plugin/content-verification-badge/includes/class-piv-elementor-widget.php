<?php
/**
 * Elementor widget: Verification Badge.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class PIV_Elementor_Widget extends Widget_Base {

	public function get_name() {
		return 'piv_verification_badge';
	}

	public function get_title() {
		return esc_html__( 'תג אימות מידע', 'content-verification-badge' );
	}

	public function get_icon() {
		return 'eicon-check-circle';
	}

	public function get_categories() {
		return array( 'general', 'piv-widgets' );
	}

	public function get_keywords() {
		return array( 'verification', 'אימות', 'מידע', 'חדשות', 'badge', 'fact' );
	}

	public function get_style_depends() {
		return array( 'piv-style' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'תוכן', 'content-verification-badge' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_evidence',
			array(
				'label'        => esc_html__( 'הצג מקורות', 'content-verification-badge' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'כן', 'content-verification-badge' ),
				'label_off'    => esc_html__( 'לא', 'content-verification-badge' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_timestamp',
			array(
				'label'        => esc_html__( 'הצג זמן בדיקה', 'content-verification-badge' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'כן', 'content-verification-badge' ),
				'label_off'    => esc_html__( 'לא', 'content-verification-badge' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		// Marker is injected automatically next to post titles only.
	}
}
