<?php
/**
 * Mood based listing search.
 *
 * Provides the `[af5_mood_search]` shortcode which lets a visitor filter
 * GeoDirectory listings by a multiselect custom field (e.g. "mood") and
 * renders matching listings using this plugin's own listing card markup.
 *
 * @package AF5_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class AF5_Core_Mood_Search {

	/**
	 * Singleton instance.
	 *
	 * @var AF5_Core_Mood_Search|null
	 */
	private static $instance = null;

	/**
	 * Nonce action/name used for the AJAX search request.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'af5_mood_search_nonce';

	/**
	 * Minimum/maximum bounds allowed for results per page.
	 */
	const MIN_PER_PAGE = 1;
	const MAX_PER_PAGE = 50;

	/**
	 * Word count for each card's excerpt, kept independent of the active
	 * theme's `excerpt_length` filter so cards stay a consistent size.
	 */
	const EXCERPT_WORDS = 20;

	/**
	 * Get the singleton instance.
	 *
	 * @return AF5_Core_Mood_Search
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire up shortcode, AJAX and query hooks.
	 */
	private function __construct() {
		add_shortcode( 'af5_mood_search', array( $this, 'render_shortcode' ) );

		add_action( 'wp_ajax_af5_mood_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_af5_mood_search', array( $this, 'ajax_search' ) );

		add_filter( 'posts_join', array( $this, 'filter_posts_join' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'filter_posts_where' ), 10, 2 );
	}

	/**
	 * Register (but do not print) the front-end assets.
	 */
	private function register_assets() {
		if ( wp_script_is( 'af5-mood-search', 'registered' ) ) {
			return;
		}

		wp_register_style(
			'af5-mood-search',
			AF5_CORE_URL . 'assets/css/mood-search.css',
			array(),
			AF5_CORE_VERSION
		);

		wp_register_script(
			'af5-mood-search',
			AF5_CORE_URL . 'assets/js/mood-search.js',
			array(),
			AF5_CORE_VERSION,
			true
		);
	}

	/**
	 * Shortcode callback: [af5_mood_search post_type="gd_place" field="mood" per_page="10"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		if ( ! function_exists( 'geodir_get_field_infoby' ) || ! function_exists( 'geodir_get_posttypes' ) || ! function_exists( 'geodir_get_post_meta' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'post_type' => 'gd_place',
				'field'     => 'mood',
				'per_page'  => 10,
			),
			$atts,
			'af5_mood_search'
		);

		$post_type = sanitize_key( $atts['post_type'] );
		$field_key = sanitize_key( $atts['field'] );
		$per_page  = $this->clamp_per_page( $atts['per_page'] );

		$post_types = geodir_get_posttypes();
		if ( ! in_array( $post_type, $post_types, true ) ) {
			return '';
		}

		$field = geodir_get_field_infoby( 'htmlvar_name', $field_key, $post_type );
		if ( empty( $field ) || empty( $field['option_values'] ) ) {
			return '';
		}

		$options = $this->get_field_options( $field );
		if ( empty( $options ) ) {
			return '';
		}

		$this->register_assets();
		wp_enqueue_style( 'af5-mood-search' );
		wp_enqueue_script( 'af5-mood-search' );
		wp_localize_script(
			'af5-mood-search',
			'af5MoodSearch',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'af5_mood_search',
				'i18n'    => array(
					'loadError' => __( 'Something went wrong while searching. Please try again.', 'af5-core' ),
				),
			)
		);

		static $instance_count = 0;
		$instance_count++;
		$container_id = 'af5-mood-search-' . $instance_count;

		$selected = $this->get_requested_moods( $options );

		ob_start();
		?>
		<div class="af5-mood-search" id="<?php echo esc_attr( $container_id ); ?>">
			<form class="af5-mood-search__form" method="get" data-af5-mood-search>
				<?php wp_nonce_field( self::NONCE_ACTION, 'af5_mood_search_nonce' ); ?>
				<input type="hidden" name="af5_post_type" value="<?php echo esc_attr( $post_type ); ?>" />
				<input type="hidden" name="af5_field" value="<?php echo esc_attr( $field_key ); ?>" />
				<input type="hidden" name="af5_per_page" value="<?php echo esc_attr( $per_page ); ?>" />

				<fieldset>
					<legend><?php echo esc_html( $field['frontend_title'] ? $field['frontend_title'] : __( 'Search by mood', 'af5-core' ) ); ?></legend>

					<?php foreach ( $options as $option ) : ?>
						<label class="af5-mood-search__option">
							<input
								type="checkbox"
								name="af5_mood[]"
								value="<?php echo esc_attr( $option ); ?>"
								<?php checked( in_array( $option, $selected, true ) ); ?>
							/>
							<?php echo esc_html( $option ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<button type="submit"><?php esc_html_e( 'Search', 'af5-core' ); ?></button>
			</form>

			<div class="af5-mood-search__results" data-af5-mood-search-results>
				<?php
				// Show all listings by default; narrows down once moods are selected.
				$query = $this->build_query( $post_type, $field_key, $selected, 1, $per_page );
				echo $this->render_results( $query, $field_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in render_results()/render_card().
				?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Read and whitelist the currently requested mood values from $_GET,
	 * used only to pre-render results on first page load (no JS / deep link).
	 *
	 * @param array $options Allowed option values for the field.
	 * @return array
	 */
	private function get_requested_moods( $options ) {
		if ( empty( $_GET['af5_mood'] ) || ! is_array( $_GET['af5_mood'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, non-destructive filter of publicly visible listings.
			return array();
		}

		$requested = array_map( 'sanitize_text_field', wp_unslash( $_GET['af5_mood'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_values( array_intersect( $requested, $options ) );
	}

	/**
	 * Parse a custom field's option_values into a clean array.
	 *
	 * @param array $field Custom field row as returned by geodir_get_field_infoby().
	 * @return array
	 */
	private function get_field_options( $field ) {
		$lines = preg_split( '/[\r\n]+/', (string) $field['option_values'] );
		$lines = array_map( 'trim', $lines );

		return array_values( array_filter( $lines, 'strlen' ) );
	}

	/**
	 * Clamp a requested per-page value to a sane, safe range.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function clamp_per_page( $value ) {
		$value = absint( $value );

		if ( $value < self::MIN_PER_PAGE ) {
			$value = self::MIN_PER_PAGE;
		} elseif ( $value > self::MAX_PER_PAGE ) {
			$value = self::MAX_PER_PAGE;
		}

		return $value;
	}

	/**
	 * AJAX handler for the mood search.
	 */
	public function ajax_search() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! function_exists( 'geodir_get_field_infoby' ) || ! function_exists( 'geodir_get_posttypes' ) || ! function_exists( 'geodir_get_post_meta' ) ) {
			wp_send_json_error( array( 'message' => __( 'GeoDirectory is not active.', 'af5-core' ) ) );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'gd_place';
		$field_key = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'mood';
		$per_page  = isset( $_POST['per_page'] ) ? $this->clamp_per_page( wp_unslash( $_POST['per_page'] ) ) : 10;
		$paged     = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;

		$post_types = geodir_get_posttypes();
		if ( ! in_array( $post_type, $post_types, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid listing type.', 'af5-core' ) ) );
		}

		$field = geodir_get_field_infoby( 'htmlvar_name', $field_key, $post_type );
		if ( empty( $field ) || empty( $field['option_values'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid search field.', 'af5-core' ) ) );
		}

		$options = $this->get_field_options( $field );

		$raw_moods = isset( $_POST['moods'] ) ? (array) wp_unslash( $_POST['moods'] ) : array();
		$raw_moods = array_map( 'sanitize_text_field', $raw_moods );
		$moods     = array_values( array_intersect( $raw_moods, $options ) );

		$query = $this->build_query( $post_type, $field_key, $moods, $paged, $per_page );

		wp_send_json_success(
			array(
				'html'       => $this->render_results( $query, $field_key ),
				'foundPosts' => (int) $query->found_posts,
				'maxPages'   => (int) $query->max_num_pages,
				'page'       => $paged,
			)
		);
	}

	/**
	 * Build the filtered WP_Query for the requested moods.
	 *
	 * @param string $post_type GeoDirectory post type.
	 * @param string $field_key Custom field htmlvar_name.
	 * @param array  $moods     Whitelisted mood values to filter by.
	 * @param int    $paged     Page number.
	 * @param int    $per_page  Results per page.
	 * @return WP_Query
	 */
	private function build_query( $post_type, $field_key, $moods, $paged, $per_page ) {
		$args = array(
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'paged'               => $paged,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		if ( ! empty( $moods ) ) {
			$args['af5_mood_field']  = $field_key;
			$args['af5_mood_values'] = $moods;
		}

		return new WP_Query( $args );
	}

	/**
	 * Join the GeoDirectory details table so we can filter on the mood column.
	 *
	 * @param string   $join  Existing JOIN clause.
	 * @param WP_Query $query Current query.
	 * @return string
	 */
	public function filter_posts_join( $join, $query ) {
		if ( ! $query->get( 'af5_mood_values' ) ) {
			return $join;
		}

		global $wpdb;

		$post_type = $query->get( 'post_type' );
		$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		$table     = function_exists( 'geodir_db_cpt_table' ) ? geodir_db_cpt_table( $post_type ) : false;

		if ( ! $table ) {
			return $join;
		}

		$join .= " INNER JOIN `{$table}` AS af5_mood_detail ON af5_mood_detail.post_id = {$wpdb->posts}.ID ";

		return $join;
	}

	/**
	 * Add the FIND_IN_SET() condition(s) for the selected moods.
	 *
	 * @param string   $where Existing WHERE clause.
	 * @param WP_Query $query Current query.
	 * @return string
	 */
	public function filter_posts_where( $where, $query ) {
		$values = $query->get( 'af5_mood_values' );
		if ( empty( $values ) || ! is_array( $values ) ) {
			return $where;
		}

		global $wpdb;

		// Column name comes from a validated custom field lookup, but keep a
		// strict allow-list of characters as defence in depth since it is
		// interpolated as an identifier below.
		$field_key = preg_replace( '/[^a-z0-9_]/', '', (string) $query->get( 'af5_mood_field' ) );
		if ( '' === $field_key ) {
			return $where;
		}

		$clauses = array();
		foreach ( $values as $value ) {
			$clauses[] = $wpdb->prepare( "FIND_IN_SET( %s, af5_mood_detail.`{$field_key}` )", $value );
		}

		if ( $clauses ) {
			$where .= ' AND ( ' . implode( ' OR ', $clauses ) . ' )';
		}

		return $where;
	}

	/**
	 * Render matching listings as our own card markup (does not depend on
	 * GeoDirectory's archive item template).
	 *
	 * @param WP_Query $query     Query to render.
	 * @param string   $field_key Custom field htmlvar_name, used to show the
	 *                            matched mood value(s) on each card.
	 * @return string
	 */
	private function render_results( $query, $field_key ) {
		ob_start();

		if ( $query->have_posts() ) {
			?>
			<ul class="af5-mood-search__list">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					$this->render_card( get_the_ID(), $field_key );
				}
				?>
			</ul>
			<?php
		} else {
			?>
			<p class="af5-mood-search__empty"><?php esc_html_e( 'No listings found for the selected mood.', 'af5-core' ); ?></p>
			<?php
		}

		$html = ob_get_clean();

		wp_reset_postdata();

		return $html;
	}

	/**
	 * Output a single listing card.
	 *
	 * @param int    $post_id   Listing post ID.
	 * @param string $field_key Custom field htmlvar_name.
	 */
	private function render_card( $post_id, $field_key ) {
		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );
		$excerpt   = $this->get_card_excerpt( $post_id );
		$thumbnail = get_the_post_thumbnail( $post_id, 'medium', array( 'class' => 'af5-mood-search__thumb' ) );

		$raw_value = function_exists( 'geodir_get_post_meta' ) ? geodir_get_post_meta( $post_id, $field_key, true ) : '';
		$moods     = array_filter( array_map( 'trim', explode( ',', (string) $raw_value ) ), 'strlen' );
		?>
		<li class="af5-mood-search__card">
			<a class="af5-mood-search__card-link" href="<?php echo esc_url( $permalink ); ?>">
				<?php if ( $thumbnail ) : ?>
					<div class="af5-mood-search__thumb-wrap">
						<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_post_thumbnail() output is already escaped. ?>
					</div>
				<?php endif; ?>

				<h3 class="af5-mood-search__title"><?php echo esc_html( $title ); ?></h3>
			</a>

			<?php if ( $excerpt ) : ?>
				<p class="af5-mood-search__excerpt"><?php echo esc_html( $excerpt ); ?></p>
			<?php endif; ?>

			<?php if ( $moods ) : ?>
				<ul class="af5-mood-search__badges">
					<?php foreach ( $moods as $mood ) : ?>
						<li class="af5-mood-search__badge"><?php echo esc_html( $mood ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * Get a short, fixed-length excerpt for a card.
	 *
	 * Uses the post's manual excerpt when set, otherwise trims the content
	 * ourselves to a fixed word count so cards stay a consistent size no
	 * matter what the active theme does to `excerpt_length`.
	 *
	 * @param int $post_id Listing post ID.
	 * @return string
	 */
	private function get_card_excerpt( $post_id ) {
		if ( has_excerpt( $post_id ) ) {
			$text = get_the_excerpt( $post_id );
		} else {
			$text = wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $post_id ) ) );
		}

		return wp_trim_words( $text, self::EXCERPT_WORDS );
	}
}
