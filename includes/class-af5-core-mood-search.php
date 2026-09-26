<?php
/**
 * AF5 listing search.
 *
 * Provides the `[af5_mood_search]` shortcode which lets a visitor filter
 * GeoDirectory listings by any custom field the admin has flagged with
 * "Include in AF5 search" (Settings > Custom Fields), and renders matching
 * listings using this plugin's own listing card markup.
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
	 * Key stored in a custom field's `extra_fields` when it is enabled for
	 * the AF5 search.
	 */
	const SEARCH_FLAG = 'af5_search';

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
	 * Custom field types that can be offered as checkbox filters.
	 *
	 * @var string[]
	 */
	private static $supported_types = array( 'multiselect', 'select', 'radio', 'checkbox' );

	/**
	 * Per-request cache of searchable fields, keyed by post type.
	 *
	 * @var array
	 */
	private $fields_cache = array();

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
	 * Wire up shortcode, AJAX, admin and query hooks.
	 */
	private function __construct() {
		add_shortcode( 'af5_mood_search', array( $this, 'render_shortcode' ) );

		add_action( 'wp_ajax_af5_mood_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_af5_mood_search', array( $this, 'ajax_search' ) );

		// GD saves any `extra[...]` input into the field's extra_fields column.
		add_action( 'geodir_cfa_before_save', array( $this, 'render_admin_setting' ), 10, 2 );

		add_filter( 'posts_join', array( $this, 'filter_posts_join' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'filter_posts_where' ), 10, 2 );
	}

	/**
	 * Output the "Include in AF5 search" switch on a GD custom field's settings.
	 *
	 * @param string $post_type Post type being edited.
	 * @param object $field     Custom field object.
	 */
	public function render_admin_setting( $post_type, $field ) {
		if ( empty( $field->field_type ) || ! in_array( $field->field_type, self::$supported_types, true ) ) {
			return;
		}

		$extra   = ! empty( $field->extra_fields ) ? maybe_unserialize( $field->extra_fields ) : array();
		$enabled = is_array( $extra ) && ! empty( $extra[ self::SEARCH_FLAG ] );
		$label   = __( 'Include in AF5 search', 'af5-core' );
		$help    = __( 'Show this field as a filter in the [af5_mood_search] shortcode.', 'af5-core' );

		if ( function_exists( 'aui' ) ) {
			echo aui()->input( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AUI escapes its own output.
				array(
					'id'               => 'af5_search_' . absint( isset( $field->id ) ? $field->id : 0 ),
					'name'             => 'extra[' . self::SEARCH_FLAG . ']',
					'label_type'       => 'horizontal',
					'label_col'        => '4',
					'label'            => $label,
					'type'             => 'checkbox',
					'checked'          => $enabled,
					'value'            => '1',
					'switch'           => 'md',
					'label_force_left' => true,
					'help_text'        => function_exists( 'geodir_help_tip' ) ? geodir_help_tip( esc_html( $help ) ) : '',
				)
			);
			return;
		}

		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
			esc_attr( 'extra[' . self::SEARCH_FLAG . ']' ),
			checked( $enabled, true, false ),
			esc_html( $label )
		);
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
	 * Shortcode callback: [af5_mood_search post_type="gd_place" field="" per_page="10"]
	 *
	 * `field` is optional: a comma separated list of htmlvar_names to show only
	 * some of the fields enabled for the AF5 search. Empty shows all of them.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		if ( ! function_exists( 'geodir_get_posttypes' ) || ! function_exists( 'geodir_get_post_meta' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'post_type' => 'gd_place',
				'field'     => '',
				'per_page'  => 10,
			),
			$atts,
			'af5_mood_search'
		);

		$post_type = sanitize_key( $atts['post_type'] );
		$only      = $this->parse_field_list( $atts['field'] );
		$per_page  = $this->clamp_per_page( $atts['per_page'] );

		$post_types = geodir_get_posttypes();
		if ( ! in_array( $post_type, $post_types, true ) ) {
			return '';
		}

		$fields = $this->get_search_fields( $post_type, $only );
		if ( empty( $fields ) ) {
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

		ob_start();
		?>
		<div class="af5-mood-search">
			<form class="af5-mood-search__form" data-af5-mood-search>
				<?php wp_nonce_field( self::NONCE_ACTION, 'af5_mood_search_nonce' ); ?>
				<input type="hidden" name="af5_post_type" value="<?php echo esc_attr( $post_type ); ?>" />
				<input type="hidden" name="af5_fields" value="<?php echo esc_attr( implode( ',', $only ) ); ?>" />
				<input type="hidden" name="af5_per_page" value="<?php echo esc_attr( $per_page ); ?>" />

				<?php foreach ( $fields as $key => $field ) : ?>
					<fieldset>
						<legend><?php echo esc_html( $field['label'] ); ?></legend>

						<?php foreach ( $field['options'] as $value => $label ) : ?>
							<label class="af5-mood-search__option">
								<input
									type="checkbox"
									name="<?php echo esc_attr( 'af5_filter[' . $key . '][]' ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
								/>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>
			</form>

			<div class="af5-mood-search__results" data-af5-mood-search-results>
				<?php
				// Show all listings by default; narrows down once filters are selected.
				$query = $this->build_query( $post_type, $fields, array(), $per_page );
				echo $this->render_results( $query, $fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in render_results()/render_card().
				?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Get the custom fields enabled for the AF5 search on a post type.
	 *
	 * @param string $post_type GeoDirectory post type.
	 * @param array  $only      Optional htmlvar_names to limit the result to.
	 * @return array Keyed by htmlvar_name: array( label, type, options => array( value => label ) ).
	 */
	private function get_search_fields( $post_type, $only = array() ) {
		if ( ! isset( $this->fields_cache[ $post_type ] ) ) {
			$this->fields_cache[ $post_type ] = $this->load_search_fields( $post_type );
		}

		$fields = $this->fields_cache[ $post_type ];

		if ( ! empty( $only ) ) {
			$fields = array_intersect_key( $fields, array_flip( $only ) );
		}

		return $fields;
	}

	/**
	 * Read enabled search fields from the GD custom fields table.
	 *
	 * @param string $post_type GeoDirectory post type.
	 * @return array
	 */
	private function load_search_fields( $post_type ) {
		if ( ! defined( 'GEODIR_CUSTOM_FIELDS_TABLE' ) ) {
			return array();
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . GEODIR_CUSTOM_FIELDS_TABLE . ' WHERE post_type = %s AND is_active = 1 ORDER BY sort_order ASC',
				$post_type
			),
			ARRAY_A
		);

		$enabled = array();

		foreach ( (array) $rows as $row ) {
			if ( ! in_array( $row['field_type'], self::$supported_types, true ) ) {
				continue;
			}

			$extra = ! empty( $row['extra_fields'] ) ? maybe_unserialize( $row['extra_fields'] ) : array();
			if ( ! is_array( $extra ) || empty( $extra[ self::SEARCH_FLAG ] ) ) {
				continue;
			}

			$field = $this->prepare_field( $row );
			if ( ! empty( $field['options'] ) ) {
				$enabled[ $row['htmlvar_name'] ] = $field;
			}
		}

		return $enabled;
	}

	/**
	 * Normalise a custom field row into the shape used by the search.
	 *
	 * @param array $row Custom field row.
	 * @return array
	 */
	private function prepare_field( $row ) {
		$label = ! empty( $row['frontend_title'] ) ? __( $row['frontend_title'], 'geodirectory' ) : $row['htmlvar_name']; // phpcs:ignore WordPress.WP.I18n -- GD registers field titles for translation in its own domain.

		if ( 'checkbox' === $row['field_type'] ) {
			$options = array( '1' => __( 'Yes', 'af5-core' ) );
		} else {
			$options = $this->get_field_options( $row );
		}

		return array(
			'label'   => $label,
			'type'    => $row['field_type'],
			'options' => $options,
		);
	}

	/**
	 * Parse a custom field's option_values into value => label pairs.
	 *
	 * @param array $row Custom field row.
	 * @return array
	 */
	private function get_field_options( $row ) {
		$options = array();

		if ( function_exists( 'geodir_string_values_to_options' ) ) {
			foreach ( geodir_string_values_to_options( (string) $row['option_values'], true ) as $option ) {
				// Skip optgroup markers and empty placeholder options.
				if ( ! empty( $option['optgroup'] ) || '' === (string) $option['value'] ) {
					continue;
				}
				$options[ (string) $option['value'] ] = $option['label'];
			}

			return $options;
		}

		$lines = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $row['option_values'] ) ), 'strlen' );
		foreach ( $lines as $line ) {
			$options[ $line ] = $line;
		}

		return $options;
	}

	/**
	 * Turn a comma separated list of htmlvar_names into a clean array.
	 *
	 * @param string $list Raw list.
	 * @return array
	 */
	private function parse_field_list( $list ) {
		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $list ) ) ) );
	}

	/**
	 * Whitelist requested filter values against the searchable fields.
	 *
	 * @param mixed $raw    Raw `af5_filter` input (already unslashed).
	 * @param array $fields Searchable fields.
	 * @return array htmlvar_name => array of allowed values.
	 */
	private function sanitize_filters( $raw, $fields ) {
		$filters = array();

		if ( ! is_array( $raw ) ) {
			return $filters;
		}

		foreach ( $fields as $key => $field ) {
			if ( empty( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
				continue;
			}

			$values = array_map( 'sanitize_text_field', $raw[ $key ] );
			$values = array_values( array_intersect( $values, array_map( 'strval', array_keys( $field['options'] ) ) ) );

			if ( $values ) {
				$filters[ $key ] = $values;
			}
		}

		return $filters;
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
	 * AJAX handler for the search.
	 */
	public function ajax_search() {
		check_ajax_referer( self::NONCE_ACTION, 'af5_mood_search_nonce' );

		if ( ! function_exists( 'geodir_get_posttypes' ) || ! function_exists( 'geodir_get_post_meta' ) ) {
			wp_send_json_error( array( 'message' => __( 'GeoDirectory is not active.', 'af5-core' ) ) );
		}

		$post_type = isset( $_POST['af5_post_type'] ) ? sanitize_key( wp_unslash( $_POST['af5_post_type'] ) ) : 'gd_place';
		$only      = isset( $_POST['af5_fields'] ) ? $this->parse_field_list( sanitize_text_field( wp_unslash( $_POST['af5_fields'] ) ) ) : array();
		$per_page  = isset( $_POST['af5_per_page'] ) ? $this->clamp_per_page( wp_unslash( $_POST['af5_per_page'] ) ) : 10;

		$post_types = geodir_get_posttypes();
		if ( ! in_array( $post_type, $post_types, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid listing type.', 'af5-core' ) ) );
		}

		// Only fields the admin enabled are searchable, whatever the request says.
		$fields = $this->get_search_fields( $post_type, $only );
		if ( empty( $fields ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid search field.', 'af5-core' ) ) );
		}

		$raw_filters = isset( $_POST['af5_filter'] ) ? wp_unslash( $_POST['af5_filter'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted in sanitize_filters().
		$selected    = $this->sanitize_filters( $raw_filters, $fields );

		$query = $this->build_query( $post_type, $fields, $selected, $per_page );

		wp_send_json_success(
			array(
				'html' => $this->render_results( $query, $fields ),
			)
		);
	}

	/**
	 * Build the filtered WP_Query for the selected filters.
	 *
	 * @param string $post_type GeoDirectory post type.
	 * @param array  $fields    Searchable fields.
	 * @param array  $selected  Whitelisted htmlvar_name => values to filter by.
	 * @param int    $per_page  Results per page.
	 * @return WP_Query
	 */
	private function build_query( $post_type, $fields, $selected, $per_page ) {
		$args = array(
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		if ( ! empty( $selected ) ) {
			$filters = array();
			foreach ( $selected as $key => $values ) {
				$filters[ $key ] = array(
					'type'   => $fields[ $key ]['type'],
					'values' => $values,
				);
			}
			$args['af5_filters'] = $filters;
		}

		return new WP_Query( $args );
	}

	/**
	 * Join the GeoDirectory details table so we can filter on its columns.
	 *
	 * @param string   $join  Existing JOIN clause.
	 * @param WP_Query $query Current query.
	 * @return string
	 */
	public function filter_posts_join( $join, $query ) {
		if ( ! $query->get( 'af5_filters' ) ) {
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
	 * Add the filter conditions: values within a field are OR'ed, separate
	 * fields are AND'ed.
	 *
	 * @param string   $where Existing WHERE clause.
	 * @param WP_Query $query Current query.
	 * @return string
	 */
	public function filter_posts_where( $where, $query ) {
		$filters = $query->get( 'af5_filters' );
		if ( empty( $filters ) || ! is_array( $filters ) ) {
			return $where;
		}

		global $wpdb;

		foreach ( $filters as $key => $filter ) {
			// Column name comes from a validated custom field lookup, but keep a
			// strict allow-list of characters as defence in depth since it is
			// interpolated as an identifier below.
			$column = preg_replace( '/[^a-z0-9_]/', '', (string) $key );
			if ( '' === $column || empty( $filter['values'] ) ) {
				continue;
			}

			$clauses = array();
			foreach ( $filter['values'] as $value ) {
				if ( 'multiselect' === $filter['type'] ) {
					$clauses[] = $wpdb->prepare( "FIND_IN_SET( %s, af5_mood_detail.`{$column}` )", $value );
				} else {
					$clauses[] = $wpdb->prepare( "af5_mood_detail.`{$column}` = %s", $value );
				}
			}

			$where .= ' AND ( ' . implode( ' OR ', $clauses ) . ' )';
		}

		return $where;
	}

	/**
	 * Render matching listings as our own card markup (does not depend on
	 * GeoDirectory's archive item template).
	 *
	 * @param WP_Query $query  Query to render.
	 * @param array    $fields Searchable fields, used to show each card's
	 *                         matching values as badges.
	 * @return string
	 */
	private function render_results( $query, $fields ) {
		ob_start();

		if ( $query->have_posts() ) {
			?>
			<ul class="af5-mood-search__list">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					$this->render_card( get_the_ID(), $fields );
				}
				?>
			</ul>
			<?php
		} else {
			?>
			<p class="af5-mood-search__empty"><?php esc_html_e( 'No listings match your selection.', 'af5-core' ); ?></p>
			<?php
		}

		$html = ob_get_clean();

		wp_reset_postdata();

		return $html;
	}

	/**
	 * Get badge labels for a listing from the searchable fields.
	 *
	 * @param int   $post_id Listing post ID.
	 * @param array $fields  Searchable fields.
	 * @return string[]
	 */
	private function get_card_badges( $post_id, $fields ) {
		$badges = array();

		foreach ( $fields as $key => $field ) {
			$raw = (string) geodir_get_post_meta( $post_id, $key, true );

			if ( 'checkbox' === $field['type'] ) {
				if ( '1' === $raw ) {
					$badges[] = $field['label'];
				}
				continue;
			}

			$values = 'multiselect' === $field['type'] ? explode( ',', $raw ) : array( $raw );
			foreach ( array_filter( array_map( 'trim', $values ), 'strlen' ) as $value ) {
				$badges[] = isset( $field['options'][ $value ] ) ? $field['options'][ $value ] : $value;
			}
		}

		return $badges;
	}

	/**
	 * Output a single listing card.
	 *
	 * @param int   $post_id Listing post ID.
	 * @param array $fields  Searchable fields.
	 */
	private function render_card( $post_id, $fields ) {
		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );
		$excerpt   = $this->get_card_excerpt( $post_id );
		$thumbnail = get_the_post_thumbnail( $post_id, 'medium', array( 'class' => 'af5-mood-search__thumb' ) );
		$badges    = $this->get_card_badges( $post_id, $fields );
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

			<?php if ( $badges ) : ?>
				<ul class="af5-mood-search__badges">
					<?php foreach ( $badges as $badge ) : ?>
						<li class="af5-mood-search__badge"><?php echo esc_html( $badge ); ?></li>
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
