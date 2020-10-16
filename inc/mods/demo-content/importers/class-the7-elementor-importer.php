<?php
/**
 * @package The7
 */

defined( 'ABSPATH' ) || exit;

class The7_Elementor_Importer {

	/**
	 * @var The7_Demo_Content_Tracker
	 */
	private $content_tracker;
	private $importer;

	public function __construct( $importer, $content_tracker ) {
		$this->content_tracker = $content_tracker;
		$this->importer = $importer;
	}

	public function import_options( $options ) {
		$options_whitelist = [
			'elementor_scheme_color',
			'elementor_scheme_typography',
			'elementor_scheme_color-picker',
			'elementor_cpt_support',
			'elementor_disable_color_schemes',
			'elementor_disable_typography_schemes',
			'elementor_use_the7_schemes',
		];

		$origin_options = [];

		foreach ( $options_whitelist as $option ) {
			$origin_options[ $option ] = get_option( $option, null );

			if ( isset( $options[ $option ] ) ) {
				update_option( $option, $options[ $option ] );
			} else {
				delete_option( $option );
			}
		}

		$this->content_tracker->add( 'origin_elementor_options', $origin_options );

		if ( class_exists( 'Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		if ( class_exists( 'ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();
		}
	}

	/**
	 * @param array $kit_settings
	 */
	public function import_kit_settings( $kit_settings ) {
		$white_list= [
			'default_generic_fonts',
			'container_width',
			'container_width_tablet',
			'container_width_mobile',
			'space_between_widgets',
			'stretched_section_container',
			'page_title_selector',
			'global_image_lightbox',
			'viewport_lg',
			'viewport_md',
		];

		$kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();
		$kit = \Elementor\Plugin::$instance->documents->get( $kit_id );
		$current_settings = (array) $kit->get_meta( \Elementor\Core\Settings\Page\Manager::META_KEY ) ?: [];

		foreach ( $white_list as $key ) {
			if ( isset( $kit_settings[ $key ] ) ) {
				$current_settings[ $key ] = $kit_settings[ $key ];
			} else {
				unset( $current_settings[ $key ] );
			}
		}

		$page_settings_manager = \Elementor\Core\Settings\Manager::get_settings_managers( 'page' );
		$page_settings_manager->save_settings( $current_settings, $kit_id );
	}

	public function fix_elementor_data() {
		global $wpdb;

		$ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data'");

		foreach ( $ids as $post_id ) {
			$elementor_data = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
			static::apply_elementor_data_patch( $elementor_data, [ $this, 'fix_the7_widgets_terms' ] );
			update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
		}
	}

	public static function apply_elementor_data_patch( &$elementor_data, $callback ) {
		foreach ( $elementor_data as &$element ) {
			if ( isset( $element['elType'] ) && $element['elType'] === 'widget' ) {
				if ( is_callable( $callback ) ) {
					$element = $callback( $element );
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				static::apply_elementor_data_patch( $element['elements'], $callback );
			}
		}
	}

	protected function fix_the7_widgets_terms( $widget ) {
		if ( isset( $widget['settings']['terms'] ) && is_array( $widget['settings']['terms'] ) ) {
			foreach ( $widget['settings']['terms'] as &$term ) {
				$term = (string) $this->importer->get_processed_term( $term );
			}
		}

		return $widget;
	}
}
