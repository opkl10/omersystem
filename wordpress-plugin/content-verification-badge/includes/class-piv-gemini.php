<?php
/**
 * Gemini AI semantic comparison for source relevance.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Gemini {

	/**
	 * Known-good model used when the configured model id returns 404.
	 */
	const FALLBACK_MODEL = 'gemini-2.5-flash';

	/**
	 * Whether Gemini comparison is available with current settings.
	 *
	 * @param array|null $settings Settings.
	 * @return bool
	 */
	public static function is_enabled( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = PIV_Helpers::get_settings();
		}

		if ( 'yes' !== ( $settings['gemini_enabled'] ?? 'no' ) ) {
			return false;
		}

		return '' !== trim( (string) ( $settings['gemini_api_key'] ?? '' ) );
	}

	/**
	 * Compare post content to a candidate source via Gemini.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $source   Source row (title/body/url).
	 * @param array   $settings Plugin settings.
	 * @return array{same_story:bool,confidence:float,reason:string,error:string,raw?:string}
	 */
	public static function compare_source( $post, $source, $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = PIV_Helpers::get_settings();
		}

		$empty = array(
			'same_story' => false,
			'confidence' => 0.0,
			'reason'     => '',
			'error'      => 'disabled',
		);

		if ( ! self::is_enabled( $settings ) || ! ( $post instanceof WP_Post ) ) {
			return $empty;
		}

		$api_key = trim( (string) ( $settings['gemini_api_key'] ?? '' ) );
		$model   = preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) ( $settings['gemini_model'] ?? 'gemini-3.1-pro-preview' ) );
		if ( '' === $model ) {
			$model = 'gemini-3.1-pro-preview';
		}

		$post_text = PIV_Verifier::get_post_comparison_text( $post, 2200 );
		$src_title = isset( $source['title'] ) ? (string) $source['title'] : '';
		$src_body  = isset( $source['body_text'] ) ? (string) $source['body_text'] : '';
		if ( '' === $src_body && ! empty( $source['body_excerpt'] ) ) {
			$src_body = (string) $source['body_excerpt'];
		}
		$src_url = isset( $source['url'] ) ? (string) $source['url'] : '';

		if ( '' === trim( $post_text ) || ( '' === trim( $src_title ) && '' === trim( $src_body ) ) ) {
			return array(
				'same_story' => false,
				'confidence' => 0.0,
				'reason'     => 'missing_text',
				'error'      => 'missing_text',
			);
		}

		$cache_key = 'piv_gem_' . md5(
			$model . '|' . (int) $post->ID . '|' . PIV_Helpers::get_post_content_hash( $post ) . '|' . $src_url . '|' . md5( $src_title . $src_body )
		);
		if ( empty( $settings['bypass_fetch_cache'] ) && empty( $settings['bypass_search_cache'] ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['same_story'] ) ) {
				return $cached;
			}
		}

		$prompt = self::build_prompt( $post, $post_text, $src_title, $src_body, $src_url );
		$result = self::request_json( $api_key, $model, $prompt );

		if ( ! empty( $result['error'] ) && empty( $result['same_story'] ) && empty( $result['confidence'] ) ) {
			set_transient( $cache_key, $result, 20 * MINUTE_IN_SECONDS );
			return $result;
		}

		set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * @param WP_Post $post      Post.
	 * @param string  $post_text Post comparison text.
	 * @param string  $src_title Source title.
	 * @param string  $src_body  Source body.
	 * @param string  $src_url   Source URL.
	 * @return string
	 */
	private static function build_prompt( $post, $post_text, $src_title, $src_body, $src_url ) {
		$post_title = wp_strip_all_tags( (string) $post->post_title );

		return implode(
			"\n",
			array(
				'You are a news verification assistant for a Hebrew gaming news site.',
				'Decide if the CANDIDATE SOURCE is about the SAME specific news story as the SITE POST.',
				'Same franchise/platform alone is NOT enough. Reject unrelated articles.',
				'Reply with ONLY valid JSON, no markdown, shape:',
				'{"same_story":true|false,"confidence":0.0-1.0,"reason":"short Hebrew explanation"}',
				'',
				'SITE POST TITLE:',
				$post_title,
				'',
				'SITE POST CONTENT:',
				mb_substr( $post_text, 0, 2200, 'UTF-8' ),
				'',
				'CANDIDATE SOURCE URL:',
				$src_url,
				'',
				'CANDIDATE SOURCE TITLE:',
				$src_title,
				'',
				'CANDIDATE SOURCE CONTENT:',
				mb_substr( $src_body, 0, 2200, 'UTF-8' ),
			)
		);
	}

	/**
	 * Call Gemini generateContent and parse JSON.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model id.
	 * @param string $prompt  Prompt text.
	 * @return array
	 */
	private static function request_json( $api_key, $model, $prompt ) {
		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
			rawurlencode( $model )
		);

		$body = array(
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.2,
				'maxOutputTokens'  => 1024,
				'responseMimeType' => 'application/json',
			),
		);

		// Strongest Pro models benefit from higher thinking for story matching.
		if ( false !== strpos( $model, 'pro' ) ) {
			$body['generationConfig']['thinkingConfig'] = array(
				'thinkingLevel' => 'HIGH',
			);
		}

		$timeout = ( false !== strpos( $model, 'pro' ) ) ? 60 : 30;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'same_story' => false,
				'confidence' => 0.0,
				'reason'     => '',
				'error'      => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			// Unknown/retired model id — retry once with a known-good model instead
			// of silently losing the semantic comparison.
			if ( 404 === $code && self::FALLBACK_MODEL !== $model ) {
				return self::request_json( $api_key, self::FALLBACK_MODEL, $prompt );
			}

			return array(
				'same_story' => false,
				'confidence' => 0.0,
				'reason'     => '',
				'error'      => 'HTTP ' . $code . ': ' . mb_substr( wp_strip_all_tags( $raw ), 0, 160, 'UTF-8' ),
			);
		}

		$data = json_decode( $raw, true );
		$text = '';
		if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( ! empty( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		$parsed = self::parse_model_json( $text );
		if ( null === $parsed ) {
			return array(
				'same_story' => false,
				'confidence' => 0.0,
				'reason'     => '',
				'error'      => 'bad_json',
				'raw'        => mb_substr( $text, 0, 300, 'UTF-8' ),
			);
		}

		return array(
			'same_story' => ! empty( $parsed['same_story'] ),
			'confidence' => max( 0, min( 1, (float) ( $parsed['confidence'] ?? 0 ) ) ),
			'reason'     => sanitize_text_field( (string) ( $parsed['reason'] ?? '' ) ),
			'error'      => '',
		);
	}

	/**
	 * @param string $text Model text.
	 * @return array|null
	 */
	private static function parse_model_json( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}

		// Strip markdown fences if the model ignored responseMimeType.
		if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
			$text = $m[0];
		}

		$data = json_decode( $text, true );
		return is_array( $data ) ? $data : null;
	}
}
