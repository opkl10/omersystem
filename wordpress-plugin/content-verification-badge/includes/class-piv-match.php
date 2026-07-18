<?php
/**
 * Source matching — discovery (wide) vs acceptance (precise).
 *
 * Tuned for Hebrew posts vs English news: bridge tokens (Latin/aliases)
 * count as title anchors so real same-story hits are not missed.
 * Trusted badge still requires a fetched news/official page.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Match {

	/**
	 * Filter discovery URLs — keep article candidates that aren't obviously wrong.
	 *
	 * @param array   $urls      Candidate URLs.
	 * @param WP_Post $post      Post.
	 * @param int     $max       Max to keep.
	 * @param float   $min_score Soft floor.
	 * @return array
	 */
	public static function filter_discovery_urls( $urls, $post, $max = 8, $min_score = 0.06 ) {
		$urls = array_values( array_unique( array_filter( (array) $urls ) ) );
		$max  = max( 1, (int) $max );

		if ( empty( $urls ) || ! ( $post instanceof WP_Post ) ) {
			return array();
		}

		$query_text = PIV_Search::build_query( $post );
		if ( '' === $query_text ) {
			$query_text = wp_strip_all_tags( $post->post_title . ' ' . $post->post_excerpt );
		}
		$score_text = trim(
			$query_text . ' ' .
			PIV_Search::build_latin_query( $post ) . ' ' .
			PIV_Search::build_alias_query( $post )
		);
		$post_blob = wp_strip_all_tags( $post->post_title . ' ' . $post->post_excerpt );

		$scored = array();
		foreach ( $urls as $url ) {
			if ( ! PIV_Sources::is_article_url( $url ) || ! PIV_Search::is_allowed_result_url( $url ) ) {
				continue;
			}

			$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
			$path_text = str_replace( array( '-', '_', '/' ), ' ', $path );

			if ( PIV_Verifier::is_review_like_text( $path_text . ' ' . $url ) && ! PIV_Verifier::is_review_like_text( $post_blob ) ) {
				continue;
			}
			if ( PIV_Verifier::is_outdated_source_for_post( $post, $path_text, $url ) ) {
				continue;
			}

			$score = PIV_Search::score_url_for_post( $url, $score_text, $path_text );
			if ( $score < 0.05 ) {
				$score = 0.08;
			}
			if ( $score < $min_score ) {
				continue;
			}

			$scored[ $url ] = $score;
		}

		if ( empty( $scored ) ) {
			foreach ( $urls as $url ) {
				if ( PIV_Sources::is_article_url( $url ) && PIV_Search::is_allowed_result_url( $url ) ) {
					$scored[ $url ] = 0.07;
				}
			}
		}

		if ( empty( $scored ) ) {
			return array();
		}

		arsort( $scored, SORT_NUMERIC );
		return array_slice( array_keys( $scored ), 0, $max );
	}

	/**
	 * Anchor tokens for Hebrew↔English matching (title + Latin + aliases).
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function get_anchor_tokens( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return array();
		}

		$raw = array_merge(
			PIV_Verifier::get_distinctive_title_tokens( $post ),
			PIV_Verifier::extract_key_tokens( PIV_Search::build_latin_query( $post ) ),
			PIV_Verifier::extract_key_tokens( PIV_Search::build_alias_query( $post ) )
		);

		$tokens = PIV_Verifier::expand_language_aliases( $raw );
		$noise  = PIV_Verifier::broad_topic_noise_tokens();

		return array_values(
			array_filter(
				array_unique( $tokens ),
				static function ( $token ) use ( $noise ) {
					$token = strtolower( (string) $token );
					if ( strlen( $token ) < 3 && ! preg_match( '/^\d{1,4}$/', $token ) ) {
						return false;
					}
					return ! in_array( $token, $noise, true );
				}
			)
		);
	}

	/**
	 * Decide if a fetched source matches the post enough to count.
	 *
	 * @param WP_Post|null $post       Post.
	 * @param string       $src_title  Source title.
	 * @param string       $url        Source URL.
	 * @param float        $text_score Combined text score from fetch.
	 * @param float        $key_score  Key-token score.
	 * @param string       $src_body   Source body text.
	 * @return array{ok:bool,score:float,reason:string}
	 */
	public static function evaluate( $post, $src_title, $url, $text_score = 0.0, $key_score = 0.0, $src_body = '' ) {
		$fail = static function ( $reason ) {
			return array(
				'ok'     => false,
				'score'  => 0.0,
				'reason' => $reason,
			);
		};

		if ( ! ( $post instanceof WP_Post ) ) {
			return $fail( 'no_post' );
		}

		$post_title = wp_strip_all_tags( (string) $post->post_title );
		$post_blob  = PIV_Verifier::get_post_comparison_text( $post, 2000 );
		$src_blob   = trim( (string) $src_title . ' ' . (string) $src_body );

		if ( '' === $src_blob ) {
			return $fail( 'empty_source' );
		}

		if ( PIV_Verifier::is_review_like_text( $src_blob . ' ' . $url ) && ! PIV_Verifier::is_review_like_text( $post_blob ) ) {
			return $fail( 'review_mismatch' );
		}
		if ( PIV_Verifier::is_outdated_source_for_post( $post, $src_title . ' ' . $src_body, $url ) ) {
			return $fail( 'outdated' );
		}

		$title_score = PIV_Verifier::text_similarity(
			PIV_Verifier::normalize_text( $post_title ),
			PIV_Verifier::normalize_text( (string) $src_title )
		);
		$body_score  = '' !== trim( (string) $src_body )
			? PIV_Verifier::text_similarity( $post_blob, $src_blob )
			: 0.0;

		$src_tokens   = PIV_Verifier::expand_language_aliases( PIV_Verifier::extract_key_tokens( $src_blob, $url ) );
		$post_tokens  = PIV_Verifier::expand_language_aliases( PIV_Verifier::extract_key_tokens( $post_blob ) );
		$overlap      = array_values( array_intersect( $post_tokens, $src_tokens ) );
		$hit_count    = count( $overlap );
		$anchor_tokens = self::get_anchor_tokens( $post );
		$anchor_hits   = array_values( array_intersect( $anchor_tokens, $src_tokens ) );

		$score = max( (float) $text_score, (float) $key_score, $title_score, $body_score );

		if ( ! empty( $overlap ) && PIV_Verifier::is_platform_only_overlap( $overlap ) && count( $anchor_hits ) < 1 && $title_score < 0.38 ) {
			return $fail( 'platform_only' );
		}
		if ( ! empty( $anchor_hits ) && PIV_Verifier::is_platform_only_overlap( $anchor_hits ) && $title_score < 0.38 ) {
			return $fail( 'platform_only' );
		}

		// Need a real topical anchor — Hebrew title alone must bridge via Latin/aliases.
		if ( ! empty( $anchor_tokens ) && empty( $anchor_hits ) && $title_score < 0.36 && $body_score < 0.24 && (float) $key_score < 0.28 ) {
			return $fail( 'title_mismatch' );
		}

		$ok     = false;
		$reason = 'no_overlap';

		if ( $title_score >= 0.32 ) {
			$ok     = true;
			$reason = 'title_match';
			$score  = max( $score, $title_score );
		} elseif ( count( $anchor_hits ) >= 2 && $score >= 0.22 ) {
			$ok     = true;
			$reason = 'anchor_tokens';
		} elseif ( count( $anchor_hits ) >= 1 && ( $title_score >= 0.22 || $body_score >= 0.18 || (float) $key_score >= 0.24 ) ) {
			$ok     = true;
			$reason = 'anchor_and_signal';
			$score  = max( $score, 0.24 );
		} elseif ( $body_score >= 0.26 && $hit_count >= 3 && count( $anchor_hits ) >= 1 ) {
			$ok     = true;
			$reason = 'body_match';
			$score  = max( $score, $body_score );
		} elseif ( (float) $key_score >= 0.3 && count( $anchor_hits ) >= 1 ) {
			$ok     = true;
			$reason = 'key_match';
			$score  = max( $score, (float) $key_score );
		}

		if ( ! $ok ) {
			return $fail( $reason );
		}

		if ( count( $anchor_hits ) >= 2 ) {
			$score = min( 1.0, $score + 0.04 );
		}

		return array(
			'ok'     => true,
			'score'  => round( min( 1.0, $score ), 3 ),
			'reason' => $reason,
		);
	}

	/**
	 * Whether an analyzed entry should be accepted as a matching source.
	 *
	 * @param array  $entry   Source entry.
	 * @param string $origin  Origin label.
	 * @return bool
	 */
	public static function should_accept( $entry, $origin = '' ) {
		if ( 'manual' !== $origin && empty( $entry['http_ok'] ) && empty( $entry['fetched'] ) ) {
			return false;
		}
		if ( empty( $entry['context_ok'] ) ) {
			return false;
		}
		if ( 'manual' !== $origin && empty( $entry['title'] ) ) {
			return false;
		}

		$score = (float) ( $entry['effective_match'] ?? $entry['match_score'] ?? 0 );

		// Gemini same-story is already reflected in context_ok + score.
		if ( 'manual' === $origin ) {
			return true;
		}
		if ( 'official' === $origin || ! empty( $entry['is_official'] ) ) {
			return $score >= 0.22;
		}
		if ( in_array( $origin, array( 'news', 'rss' ), true ) || ! empty( $entry['is_news'] ) ) {
			return $score >= 0.24;
		}
		if ( 'cited' === $origin ) {
			return $score >= 0.24;
		}

		return $score >= 0.3;
	}
}
