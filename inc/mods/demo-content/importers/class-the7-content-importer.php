<?php
/**
 * @package The7
 */

defined( 'ABSPATH' ) || exit;

class The7_Content_Importer extends WP_Import {

	const THE7_PROCESSED_DATA_KEY = 'the7_demo_content_processed_data';
	const THE7_POST_RESOURCES_META_KEY = 'the7_exporter_post_resources';

	protected $imported_posts = 0;
	protected $total_posts = 0;
	protected $parsed_file = null;
	protected $filtered_post_id;
	protected $filter_by_url;
	protected $filter_by_id;

	public function import_batch( $file, $batch, $fetch_attachments = true ) {
		$this->fetch_attachments = $fetch_attachments;

		ob_start();

		if ( ! $batch ) {
			$this->import( $file );

			ob_end_clean();

			return [
				'imported' => count( $this->processed_posts ),
				'left'     => 0,
				'total'    => $this->total_posts,
			];
		}

		$this->read_processed_data_from_cache();

		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( $this, 'bump_request_timeout' ) );

		$this->import_start( $file );
		$this->cut_batch( $batch );

		$this->get_author_mapping();

		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_tags();
		$this->process_terms();
		$this->process_posts();
		wp_suspend_cache_invalidation( false );

		// update incorrect/missing information in the DB
		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->remap_featured_images();

		$this->import_end();

		$this->cache_processed_data();

		ob_end_clean();

		return [
			'imported' => $this->imported_posts,
			'left'     => $this->total_posts - $this->imported_posts,
			'total'    => $this->total_posts,
		];
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	function import( $file ) {
		ob_start();
		parent::import( $file );
		ob_get_clean();
	}

	protected function cut_batch( $batch ) {
		$processed_posts_ids = array_keys( $this->processed_posts );

		foreach ( $this->posts as $i => $post ) {
			if ( in_array( $post['post_id'], $processed_posts_ids, false ) ) {
				$this->imported_posts++;
				unset( $this->posts[ $i ] );
			}
		}

		$this->posts = array_slice( $this->posts, 0, $batch );
		$this->imported_posts += count( $this->posts );
	}

	public function import_start( $file ) {
		parent::import_start( $file );

		foreach ( $this->posts as $index => $post ) {
			$only_attachments = $this->fetch_attachments && $post['post_type'] !== 'attachment';
			$only_posts = ! $this->fetch_attachments && $post['post_type'] === 'attachment';

			if ( $only_attachments || $only_posts ) {
				unset( $this->posts[ $index ] );
			}
		}

		$this->total_posts = count( $this->posts );

		// Do not alter existing posts, always import the new one.
		add_filter( 'wp_import_existing_post', '__return_zero' );
		add_filter( 'wp_import_post_data_raw', [ $this, 'wc_products_filter' ] );
		add_filter( 'import_post_meta_key', function( $key ) {
			return $key === static::THE7_POST_RESOURCES_META_KEY ? false : $key;
		} );
	}

	public function import_end() {
		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		do_action( 'import_end', $this );
	}

	public function fetch_remote_file( $url, $post ) {
		if ( defined( 'WP_TESTS' ) ) {
			return $this->get_noimage( $url );
		}

		return parent::fetch_remote_file( $url, $post );
	}

	/**
	 * Parse a WXR file
	 *
	 * @param string $file Path to WXR file for parsing
	 *
	 * @return array Information gathered from the WXR file
	 */
	public function parse( $file ) {
		if ( $this->parsed_file === null ) {
			$this->parsed_file = apply_filters( 'wp_import_parse', parent::parse( $file ) );
		}

		return $this->parsed_file;
	}

	/**
	 * Method override.
	 *
	 * Add custom meta to menu items.
	 *
	 * @param array $item
	 */
	public function process_menu_item( $item ) {

		// skip draft, orphaned menu items
		if ( 'draft' == $item['status'] ) {
			return;
		}

		$menu_slug = false;
		if ( isset( $item['terms'] ) ) {
			// loop through terms, assume first nav_menu term is correct menu
			foreach ( $item['terms'] as $term ) {
				if ( 'nav_menu' == $term['domain'] ) {
					$menu_slug = $term['slug'];
					break;
				}
			}
		}

		// no nav_menu term associated with this menu item
		if ( ! $menu_slug ) {
			_e( 'Menu item skipped due to missing menu slug', 'wordpress-importer' );
			echo '<br />';

			return;
		}

		$menu_id = term_exists( $menu_slug, 'nav_menu' );
		if ( ! $menu_id ) {
			printf( __( 'Menu item skipped due to invalid menu slug: %s', 'wordpress-importer' ), esc_html( $menu_slug ) );
			echo '<br />';

			return;
		} else {
			$menu_id = is_array( $menu_id ) ? $menu_id['term_id'] : $menu_id;
		}

		foreach ( $item['postmeta'] as $meta ) {
			${$meta['key']} = $meta['value'];
		}

		if ( 'taxonomy' == $_menu_item_type && isset( $this->processed_terms[ intval( $_menu_item_object_id ) ] ) ) {
			$_menu_item_object_id = $this->processed_terms[ intval( $_menu_item_object_id ) ];
		} else if ( 'post_type' == $_menu_item_type && isset( $this->processed_posts[ intval( $_menu_item_object_id ) ] ) ) {
			$_menu_item_object_id = $this->processed_posts[ intval( $_menu_item_object_id ) ];
		} else if ( 'custom' != $_menu_item_type ) {
			// associated object is missing or not imported yet, we'll retry later
			$this->missing_menu_items[] = $item;

			return;
		}

		if ( isset( $this->processed_menu_items[ intval( $_menu_item_menu_item_parent ) ] ) ) {
			$_menu_item_menu_item_parent = $this->processed_menu_items[ intval( $_menu_item_menu_item_parent ) ];
		} else if ( $_menu_item_menu_item_parent ) {
			$this->menu_item_orphans[ intval( $item['post_id'] ) ] = (int) $_menu_item_menu_item_parent;
			$_menu_item_menu_item_parent                           = 0;
		}

		// wp_update_nav_menu_item expects CSS classes as a space separated string
		$_menu_item_classes = maybe_unserialize( $_menu_item_classes );
		if ( is_array( $_menu_item_classes ) ) {
			$_menu_item_classes = implode( ' ', $_menu_item_classes );
		}

		$args = array(
			'menu-item-object-id'   => $_menu_item_object_id,
			'menu-item-object'      => $_menu_item_object,
			'menu-item-parent-id'   => $_menu_item_menu_item_parent,
			'menu-item-position'    => intval( $item['menu_order'] ),
			'menu-item-type'        => $_menu_item_type,
			'menu-item-title'       => $item['post_title'],
			'menu-item-url'         => $_menu_item_url,
			'menu-item-description' => $item['post_content'],
			'menu-item-attr-title'  => $item['post_excerpt'],
			'menu-item-target'      => $_menu_item_target,
			'menu-item-classes'     => $_menu_item_classes,
			'menu-item-xfn'         => $_menu_item_xfn,
			'menu-item-status'      => $item['status'],
		);

		/**
		 * Filter menu item args.
		 *
		 * @since 7.4.1
		 */
		$args = apply_filters( 'wxr_menu_item_args', $args, $menu_id );

		$id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( $id && ! is_wp_error( $id ) ) {
			$this->processed_menu_items[ intval( $item['post_id'] ) ] = (int) $id;

			// Add custom meta.
			$meta_to_exclude   = array_keys( $args );
			$meta_to_exclude[] = 'menu-item-menu-item-parent';
			foreach ( $item['postmeta'] as $meta ) {
				$key = str_replace( '_', '-', ltrim( $meta['key'], '_' ) );
				if ( ! empty( $meta['value'] ) && ! in_array( $key, $meta_to_exclude, true ) ) {
					update_post_meta( $id, $meta['key'], maybe_unserialize( $meta['value'] ) );
				}
			}
		}
	}

	/**
	 * @param array $raw_post
	 *
	 * @return array
	 */
	public function wc_products_filter( $raw_post ) {
		global $wpdb;

		if ( $raw_post['post_type'] !== 'product' ) {
			return $raw_post;
		}

		if ( empty( $raw_post['terms'] ) ) {
			return $raw_post;
		}

		foreach ( $raw_post['terms'] as $term ) {
			$domain = $term['domain'];

			if ( false === strpos( $domain, 'pa_' ) || taxonomy_exists( $domain ) ) {
				continue;
			}

			// Make sure taxonomy exists!
			$nicename     = strtolower( sanitize_title( str_replace( 'pa_', '', $domain ) ) );
			$exists_in_db = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s;",
					$nicename
				)
			);

			// Create the taxonomy
			if ( ! $exists_in_db ) {
				$wpdb->insert(
					"{$wpdb->prefix}woocommerce_attribute_taxonomies",
					array(
						'attribute_name'    => $nicename,
						'attribute_type'    => 'select',
						'attribute_orderby' => 'menu_order',
					),
					array( '%s', '%s', '%s' )
				);
			}

			// Register the taxonomy now so that the import works!
			$tax_args = array(
				'hierarchical' => true,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			);
			register_taxonomy(
				$domain,
				apply_filters( 'woocommerce_taxonomy_objects_' . $domain, array( 'product' ) ),
				apply_filters( 'woocommerce_taxonomy_args_' . $domain, $tax_args )
			);
		}

		return $raw_post;
	}

	/**
	 * Cache processed data to be used in future imports.
	 */
	public function cache_processed_data() {
		self::clear_session();

		$data = [
			'processed_authors'     => $this->processed_authors,
			'processed_terms'       => $this->processed_terms,
			'processed_posts'       => $this->processed_posts,
			'featured_images'       => $this->featured_images,
			'url_remap'             => $this->url_remap,
		];
		set_transient( self::THE7_PROCESSED_DATA_KEY, $data, 1200 );
	}

	/**
	 * Read populate internal variables with processed data from cache.
	 */
	public function read_processed_data_from_cache() {
		$processed_data = get_transient( self::THE7_PROCESSED_DATA_KEY );
		if ( ! is_array( $processed_data ) || empty( $processed_data ) ) {
			return;
		}

		foreach ( $processed_data as $group => $data ) {
			if ( property_exists( $this, $group ) && $data ) {
				$this->$group = $data;
			}
		}
	}

	public static function clear_session() {
		delete_transient( self::THE7_PROCESSED_DATA_KEY );
	}

	/**
	 * Decide whether or not the importer is allowed to create users.
	 * Default is false (we don't want to scare users), can be filtered via import_allow_create_users.
	 *
	 * @return bool True if creating users is allowed
	 */
	public function allow_create_users() {
		return apply_filters( 'import_allow_create_users', false );
	}

	public function get_processed_post( $post_id ) {
		if ( isset( $this->processed_posts[ $post_id ] ) ) {
			return $this->processed_posts[ $post_id ];
		}

		return 0;
	}

	public function get_processed_filtered_post() {
		return $this->get_processed_post( $this->filtered_post_id );
	}

	public function get_processed_term( $term_id ) {
		if ( isset( $this->processed_terms[ $term_id ] ) ) {
			return $this->processed_terms[ $term_id ];
		}

		return 0;
	}

	public function add_filter_by_url( $post_url ) {
		$this->filter_by_url = $post_url;
		$this->add_import_posts_filter();
	}

	public function add_filter_by_id( $post_id ) {
		$this->filter_by_id = $post_id;
		$this->add_import_posts_filter();
	}

	protected function find_post_by_url( $posts ) {
		foreach ( $posts as $post ) {
			if ( (string) $post['link'] === (string) $this->filter_by_url ) {
				return $post;
			}
		}

		return [];
	}

	protected function find_post_by_id( $posts ) {
		foreach ( $posts as $post ) {
			if ( (int) $post['post_id'] === (int) $this->filter_by_id ) {
				return $post;
			}
		}

		return [];
	}

	protected function add_import_posts_filter() {
		add_filter( 'wp_import_parse', function( $parsed_file ) {
			$posts = $parsed_file['posts'];

			if ( $this->filter_by_url ) {
				$found_post = $this->find_post_by_url( $posts );
			} elseif ( $this->filter_by_id ) {
				$found_post = $this->find_post_by_id( $posts );
			}

			if ( empty( $found_post ) ) {
				return [];
			}

			$this->filtered_post_id = $found_post['post_id'];
			$filtered_posts = [ $found_post ];

			$resources         = array_reduce(
				$found_post['postmeta'],
				function ( $acc, $item ) {
					return $item['key'] === static::THE7_POST_RESOURCES_META_KEY ? maybe_unserialize( $item['value'] ) : $acc;
				},
				[]
			);

			$posts_terms = [];
			foreach ( $posts as $post ) {
				if ( in_array( $post['post_id'], $resources, false ) ) {
					$filtered_posts[] = $post;

					if ( ! empty( $post['terms'] ) ) {
						$posts_terms[] = $post['terms'];
					}
				}
			}

			$posts_terms = array_merge( [], ...$posts_terms );
			$posts_terms = array_unique( wp_list_pluck( $posts_terms, 'slug' ) );

			$parsed_file['posts'] = $filtered_posts;

			$parsed_file['categories'] = array_filter( $parsed_file['categories'], function( $term ) use ( $posts_terms ) {
				return in_array( $term['category_nicename'], $posts_terms, true );
			} );

			$parsed_file['tags'] = array_filter( $parsed_file['tags'], function( $term ) use ( $posts_terms ) {
				return in_array( $term['tag_slug'], $posts_terms, true );
			} );

			$parsed_file['terms'] = array_filter( $parsed_file['terms'], function( $term ) use ( $posts_terms ) {
				return in_array( $term['slug'], $posts_terms, true );
			} );

			return $parsed_file;
		});
	}

	protected function get_noimage( $origin_img_url ) {
		$wp_filetype = wp_check_filetype( $origin_img_url );
		$ext = $wp_filetype['ext'];

		return array(
			'file'  => PRESSCORE_ADMIN_DIR . "/assets/images/noimage.{$ext}",
			'url'   => PRESSCORE_ADMIN_URI . "/assets/images/noimage.{$ext}",
			'type'  => $wp_filetype['type'],
			'error' => false,
		);
	}
}
