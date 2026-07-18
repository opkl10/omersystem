<?php
/**
 * Post editor meta box showing verification status.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Meta_Box {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save_manual_fields' ), 20, 2 );
	}

	public static function add() {
		foreach ( PIV_Helpers::post_types() as $type ) {
			add_meta_box(
				'piv_verification_box',
				esc_html__( 'סטטוס אימות מידע', 'content-verification-badge' ),
				array( __CLASS__, 'render' ),
				$type,
				'side',
				'high'
			);
		}
	}

	/**
	 * @param WP_Post $post Post object.
	 */
	public static function render( $post ) {
		if ( PIV_Helpers::is_excluded_post( $post->ID ) ) {
			?>
			<p class="description"><?php esc_html_e( 'פוסט ביקורת/דעה (קטגוריה, תגית, תיבת ביקורת, ציון, או כותרת/סלאג של ביקורת) — אימות מידע אינו חל על פוסט זה.', 'content-verification-badge' ); ?></p>
			<?php
			return;
		}

		$settings = PIV_Helpers::get_settings();
		PIV_Helpers::repair_inconsistent_verification( $post->ID );
		$layer_slug = PIV_Helpers::get_layer( $post->ID );
		$layer      = $layer_slug ? PIV_Layers::get( $layer_slug ) : null;
		$result     = PIV_Helpers::get_result( $post->ID );
		$status     = (string) get_post_meta( $post->ID, PIV_META_STATUS, true );
		$checked    = (int) get_post_meta( $post->ID, PIV_META_CHECKED_AT, true );
		$manual     = PIV_Verifier::get_manual_override( $post->ID );
		$allow_manual = 'yes' === ( $settings['allow_manual_override'] ?? 'yes' );

		wp_nonce_field( 'piv_manual_override', 'piv_manual_nonce' );
		?>
		<div class="piv-metabox" id="piv-metabox" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<?php if ( $layer ) : ?>
				<p class="piv-metabox__status piv-metabox__status--<?php echo esc_attr( $layer_slug ); ?>">
					<span class="piv-metabox__icon"><?php echo esc_html( $layer['icon'] ); ?></span>
					<strong><?php echo esc_html( $layer['label'] ); ?></strong>
					<?php if ( ! empty( $manual['layer'] ) ) : ?>
						<br><span class="description"><?php esc_html_e( '(כולל אימות ידני)', 'content-verification-badge' ); ?></span>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p class="piv-metabox__status piv-metabox__status--pending">
					<?php esc_html_e( 'טרם נבדק', 'content-verification-badge' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $result['reasons'][0] ) ) : ?>
				<p class="piv-metabox__reason"><?php echo esc_html( $result['reasons'][0] ); ?></p>
			<?php endif; ?>

			<?php if ( $checked ) : ?>
				<p class="piv-metabox__time">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: formatted datetime */
							__( 'נבדק: %s', 'content-verification-badge' ),
							wp_date( 'j.n.Y H:i', $checked )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( 'checking' === $status ) : ?>
				<p class="piv-metabox__checking"><?php esc_html_e( 'הבדיקה רצה...', 'content-verification-badge' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $result['search']['query'] ) ) : ?>
				<p class="piv-metabox__search">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: search query, 2: provider */
							__( 'חיפוש: %1$s (%2$s)', 'content-verification-badge' ),
							$result['search']['query'],
							(string) ( $result['search']['provider'] ?? $result['discovery_diag']['provider'] ?? '—' )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $result['discovery_diag'] ) ) : ?>
				<ul class="piv-metabox__counts">
					<li><?php echo esc_html( sprintf( __( 'מועמדים שנמצאו: %d', 'content-verification-badge' ), (int) ( $result['discovery_diag']['candidates'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'חיפוש גולמי: %d · רשמי: %d · חדשות: %d · RSS: %d', 'content-verification-badge' ), (int) ( $result['discovery_diag']['search_raw'] ?? 0 ), (int) ( $result['discovery_diag']['official'] ?? 0 ), (int) ( $result['discovery_diag']['news'] ?? 0 ), (int) ( $result['discovery_diag']['rss'] ?? 0 ) ) ); ?></li>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $result['sources']['counts'] ) ) : ?>
				<ul class="piv-metabox__counts">
					<li><?php echo esc_html( sprintf( __( 'מקורות מתאימים: %d', 'content-verification-badge' ), (int) ( $result['sources']['counts']['matched'] ?? $result['sources']['counts']['external'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'נסרקו: %d · נדחו: %d', 'content-verification-badge' ), (int) ( $result['sources']['counts']['scanned'] ?? 0 ), (int) ( $result['sources']['counts']['rejected'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'מאתרים רשמיים: %d', 'content-verification-badge' ), (int) ( $result['sources']['counts']['official'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'מאתרי חדשות: %d', 'content-verification-badge' ), (int) ( $result['sources']['counts']['news'] ?? 0 ) ) ); ?></li>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $result['sources']['rejected_samples'] ) ) : ?>
				<details class="piv-metabox__rejected">
					<summary><?php esc_html_e( 'דוגמאות שנדחו (לא נספרות)', 'content-verification-badge' ); ?></summary>
					<ul class="piv-metabox__sources-list">
						<?php foreach ( (array) $result['sources']['rejected_samples'] as $sample ) : ?>
							<li>
								<?php if ( ! empty( $sample['url'] ) ) : ?>
									<a href="<?php echo esc_url( $sample['url'] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $sample['domain'] ?? $sample['url'] ); ?>
									</a>
								<?php endif; ?>
								<span class="description">
									<?php echo esc_html( ' — ' . (string) ( $sample['context_reason'] ?? '' ) . ' (' . round( (float) ( $sample['match_score'] ?? 0 ), 2 ) . ')' ); ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>

			<?php
			$checked_sources  = PIV_Helpers::get_checked_sources( $post->ID );
			$accepted_sources = array();
			foreach ( $checked_sources as $source ) {
				if ( ! empty( $source['accepted'] ) || ! empty( $source['url'] ) ) {
					$accepted_sources[] = $source;
				}
			}
			?>

			<?php if ( ! empty( $result['evidence'] ) && is_array( $result['evidence'] ) ) : ?>
				<div class="piv-metabox__sources piv-metabox__sources--evidence">
					<strong><?php esc_html_e( 'מקורות לאימות', 'content-verification-badge' ); ?></strong>
					<ul class="piv-metabox__sources-list">
						<?php foreach ( $result['evidence'] as $source ) : ?>
							<li>
								<a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( PIV_Helpers::format_source_admin_label( array_merge( $source, array( 'accepted' => true ) ) ) ); ?>
								</a>
								<?php if ( ! empty( $source['title'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( $source['title'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $source['body_excerpt'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( '…' . $source['body_excerpt'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $source['ai_reason'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( 'Gemini: ' . $source['ai_reason'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php elseif ( ! empty( $accepted_sources ) ) : ?>
				<div class="piv-metabox__sources piv-metabox__sources--evidence">
					<strong><?php esc_html_e( 'מקורות לאימות', 'content-verification-badge' ); ?></strong>
					<ul class="piv-metabox__sources-list">
						<?php foreach ( $accepted_sources as $source ) : ?>
							<li>
								<a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( PIV_Helpers::format_source_admin_label( $source ) ); ?>
								</a>
								<?php if ( ! empty( $source['title'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( $source['title'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $source['body_excerpt'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( '…' . $source['body_excerpt'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $source['ai_reason'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( 'Gemini: ' . $source['ai_reason'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( empty( $accepted_sources ) && empty( $result['evidence'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'לא נמצא מקור מתאים. מועמדים לא רלוונטיים לא נספרים ולא מוצגים.', 'content-verification-badge' ); ?></p>
			<?php endif; ?>

			<p class="piv-metabox__actions">
				<button type="button" class="button button-secondary" id="piv-recheck-btn">
					<?php esc_html_e( 'בדוק שוב עכשיו', 'content-verification-badge' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="piv-reset-recheck-btn">
					<?php esc_html_e( 'אפס ובדוק מחדש', 'content-verification-badge' ); ?>
				</button>
				<button type="button" class="button button-primary" id="piv-precision-scan-btn">
					<?php esc_html_e( 'סריקה מדויקת מלאה', 'content-verification-badge' ); ?>
				</button>
			</p>

			<p class="description">
				<?php esc_html_e( 'לדיוק מקסימלי: "סריקה מדויקת מלאה" — מאפס, סורק את כל האתרים הרשמיים+חדשות+RSS, קורא גוף כתבות ומשווה לתוכן הפוסט (1–5 דקות).', 'content-verification-badge' ); ?>
			</p>

			<?php if ( $allow_manual ) : ?>
				<div class="piv-metabox__manual">
					<strong><?php esc_html_e( 'אימות ידני', 'content-verification-badge' ); ?></strong>
					<p>
						<label for="piv_manual_layer"><?php esc_html_e( 'שכבה', 'content-verification-badge' ); ?></label><br>
						<select name="piv_manual_layer" id="piv_manual_layer" class="widefat">
							<option value=""><?php esc_html_e( 'אוטומטי (לפי המערכת)', 'content-verification-badge' ); ?></option>
							<?php foreach ( PIV_Layers::all() as $slug => $def ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $manual['layer'], $slug ); ?>>
									<?php echo esc_html( $def['icon'] . ' ' . $def['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="piv_manual_evidence"><?php esc_html_e( 'קישור מקור / ראיה', 'content-verification-badge' ); ?></label><br>
						<input type="url" class="widefat" name="piv_manual_evidence" id="piv_manual_evidence" value="<?php echo esc_attr( $manual['evidence'] ); ?>" placeholder="https://...">
					</p>
					<p>
						<label for="piv_manual_note"><?php esc_html_e( 'הערה קצרה (אופציונלי)', 'content-verification-badge' ); ?></label><br>
						<input type="text" class="widefat" name="piv_manual_note" id="piv_manual_note" value="<?php echo esc_attr( $manual['note'] ); ?>">
					</p>
					<p class="description"><?php esc_html_e( 'שמור את הפוסט כדי להחיל. ל"מידע מאומת" חובה להזין קישור מקור/ראיה — בלי קישור השכבה לא תישמר כמאומת.', 'content-verification-badge' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Persist manual override fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_manual_fields( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, PIV_Helpers::post_types(), true ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( empty( $_POST['piv_manual_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['piv_manual_nonce'] ) ), 'piv_manual_override' ) ) {
			return;
		}

		$settings = PIV_Helpers::get_settings();
		if ( 'yes' !== ( $settings['allow_manual_override'] ?? 'yes' ) ) {
			return;
		}

		$layer = isset( $_POST['piv_manual_layer'] ) ? sanitize_key( wp_unslash( $_POST['piv_manual_layer'] ) ) : '';
		$allowed = array(
			'',
			PIV_Layers::INITIAL_REPORT,
			PIV_Layers::UNDER_REVIEW,
			PIV_Layers::TRUSTED_SOURCE,
			PIV_Layers::VERIFIED,
		);
		if ( ! in_array( $layer, $allowed, true ) ) {
			$layer = '';
		}

		$evidence = isset( $_POST['piv_manual_evidence'] ) ? esc_url_raw( wp_unslash( $_POST['piv_manual_evidence'] ) ) : '';
		$note     = isset( $_POST['piv_manual_note'] ) ? sanitize_text_field( wp_unslash( $_POST['piv_manual_note'] ) ) : '';

		if ( '' === $layer ) {
			delete_post_meta( $post_id, PIV_META_MANUAL_LAYER );
		} else {
			update_post_meta( $post_id, PIV_META_MANUAL_LAYER, $layer );
		}

		if ( '' === $evidence ) {
			delete_post_meta( $post_id, PIV_META_MANUAL_EVIDENCE );
		} else {
			update_post_meta( $post_id, PIV_META_MANUAL_EVIDENCE, $evidence );
		}

		if ( '' === $note ) {
			delete_post_meta( $post_id, PIV_META_MANUAL_NOTE );
		} else {
			update_post_meta( $post_id, PIV_META_MANUAL_NOTE, $note );
		}

		// Verified is never applied without a full recheck of the evidence URL.
		if ( PIV_Layers::VERIFIED === $layer ) {
			if ( '' === $evidence ) {
				delete_post_meta( $post_id, PIV_META_MANUAL_LAYER );
				return;
			}
			PIV_Verifier::verify_post( $post_id, true, array( 'context' => 'manual' ) );
			return;
		}

		// Evidence URL always triggers a real scan.
		if ( '' !== $evidence ) {
			PIV_Verifier::verify_post( $post_id, true, array( 'context' => 'manual' ) );
			return;
		}

		// Non-verified manual layers (without evidence) may update the badge label only.
		if ( '' !== $layer && PIV_Layers::VERIFIED !== $layer ) {
			self::apply_manual_layer_only( $post_id, $layer, $note );
		}
	}

	/**
	 * Set badge layer without a full external scan.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $layer   Layer slug.
	 * @param string $note    Optional note.
	 */
	private static function apply_manual_layer_only( $post_id, $layer, $note = '' ) {
		$result = PIV_Helpers::get_result( $post_id );
		if ( ! is_array( $result ) ) {
			$result = array(
				'sources' => array( 'external' => array(), 'counts' => array() ),
				'reasons' => array(),
			);
		}

		$reasons = array( __( 'שכבה נקבעה ידנית על ידי העורך', 'content-verification-badge' ) );
		if ( '' !== $note ) {
			$reasons[] = $note;
		}
		if ( ! empty( $result['reasons'] ) && is_array( $result['reasons'] ) ) {
			$reasons = array_merge( $reasons, $result['reasons'] );
		}

		$result['layer']      = $layer;
		$result['manual']     = PIV_Verifier::get_manual_override( $post_id );
		$result['reasons']    = $reasons;
		$result['checked_at'] = time();

		update_post_meta( $post_id, PIV_META_LAYER, $layer );
		update_post_meta( $post_id, PIV_META_CHECKED_AT, $result['checked_at'] );
		update_post_meta( $post_id, PIV_META_RESULT, wp_json_encode( $result ) );
		update_post_meta( $post_id, PIV_META_STATUS, 'complete' );
		PIV_Helpers::invalidate_verification_stats_cache();
	}
}
