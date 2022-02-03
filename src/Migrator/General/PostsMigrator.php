<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts;
use \WP_CLI;

class PostsMigrator implements InterfaceMigrator {

	/**
	 * `meta_key` which gets assigned to exported posts, contains the original post ID.
	 */
	const META_KEY_ORIGINAL_ID = 'newspack_custom_content_migrator-original_post_id';

	/**
	 * @var string Staging site pages export file name.
	 */
	const STAGING_PAGES_EXPORT_FILE = 'newspack-staging_pages_all.xml';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var Posts.
	 */
	private $posts_logic = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new Posts();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator export-posts',
			array( $this, 'cmd_export_posts' ),
			[
				'shortdesc' => 'Exports posts/pages by post-IDs to an XML file using `wp export`.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Output directory, no ending slash.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'output-xml-file',
						'description' => 'Output XML file name.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'CSV post/page IDs to migrate.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator import-posts',
			array( $this, 'cmd_import_posts' ),
			[
				'shortdesc' => 'Imports custom posts from the XML file generated by `wp export`.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'file',
						'description' => 'XML file full path.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator export-all-staging-pages',
			array( $this, 'cmd_export_all_staging_site_pages' ),
			[
				'shortdesc' => 'Exports all Pages from the Staging site.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Output directory, no ending slash.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator import-staging-site-pages',
			array( $this, 'cmd_import_staging_site_pages' ),
			[
				'shortdesc' => 'Imports pages which were exported from the Staging site',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'input-dir',
						'description' => 'Full path to the location of the XML file containing staging files.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator delete-export-postmeta',
			array( $this, 'cmd_delete_export_postmeta' ),
			[
				'shortdesc' => 'Removes the postmeta with original ID which gets set on all exported posts/pages.',
			]
		);
	}

	/**
	 * Callable for export-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_export_posts( $args, $assoc_args ) {
		$output_dir   = isset( $assoc_args['output-dir'] ) ? $assoc_args['output-dir'] : null;
		$output_file  = isset( $assoc_args['output-xml-file'] ) ? $assoc_args['output-xml-file'] : null;
		$post_ids_csv = isset( $assoc_args['post-ids'] ) ? $assoc_args['post-ids'] : null;
		if ( is_null( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}
		if ( is_null( $output_file ) ) {
			WP_CLI::error( 'Invalid output file.' );
		}
		if ( is_null( $post_ids_csv ) ) {
			WP_CLI::error( 'One of these is mandatory: post-ids or created-from' );
		}

		$post_ids = explode( ',', $post_ids_csv );

		WP_CLI::line( sprintf( 'Exporting post IDs %s to %s...', implode( ',', $post_ids ), $output_dir . '/' . $output_file ) );

		$this->migrator_export_posts( $post_ids, $output_dir, $output_file );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Exports posts and sets a meta with the original ID when it was exported.
	 *
	 * @param array  $post_ids    Post IDs.
	 * @param string $output_dir  Output dir.
	 * @param string $output_file Output file.
	 *
	 * @return bool
	 */
	public function migrator_export_posts( $post_ids, $output_dir, $output_file ) {
		if ( empty( $post_ids ) ) {
			WP_CLI::warning( 'No posts to export.' );
			return false;
		}

		wp_cache_flush();
		foreach ( $post_ids as $key => $post_id ) {
			update_post_meta( $post_id, self::META_KEY_ORIGINAL_ID, $post_id );
		}

		wp_cache_flush();
		$post_ids = array_values( $post_ids );
		$this->export_posts( $post_ids, $output_dir, $output_file );

		return true;
	}

	/**
	 * Actual exporting of posts to file.
	 * NOTE: this function doesn't set the self::META_KEY_ORIGINAL_ID meta on exported posts, so be sure that's what you want to do.
	 *       Otherwise, use the self::migrator_export_posts() function to set the meta, too.
	 *
	 * @param array  $post_ids    Post IDs.
	 * @param string $output_dir  Output dir.
	 * @param string $output_file Output file.
	 */
	private function export_posts( $post_ids, $output_dir, $output_file ) {
		$post_ids_csv = implode( ',', $post_ids );
		WP_CLI::runcommand( "export --post__in=$post_ids_csv --dir=$output_dir --filename_format=$output_file --with_attachments" );
	}

	/**
	 * Callable for import-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_posts( $args, $assoc_args ) {
		$file = isset( $assoc_args['file'] ) ? $assoc_args['file'] : null;
		if ( is_null( $file ) || ! file_exists( $file ) ) {
			WP_CLI::error( 'Invalid file provided.' );
		}

		WP_CLI::line( 'Importing posts...' );

		$this->import_posts( $file );
		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * @param string $file File for Import.
	 *
	 * @return mixed
	 */
	public function import_posts( $file ) {
		$options = [
			'return' => true,
		];
		$output  = WP_CLI::runcommand( "import $file --authors=create", $options );

		return $output;
	}

	/**
	 * Exports all Pages from the Staging site.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_all_staging_site_pages( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args['output-dir'] ) ? $assoc_args['output-dir'] : null;
		if ( is_null( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting all Staging site Pages to %s ...', $output_dir . '/' . self::STAGING_PAGES_EXPORT_FILE ) );

		wp_reset_postdata();
		$post_ids = $this->posts_logic->get_all_posts_ids( 'page' );
		$this->migrator_export_posts( $post_ids, $output_dir, self::STAGING_PAGES_EXPORT_FILE );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Updates titles of given pages with a prefix, and sets their statuses to drafts.
	 *
	 * @param array $page_ids IDs of pages.
	 */
	public function preserve_unique_pages_from_live_as_drafts( $page_ids ) {
		foreach ( $page_ids as $id ) {
			$page              = get_post( $id );
			$page->post_title  = '[Live] ' . $page->post_title;
			$page->post_name  .= '-live_migrated';
			$page->post_status = 'draft';
			wp_update_post( $page );
		}
	}

	/**
	 * Callable for import-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_staging_site_pages( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args['input-dir'] ) ? $assoc_args['input-dir'] : null;
		if ( is_null( $input_dir ) || ! file_exists( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		// The following Page migration strategy aims to achieve two things:
		// - to keep all the Pages from the Staging site,
		// - to keep only the unique (new, different) pages from Live, but import them as Drafts, with their titles and permalinks updated.

		WP_CLI::line( 'Importing all Pages from Staging site and new pages from the Live site...' );

		// First delete those Live Pages which we're not keeping.
		WP_CLI::line( 'First clearing all Live site Pages which will be imported from Staging site to prevent duplicates...' );
		$this->delete_duplicate_live_site_pages();

		// Get IDs of the unique Live Pages which we are keeping.
		wp_reset_postdata();
		$pages_live_ids = $this->posts_logic->get_all_posts_ids( 'page' );

		// Update the remaining Live Pages which we are keeping: save them as drafts, and change their permalinks and titles.
		if ( count( $pages_live_ids ) > 0 ) {
			$this->preserve_unique_pages_from_live_as_drafts( $pages_live_ids );
			wp_cache_flush();
		} else {
			WP_CLI::warning( 'No unique Live site Pages found, continuing.' );
		}

		// Import Pages from Staging site.
		$file = $input_dir . '/' . self::STAGING_PAGES_EXPORT_FILE;
		WP_CLI::line( 'Importing Staging site Pages from  ' . $file . ' (uses `wp import` and might take a bit longer) ...' );
		$this->import_posts( $file );

		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Deletes all Live site Pages which will not be preserved, since they'll be imported from the Staging site anyway.
	 */
	public function delete_duplicate_live_site_pages() {
		$post_ids = $this->get_all_pages_duplicates_on_staging();
		if ( empty( $post_ids ) ) {
			WP_CLI::success( 'No Pages found.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting ' . count( $post_ids ) . ' pages...', count( $post_ids ) );
		foreach ( $post_ids as $id ) {
			$progress->tick();
			wp_delete_post( $id, true );
		}
		$progress->finish();

		wp_reset_postdata();
		wp_cache_flush();
	}

	/**
	 * Gets all pages which were exported from Staging and that are also found in current wp_posts.
	 *
	 * @return array|void Array of page IDs.
	 */
	public function get_all_pages_duplicates_on_staging() {
		global $wpdb;

		$ids = array();
		wp_reset_postdata();

		$staging_posts_table = 'staging_' . $wpdb->prefix . 'posts';
		$posts_table         = $wpdb->prefix . 'posts';
		// Notes on joining: post_content will have different hostnames; guid is misleading (new page on live would get the same guid).
		$sql = "SELECT wp.ID FROM {$posts_table} wp
			JOIN {$staging_posts_table} swp
				ON swp.post_name = wp.post_name
				AND swp.post_title = wp.post_title
				AND swp.post_status = wp.post_status
			WHERE wp.post_type = 'page';";
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results = $wpdb->get_results( $sql );

		if ( empty( $results ) ) {
			return;
		}

		foreach ( $results as $result ) {
			$ids[] = $result->ID;
		}
		return $ids;
	}

	/**
	 * Callable for remove-export-postmeta command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_delete_export_postmeta() {
		WP_CLI::line( sprintf( 'Deleting %s postmeta from all ther posts and pages...', self::META_KEY_ORIGINAL_ID ) );

		$args  = array(
			'post_type'   => array( 'post', 'page' ),
			'post_status' => 'publish',
			'meta_query'  => array(
				array(
					'key' => self::META_KEY_ORIGINAL_ID,
				),
			),
		);
		$query = new \WP_Query( $args );
		$posts = $query->posts;

		foreach ( $posts as $post ) {
			delete_post_meta( $post->ID, self::META_KEY_ORIGINAL_ID );
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * When exporting objects, the PostsMigrator sets PostsMigrator::META_KEY_ORIGINAL_ID meta key with the ID they had at the
	 * time. This function gets the new/current ID which changed when they were imported.
	 *
	 * @param $original_post_id ID.
	 *
	 * @return |null
	 */
	public function get_current_post_id_from_original_post_id( $original_post_id ) {
		global $wpdb;

		$new_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
			FROM {$wpdb->prefix}posts p
			JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID
			AND pm.meta_key = '%s'
			AND pm.meta_value = %d ; ",
				self::META_KEY_ORIGINAL_ID,
				$original_post_id
			)
		);

		return isset( $new_id ) ? $new_id : null;
	}

	/**
	 * Generate Newspack Iframe Block code from HTML
	 * By Creating an HTML file and uploading it to be set as the iframe source.
	 *
	 * @param string $html_to_embed HTML code to embed.
	 * @param int    $post_id Post ID where to embed the HTML, used to generate unique Iframe source filename.
	 * @return string Iframe block code to be add to the post content.
	 */
	public function embed_iframe_block_from_html( $html_to_embed, $post_id ) {
		$iframe_folder      = "iframe-$post_id-" . wp_generate_password( 8, false );
		$wp_upload_dir      = wp_upload_dir();
		$iframe_upload_dir  = '/newspack_iframes/';
		$iframe_upload_path = $wp_upload_dir['path'] . $iframe_upload_dir;
		$iframe_path        = $iframe_upload_path . $iframe_folder;

		// create iframe directory if not existing.
		if ( ! file_exists( $iframe_path ) ) {
			wp_mkdir_p( $iframe_path );
		}

		// Save iframe content in html file.
		file_put_contents( "$iframe_path/index.html", $html_to_embed );
		$iframe_src       = $wp_upload_dir['url'] . $iframe_upload_dir . $iframe_folder . DIRECTORY_SEPARATOR;
		$iframe_directory = path_join( $wp_upload_dir['subdir'] . $iframe_upload_dir, $iframe_folder );

		return '<!-- wp:newspack-blocks/iframe {"src":"' . $iframe_src . '","archiveFolder":"' . $iframe_directory . '"} /-->';
	}

	/**
	 * Generate Newspack Iframe Block code from URL.
	 *
	 * @param string $src Iframe source URL.
	 * @return string Iframe block code to be add to the post content.
	 */
	public function embed_iframe_block_from_src( $src ) {
		return '<!-- wp:newspack-blocks/iframe {"src":"' . $src . '"} /-->';
	}

	/**
	 * Generate Jetpack Slideshow Block code from Media Posts.
	 *
	 * @param int[] $post_ids Media Posts IDs.
	 * @return string Jetpack Slideshow block code to be add to the post content.
	 */
	public function generate_jetpack_slideshow_block_from_media_posts( $post_ids ) {
		$posts = [];
		foreach ( $post_ids as $post_id ) {
			$posts[] = get_post( $post_id );
		}

		if ( empty( $posts ) ) {
			return '';
		}

		$content  = '<!-- wp:jetpack/slideshow {"ids":[' . join( ',', $post_ids ) . '],"sizeSlug":"large"} -->';
		$content .= '<div class="wp-block-jetpack-slideshow aligncenter" data-effect="slide"><div class="wp-block-jetpack-slideshow_container swiper-container"><ul class="wp-block-jetpack-slideshow_swiper-wrapper swiper-wrapper">';
		foreach ( $posts as $post ) {
			$content .= '<li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="' . $post->post_title . '" class="wp-block-jetpack-slideshow_image wp-image-' . $post->ID . '" data-id="' . $post->ID . '" src="' . wp_get_attachment_url( $post->ID ) . '"/><figcaption class="wp-block-jetpack-slideshow_caption gallery-caption">' . $post->post_title . '</figcaption></figure></li>';
		}
		$content .= '</ul><a class="wp-block-jetpack-slideshow_button-prev swiper-button-prev swiper-button-white" role="button"></a><a class="wp-block-jetpack-slideshow_button-next swiper-button-next swiper-button-white" role="button"></a><a aria-label="Pause Slideshow" class="wp-block-jetpack-slideshow_button-pause" role="button"></a><div class="wp-block-jetpack-slideshow_pagination swiper-pagination swiper-pagination-white"></div></div></div><!-- /wp:jetpack/slideshow -->';
		return $content;
	}
}
