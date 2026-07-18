<?php
/**
 * Admin report: verification status and match % per post.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PIV_Admin_Report {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 11 );
	}

	public static function add_menu() {
		add_submenu_page(
			'options-general.php',
			__( 'דוח אימות מידע', 'content-verification-badge' ),
			__( 'דוח אימות', 'content-verification-badge' ),
			'manage_options',
			'content-verification-report',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @return array
	 */
	public static function get_layer_filter_options() {
		$options = array(
			'' => __( 'כל השכבות', 'content-verification-badge' ),
		);

		foreach ( PIV_Layers::all() as $slug => $layer ) {
			$options[ $slug ] = $layer['icon'] . ' ' . $layer['label'];
		}

		$options['excluded'] = __( 'מוחרג (ביקורת/דעה)', 'content-verification-badge' );
		$options['none']     = __( 'טרם נבדק', 'content-verification-badge' );

		return $options;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_post_row( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		if ( PIV_Helpers::is_excluded_post( $post_id ) ) {
			return array(
				'post_id'     => $post_id,
				'title'       => get_the_title( $post_id ),
				'edit_link'   => get_edit_post_link( $post_id ),
				'layer'       => '',
				'layer_label' => __( 'מוחרג (ביקורת/דעה)', 'content-verification-badge' ),
				'layer_icon'  => '—',
				'percent'     => null,
				'sources'      => 0,
				'source_items' => array(),
				'checked_at'   => 0,
				'reason'      => '',
				'excluded'    => true,
			);
		}

		$result    = PIV_Helpers::get_result( $post_id );
		$layer     = PIV_Helpers::get_layer( $post_id );
		$layer_def = $layer ? PIV_Layers::get( $layer ) : null;
		$percent   = null;

		if ( isset( $result['sources']['max_match'] ) ) {
			$percent = (int) round( (float) $result['sources']['max_match'] * 100 );
		}

		$sources = 0;
		$source_items = PIV_Helpers::get_checked_sources( $post_id );
		if ( ! empty( $source_items ) ) {
			$sources = count( $source_items );
		} elseif ( ! empty( $result['sources']['counts']['external'] ) ) {
			$sources = (int) $result['sources']['counts']['external'];
		}

		$reason = '';
		if ( ! empty( $result['reasons'][0] ) ) {
			$reason = (string) $result['reasons'][0];
		}

		return array(
			'post_id'     => $post_id,
			'title'       => get_the_title( $post_id ),
			'edit_link'   => get_edit_post_link( $post_id ),
			'layer'       => $layer,
			'layer_label' => $layer_def ? $layer_def['label'] : __( 'טרם נבדק', 'content-verification-badge' ),
			'layer_icon'  => $layer_def ? $layer_def['icon'] : '—',
			'layer_color' => $layer_def ? $layer_def['color'] : '#9ca3af',
			'percent'      => $percent,
			'sources'      => $sources,
			'source_items' => $source_items,
			'checked_at'   => (int) get_post_meta( $post_id, PIV_META_CHECKED_AT, true ),
			'reason'      => $reason,
			'excluded'    => false,
		);
	}

	/**
	 * Row data formatted for live AJAX updates.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function format_row_for_client( $post_id ) {
		$row = self::get_post_row( $post_id );
		if ( empty( $row ) ) {
			return array();
		}

		$source_items = array();
		foreach ( (array) ( $row['source_items'] ?? array() ) as $source ) {
			$source_items[] = array(
				'url'   => isset( $source['url'] ) ? (string) $source['url'] : '',
				'label' => PIV_Helpers::format_source_admin_label( $source ),
			);
		}

		return array(
			'post_id'          => (int) $row['post_id'],
			'layer'            => (string) ( $row['layer'] ?? '' ),
			'layer_label'      => (string) ( $row['layer_label'] ?? '' ),
			'layer_icon'       => (string) ( $row['layer_icon'] ?? '—' ),
			'layer_color'      => (string) ( $row['layer_color'] ?? '#9ca3af' ),
			'percent'          => isset( $row['percent'] ) ? $row['percent'] : null,
			'sources'          => (int) ( $row['sources'] ?? 0 ),
			'source_items'     => $source_items,
			'checked_at'       => (int) ( $row['checked_at'] ?? 0 ),
			'checked_at_label' => ! empty( $row['checked_at'] ) ? wp_date( 'j.n.Y H:i', (int) $row['checked_at'] ) : '—',
			'reason'           => (string) ( $row['reason'] ?? '' ),
			'excluded'         => ! empty( $row['excluded'] ),
		);
	}

	/**
	 * @param string $layer_filter Layer filter key.
	 * @param string $search       Search string.
	 * @param int    $paged        Page number.
	 * @return array{posts: int[], total: int}
	 */
	public static function query_posts( $layer_filter, $search, $paged ) {
		$per_page = 20;

		if ( 'excluded' === $layer_filter ) {
			$all_ids = get_posts(
				array(
					'post_type'      => PIV_Helpers::post_types(),
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					's'              => $search,
				)
			);

			$filtered = array();
			foreach ( $all_ids as $post_id ) {
				if ( PIV_Helpers::is_excluded_post( $post_id ) ) {
					$filtered[] = $post_id;
				}
			}

			$total  = count( $filtered );
			$offset = max( 0, ( $paged - 1 ) * $per_page );
			$posts  = array_slice( $filtered, $offset, $per_page );

			return array(
				'posts' => $posts,
				'total' => $total,
			);
		}

		$args = array(
			'post_type'      => PIV_Helpers::post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $paged ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			's'              => $search,
		);

		if ( 'none' === $layer_filter ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => PIV_META_LAYER,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => PIV_META_LAYER,
					'value'   => '',
					'compare' => '=',
				),
			);
		} elseif ( '' !== $layer_filter ) {
			$args['meta_query'] = array(
				array(
					'key'   => PIV_META_LAYER,
					'value' => $layer_filter,
				),
			);
		}

		$query = new WP_Query( $args );
		$posts = array();

		foreach ( $query->posts as $post_id ) {
			if ( PIV_Helpers::is_excluded_post( $post_id ) ) {
				continue;
			}
			$posts[] = $post_id;
		}

		return array(
			'posts' => $posts,
			'total' => (int) $query->found_posts,
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$layer_filter = isset( $_GET['layer'] ) ? sanitize_key( wp_unslash( $_GET['layer'] ) ) : '';
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$data       = self::query_posts( $layer_filter, $search, $paged );
		$total      = $data['total'];
		$per_page   = 20;
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$coverage   = PIV_Helpers::get_coverage_payload();
		?>
		<div class="wrap piv-admin-wrap piv-report-wrap" data-piv-live-report>
			<h1><?php esc_html_e( 'דוח אימות מידע', 'content-verification-badge' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=content-verification-badge' ) ); ?>">
					<?php esc_html_e( '← חזרה להגדרות', 'content-verification-badge' ); ?>
				</a>
			</p>

			<div class="piv-report-coverage" data-piv-live-coverage>
				<?php PIV_Admin::render_coverage_progress( $coverage ); ?>
			</div>

			<form method="get" class="piv-report-filters">
				<input type="hidden" name="page" value="content-verification-report">
				<p class="search-box">
					<label class="screen-reader-text" for="piv-report-search"><?php esc_html_e( 'חיפוש פוסטים', 'content-verification-badge' ); ?></label>
					<input type="search" id="piv-report-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'חיפוש לפי כותרת...', 'content-verification-badge' ); ?>">
					<select name="layer">
						<?php foreach ( self::get_layer_filter_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $layer_filter, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'סנן', 'content-verification-badge' ), 'secondary', '', false ); ?>
				</p>
			</form>

			<p class="piv-report-summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: total posts */
						_n( 'נמצא פוסט אחד', 'נמצאו %d פוסטים', $total, 'content-verification-badge' ),
						$total
					)
				);
				?>
			</p>

			<table class="widefat fixed striped piv-report-table">
				<thead>
					<tr>
						<th class="piv-col-title"><?php esc_html_e( 'פוסט', 'content-verification-badge' ); ?></th>
						<th class="piv-col-status"><?php esc_html_e( 'מצב אימות', 'content-verification-badge' ); ?></th>
						<th class="piv-col-percent"><?php esc_html_e( 'אחוז התאמה', 'content-verification-badge' ); ?></th>
						<th class="piv-col-sources"><?php esc_html_e( 'מקורות', 'content-verification-badge' ); ?></th>
						<th class="piv-col-checked"><?php esc_html_e( 'נבדק', 'content-verification-badge' ); ?></th>
						<th class="piv-col-reason"><?php esc_html_e( 'הערה', 'content-verification-badge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $data['posts'] ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'לא נמצאו פוסטים.', 'content-verification-badge' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $data['posts'] as $post_id ) : ?>
							<?php $row = self::get_post_row( $post_id ); ?>
							<?php if ( empty( $row ) ) { continue; } ?>
							<tr class="<?php echo $row['excluded'] ? 'piv-report-row--excluded' : ''; ?> piv-report-row" data-post-id="<?php echo esc_attr( (string) $row['post_id'] ); ?>" data-layer="<?php echo esc_attr( (string) $row['layer'] ); ?>">
								<td class="piv-col-title">
									<strong>
										<a href="<?php echo esc_url( $row['edit_link'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
									</strong>
								</td>
								<td class="piv-col-status" data-piv-cell="status">
									<?php if ( ! empty( $row['layer'] ) ) : ?>
										<span class="piv-report-badge piv-report-badge--<?php echo esc_attr( $row['layer'] ); ?>" style="--piv-color:<?php echo esc_attr( $row['layer_color'] ); ?>">
											<span aria-hidden="true"><?php echo esc_html( $row['layer_icon'] ); ?></span>
											<?php echo esc_html( $row['layer_label'] ); ?>
										</span>
									<?php else : ?>
										<span class="piv-report-badge piv-report-badge--none"><?php echo esc_html( $row['layer_label'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="piv-col-percent" data-piv-cell="percent">
									<?php if ( null === $row['percent'] ) : ?>
										—
									<?php else : ?>
										<div class="piv-report-percent">
											<div class="piv-report-percent__bar" style="width:<?php echo esc_attr( min( 100, $row['percent'] ) ); ?>%;"></div>
											<span class="piv-report-percent__value"><?php echo esc_html( $row['percent'] ); ?>%</span>
										</div>
									<?php endif; ?>
								</td>
								<td class="piv-col-sources" data-piv-cell="sources">
									<?php if ( empty( $row['source_items'] ) ) : ?>
										<?php echo esc_html( (string) $row['sources'] ); ?>
									<?php else : ?>
										<span class="piv-report-sources-count">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: number of sources */
													_n( 'מקור אחד', '%d מקורות', count( $row['source_items'] ), 'content-verification-badge' ),
													count( $row['source_items'] )
												)
											);
											?>
										</span>
										<ul class="piv-report-sources-list">
											<?php foreach ( $row['source_items'] as $source ) : ?>
												<li>
													<a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener noreferrer">
														<?php echo esc_html( PIV_Helpers::format_source_admin_label( $source ) ); ?>
													</a>
												</li>
											<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</td>
								<td class="piv-col-checked" data-piv-cell="checked">
									<?php
									echo $row['checked_at']
										? esc_html( wp_date( 'j.n.Y H:i', $row['checked_at'] ) )
										: '—';
									?>
								</td>
								<td class="piv-col-reason" data-piv-cell="reason"><?php echo esc_html( $row['reason'] ?: '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
