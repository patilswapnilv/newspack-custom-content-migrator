<?php

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \WP_CLI;

class EmbarcaderoMigrator implements InterfaceCommand {
	const LOG_FILE                                 = 'embarcadero_importer.log';
	const TAGS_LOG_FILE                            = 'embarcadero_tags_migrator.log';
	const FEATURED_IMAGES_LOG_FILE                 = 'embarcadero_featured_images_migrator.log';
	const MORE_POSTS_LOG_FILE                      = 'embarcadero_more_posts_migrator.log';
	const EMBARCADERO_ORIGINAL_ID_META_KEY         = '_newspack_import_id';
	const EMBARCADERO_IMPORTED_TAG_META_KEY        = '_newspack_import_tag_id';
	const EMBARCADERO_IMPORTED_FEATURED_META_KEY   = '_newspack_import_featured_image_id';
	const EMBARCADERO_IMPORTED_MORE_POSTS_META_KEY = '_newspack_import_more_posts_image_id';
	const EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY   = '_newspack_media_import_id';
	const DEFAULT_AUTHOR_NAME                      = 'Staff';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Instance of Attachments Login
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger                    = new Logger();
		$this->attachments               = new Attachments();
		$this->coauthorsplus_logic       = new CoAuthorPlus();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-import-posts-content',
			array( $this, 'cmd_embarcadero_import_posts_content' ),
			[
				'shortdesc' => 'Import Embarcadero\s post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-csv-file-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-byline-email-file-path',
						'description' => 'Path to the CSV file containing the stories\'s bylines emails to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-sections-file-path',
						'description' => 'Path to the CSV file containing the stories\'s sections (categories) to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-photos-file-path',
						'description' => 'Path to the CSV file containing the stories\'s photos to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-photos-dir-path',
						'description' => 'Path to the directory containing the stories\'s photos files to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-media-file-path',
						'description' => 'Path to the CSV file containing the stories\'s media to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-carousel-items-dir-path',
						'description' => 'Path to the CSV file containing the stories\'s carousel items to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-post-tags',
			array( $this, 'cmd_embarcadero_migrate_post_tags' ),
			[
				'shortdesc' => 'Import Embarcadero\s post tags.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-tags-csv-file-path',
						'description' => 'Path to the CSV file containing the stories tags to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-posts-featured-image',
			array( $this, 'cmd_embarcadero_migrate_posts_featured_image' ),
			[
				'shortdesc' => 'Import Embarcadero\s post featured image.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-photos-file-path',
						'description' => 'Path to the CSV file containing the stories\'s photos to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-photos-dir-path',
						'description' => 'Path to the directory containing the stories\'s photos files to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-more-posts-block',
			array( $this, 'cmd_embarcadero_migrate_more_posts_block' ),
			[
				'shortdesc' => 'Import Embarcadero\s post "more posts" block.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-csv-file-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-media-file-path',
						'description' => 'Path to the CSV file containing the stories\'s media to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-import-posts-content".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_import_posts_content( $args, $assoc_args ) {
		$story_csv_file_path               = $assoc_args['story-csv-file-path'];
		$story_byline_emails_csv_file_path = $assoc_args['story-byline-email-file-path'];
		$story_sections_csv_file_path      = $assoc_args['story-sections-file-path'];
		$story_photos_csv_file_path        = $assoc_args['story-photos-file-path'];
		$story_photos_dir_path             = $assoc_args['story-photos-dir-path'];
		$story_media_csv_file_path         = $assoc_args['story-media-file-path'];
		$story_carousel_items_dir_path     = $assoc_args['story-carousel-items-dir-path'];

		// Validate co-authors plugin is active.
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$posts                 = $this->get_data_from_csv( $story_csv_file_path );
		$contributors          = $this->get_data_from_csv( $story_byline_emails_csv_file_path );
		$sections              = $this->get_data_from_csv( $story_sections_csv_file_path );
		$photos                = $this->get_data_from_csv( $story_photos_csv_file_path );
		$media                 = $this->get_data_from_csv( $story_media_csv_file_path );
		$carousel_items        = $this->get_data_from_csv( $story_carousel_items_dir_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_ORIGINAL_ID_META_KEY );

		// Skip already imported posts.
		$posts = array_values(
			array_filter(
				$posts,
				function( $post ) use ( $imported_original_ids ) {
					return ! in_array( $post['story_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Importing post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::LINE );

			// var_dump( $post['seo_link'] );
			// if ( ! str_contains( $post['story_text'], '{photo' ) ) {
			// continue;
			// }

			$contributor_index = array_search( $post['byline'], array_column( $contributors, 'full_name' ) );

			$wp_contributor_id = null;
			if ( false !== $contributor_index ) {
				$contributor       = $contributors[ $contributor_index ];
				$wp_contributor_id = $this->get_or_create_contributor( $contributor['full_name'], $contributor['email_address'] );

				if ( is_wp_error( $wp_contributor_id ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not get or create contributor %s: %s', $contributor['full_name'], $wp_contributor_id->get_error_message() ), Logger::WARNING );
					$wp_contributor_id = null;
				}
			}

			// Get the post slug.
			$post_name = $this->migrate_post_slug( $post['seo_link'] );

			$post_data = [
				'post_title'   => $post['headline'],
				'post_content' => $post['story_text'],
				'post_excerpt' => $post['front_paragraph'],
				'post_status'  => 'Yes' === $post['approved'] ? 'publish' : 'draft',
				'post_type'    => 'post',
				'post_date'    => gmdate( 'Y-m-d H:i:s', $post['date_epoch'] ),
				'post_author'  => $wp_contributor_id,
			];

			if ( ! empty( $post_name ) ) {
				$post_data['post_name'] = $post_name;
			}

			if ( '0' !== $post['date_updated_epoch'] ) {
				$post_data['post_modified'] = gmdate( 'Y-m-d H:i:s', $post['date_updated_epoch'] );
			}

			// Create the post.
			$wp_post_id = wp_insert_post( $post_data );

			if ( is_wp_error( $wp_post_id ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not create post "%s": %s', $post['headline'], $wp_post_id->get_error_message() ), Logger::WARNING );
				continue;
			}

			if ( 0 === $wp_post_id ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not create post "%s"', $post['headline'] ), Logger::WARNING );
				continue;
			}

			// Migrate post content shortcodes.
			$post_content         = str_replace( "\n", "</p>\n<p>", '<p>' . $post['story_text'] . '</p>' );
			$updated_post_content = $this->migrate_post_content_shortcodes( $post['story_id'], $wp_post_id, $post_content, $photos, $story_photos_dir_path, $media, $carousel_items );

			// Set the original ID.
			update_post_meta( $wp_post_id, self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			// Set the post subhead.
			update_post_meta( $wp_post_id, 'newspack_post_subtitle', $post['subhead'] );

			// Add tag_2 to the post.
			if ( ! empty( $post['tag_2'] ) ) {
				$updated_post_content .= serialize_block(
					$this->gutenberg_block_generator->get_paragraph( '<em>' . $post['tag_2'] . '</em>' )
				);
			}

			// Set co-author if needed.
			if ( ! $wp_contributor_id && 'byline' === $post['byline_tag_option'] ) {
				$coauthors     = $this->get_co_authors_from_bylines( $post['byline'] );
				$co_author_ids = $this->get_generate_coauthor_ids( $coauthors );
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_author_ids, $wp_post_id );

				$this->logger->log( self::LOG_FILE, sprintf( 'Assigned co-authors %s to post "%s"', implode( ', ', $coauthors ), $post['headline'] ), Logger::LINE );
			}

			if ( 'tag' === $post['byline_tag_option'] ) {
				if ( ! $wp_contributor_id ) {
					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_paragraph( '<em>By ' . $post['byline'] . '</em>' )
					);
				} else {
					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_author_profile( $wp_contributor_id )
					);
				}

				// Add "Bottom Byline" tag to the post.
				wp_set_post_tags( $wp_post_id, 'Bottom Byline', true );
			}

			// Update post content if needed.
			if ( $updated_post_content !== $post['story_text'] ) {
				wp_update_post(
					[
						'ID'           => $wp_post_id,
						'post_content' => $updated_post_content,
					]
				);
			}

			// Set categories from sections data.
			$post_section_index = array_search( $post['section_id'], array_column( $sections, 'section_id' ) );
			if ( false === $post_section_index ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find section %s for post %s', $post['section_id'], $post['headline'] ), Logger::WARNING );
			} else {
				$section     = $sections[ $post_section_index ];
				$category_id = $this->get_or_create_category( $section['section'] );

				if ( $category_id ) {
					wp_set_post_categories( $wp_post_id, [ $category_id ] );
				}
			}

			// A few meta fields.
			if ( 'Yes' === $post['baycities'] ) {
				update_post_meta( $wp_post_id, 'newspack_post_baycities', true );
			}

			if ( 'Yes' === $post['calmatters'] ) {
				update_post_meta( $wp_post_id, 'newspack_post_calmatters', true );
			}

			if ( 'Yes' === $post['council'] ) {
				update_post_meta( $wp_post_id, 'newspack_post_council', true );
			}

			if ( ! empty( $post['layout'] ) ) {
				wp_set_post_tags( $wp_post_id, 'Layout ' . $post['layout'], true );
			}

			if ( ! empty( $post['hero_headline_size'] ) ) {
				wp_set_post_tags( $wp_post_id, 'Headline ' . $post['hero_headline_size'], true );
			}

			$this->logger->log( self::LOG_FILE, sprintf( 'Imported post %d/%d: %d with the ID %d', $post_index + 1, count( $posts ), $post['story_id'], $wp_post_id ), Logger::SUCCESS );

			if ( 2000 === $post_index ) {
				break;
			}
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-post-tags".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_post_tags( $args, $assoc_args ) {
		$story_tags_csv_file_path = $assoc_args['story-tags-csv-file-path'];

		$tags                  = $this->get_data_from_csv( $story_tags_csv_file_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_IMPORTED_TAG_META_KEY );

		$grouped_tags = [];

		foreach ( $tags as $item ) {
			$tag_name = $item['tag'];
			$story_id = $item['story_id'];

			// Skip already imported post tags.
			if ( in_array( $story_id, $imported_original_ids ) ) {
				continue;
			}

			if ( ! isset( $grouped_tags[ $story_id ] ) ) {
				$grouped_tags[ $story_id ] = [];
			}

			$grouped_tags[ $story_id ][] = $tag_name;
		}

		foreach ( $grouped_tags as $story_id => $tags ) {
			$wp_post_id = $this->get_post_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $story_id );

			if ( ! $wp_post_id ) {
				$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $story_id ), Logger::WARNING );
				continue;
			}

			wp_set_post_tags( $wp_post_id, $tags, true );

			update_post_meta( $wp_post_id, self::EMBARCADERO_IMPORTED_TAG_META_KEY, $story_id );

			$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Imported tags for post %d with the original ID %d: %s', $wp_post_id, $story_id, implode( ', ', $tags ) ), Logger::SUCCESS );
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-posts-featured-image".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_posts_featured_image( $args, $assoc_args ) {
		$story_photos_csv_file_path = $assoc_args['story-photos-file-path'];
		$story_photos_dir_path      = $assoc_args['story-photos-dir-path'];

		$photos                = $this->get_data_from_csv( $story_photos_csv_file_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_IMPORTED_FEATURED_META_KEY );

		$featured_photos = array_values(
			array_filter(
				$photos,
				function( $photo ) use ( $imported_original_ids ) {
					return 'yes' === $photo['feature'] && ! in_array( $photo['photo_id'], $imported_original_ids );
					return 'yes' === $photo['feature'] && ! in_array( $photo['photo_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $featured_photos as $post_index => $photo ) {
			$this->logger->log( self::FEATURED_IMAGES_LOG_FILE, sprintf( 'Importing featured image %d/%d: %d', $post_index + 1, count( $featured_photos ), $photo['photo_id'] ), Logger::LINE );

			$wp_post_id         = $this->get_post_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $photo['story_id'] );
			$attachment_post_id = $this->get_post_by_meta( self::EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY, $photo['photo_id'] );

			if ( ! $wp_post_id ) {
				$this->logger->log( self::FEATURED_IMAGES_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $photo['story_id'] ), Logger::WARNING );
				continue;
			}

			if ( ! $attachment_post_id ) {
				$attachment_post_id = $this->get_attachment_from_media( $wp_post_id, $photo, $story_photos_dir_path );

				if ( ! $attachment_post_id ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not find attachment for photo %s for the post %d', $photo['photo_id'], $wp_post_id ), Logger::WARNING );
					continue;
				}
			}

			if ( $attachment_post_id ) {
				set_post_thumbnail( $wp_post_id, $attachment_post_id );
				$this->logger->log( self::LOG_FILE, sprintf( 'Set featured image for post %d with the ID %d', $wp_post_id, $attachment_post_id ), Logger::SUCCESS );
				update_post_meta( $attachment_post_id, self::EMBARCADERO_IMPORTED_FEATURED_META_KEY, $photo['photo_id'] );

				// Remove the image block from the post content if it contain the featured image as the first content block.
				$blocks = parse_blocks( get_post_field( 'post_content', $wp_post_id ) );

				if ( 'core/image' === $blocks[0]['blockName'] && intval( $attachment_post_id ) === $blocks[0]['attrs']['id'] ) {
					// Remove first block.
					array_shift( $blocks );
					$post_content = trim( serialize_blocks( $blocks ) );
					wp_update_post(
						[
							'ID'           => $wp_post_id,
							'post_content' => $post_content,
						]
					);
				}
			}
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-more-posts-block".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_more_posts_block( $args, $assoc_args ) {
		$story_csv_file_path       = $assoc_args['story-csv-file-path'];
		$story_media_csv_file_path = $assoc_args['story-media-file-path'];

		$posts                 = $this->get_data_from_csv( $story_csv_file_path );
		$media_list            = $this->get_data_from_csv( $story_media_csv_file_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_IMPORTED_MORE_POSTS_META_KEY );

		// Skip already imported posts.
		$posts = array_values(
			array_filter(
				$posts,
				function( $post ) use ( $imported_original_ids ) {
					return ! in_array( $post['story_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Importing post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::LINE );

			$wp_post_id = $this->get_post_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			if ( ! $wp_post_id ) {
				$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $post['story_id'] ), Logger::WARNING );
				continue;
			}

			$wp_post = get_post( $wp_post_id );

			if ( ! str_contains( $post['story_text'], '{more_stories' ) ) {
				continue;
			}

			$more_stories_media = array_values(
				array_filter(
					$media_list,
					function( $media_item ) use ( $post ) {
						return $media_item['story_id'] === $post['story_id'] && $media_item['media_type'] === 'more_stories';
					}
				)
			);

			if ( ! empty( $more_stories_media ) ) {
				$more_posts_blocks = [ $this->gutenberg_block_generator->get_heading( 'More stories' ) ];

				foreach ( $more_stories_media as $more_stories_item ) {
					$more_posts_blocks[] = $this->gutenberg_block_generator->get_paragraph( '<strong><a href="' . $more_stories_item['more_link'] . '">' . $more_stories_item['more_headline'] . '</a></strong><br>' . $more_stories_item['more_blurb'] );
				}

				preg_match( '/(?<shortcode>{(?<type>more_stories)(\s+(?<width>(\d|\w)+)?)?(\s+(?<id>\d+)?)?})/', $wp_post->post_content, $match );

				if ( array_key_exists( 'shortcode', $match ) ) {
					$content = str_replace( $match['shortcode'], serialize_blocks( $more_posts_blocks ), $wp_post->post_content );

					wp_update_post(
						[
							'ID'           => $wp_post_id,
							'post_content' => $content,
						]
					);

					$this->logger->log( self::LOG_FILE, sprintf( 'Updated post %d with the ID %d with more posts block.', $post['story_id'], $wp_post_id ), Logger::SUCCESS );
				}
			} else {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find more_stories %s for the post %d', $post['story_id'], $wp_post_id ), Logger::WARNING );
			}

			update_post_meta( $post['story_id'], self::EMBARCADERO_IMPORTED_MORE_POSTS_META_KEY, $post['story_id'] );
		}
	}

	/**
	 * Get data from CSV file.
	 *
	 * @param string $story_csv_file_path Path to the CSV file containing the stories to import.
	 * @return array Array of data.
	 */
	private function get_data_from_csv( $story_csv_file_path ) {
		$data = [];

		if ( ! file_exists( $story_csv_file_path ) ) {
			$this->logger->log( self::LOG_FILE, 'File does not exist: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_file = fopen( $story_csv_file_path, 'r' );
		if ( false === $csv_file ) {
			$this->logger->log( self::LOG_FILE, 'Could not open file: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_headers = fgetcsv( $csv_file );
		if ( false === $csv_headers ) {
			$this->logger->log( self::LOG_FILE, 'Could not read CSV headers from file: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_headers = array_map( 'trim', $csv_headers );

		while ( ( $csv_row = fgetcsv( $csv_file ) ) !== false ) {
			$csv_row = array_map( 'trim', $csv_row );
			$csv_row = array_combine( $csv_headers, $csv_row );

			$data[] = $csv_row;
		}

		fclose( $csv_file );

		return $data;
	}

	/**
	 * Get imported posts original IDs.
	 *
	 * @param string $meta_key Meta key to search for.
	 *
	 * @return array
	 */
	private function get_posts_meta_values_by_key( $meta_key ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s",
				$meta_key
			)
		);
	}

	/**
	 * Get or create a contributor.
	 *
	 * @param string $full_name Full name of the contributor.
	 * @param string $email_address Email address of the contributor.
	 * @return int|null WP user ID.
	 */
	private function get_or_create_contributor( $full_name, $email_address ) {
		// Check if user exists.
		$wp_user = get_user_by( 'email', $email_address );
		if ( $wp_user ) {
			return $wp_user->ID;
		}

		// Create a WP user with the contributor role.
		$wp_user_id = wp_create_user( $email_address, wp_generate_password(), $email_address );
		if ( is_wp_error( $wp_user_id ) ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Could not create user %s: %s', $full_name, $wp_user_id->get_error_message() ), Logger::ERROR );
		}

		// Set the Contributor role.
		$user = new \WP_User( $wp_user_id );
		$user->set_role( 'contributor' );

		// Set WP User display name.
		wp_update_user(
			[
				'ID'           => $wp_user_id,
				'display_name' => $full_name,
				'first_name'   => current( explode( ' ', $full_name ) ),
				'last_name'    => join( ' ', array_slice( explode( ' ', $full_name ), 1 ) ),
			]
		);

		return $wp_user_id;
	}

	/**
	 * Migrate post content shortcodes.
	 *
	 * @param int    $story_id Story ID.
	 * @param int    $wp_post_id WP post ID.
	 * @param string $story_text Post content.
	 * @param array  $photos Array of photos data.
	 * @param string $story_photos_dir_path Path to the directory containing the stories\'s photos files to import.
	 * @param array  $media Array of media data.
	 * @param array  $carousel_items Array of carousel items data.
	 *
	 * @return string Migrated post content.
	 */
	private function migrate_post_content_shortcodes( $story_id, $wp_post_id, $story_text, $photos, $story_photos_dir_path, $media, $carousel_items ) {
		// Story text contains different shortcodes in the format: {shorcode meta meta ...}.
		$story_text = $this->migrate_media( $wp_post_id, $story_id, $story_text, $media, $photos, $story_photos_dir_path, $carousel_items );
		$story_text = $this->migrate_photos( $wp_post_id, $story_text, $photos, $story_photos_dir_path );
		$story_text = $this->migrate_links( $story_text );
		return $story_text;
	}

	/**
	 * Get or create co-authors.
	 *
	 * @param string $byline Byline.
	 * @return array Array of co-authors.
	 */
	private function get_co_authors_from_bylines( $byline ) {
		// If byline is empty set a default author.
		if ( empty( $byline ) || '-' === $byline ) {
			return [ self::DEFAULT_AUTHOR_NAME ];
		}

		// Split co-authors by ' and '.
		$coauthors                = explode( ' and ', $byline );
		$false_positive_coauthors = [ 'Ph.D', 'M.D.', 'DVM' ];

		foreach ( $coauthors as $coauthor ) {
			// Clean up the byline.
			$coauthor = trim( $coauthor );
			// Remove By, by prefixes.
			$coauthor = preg_replace( '/^By,? /i', '', $coauthor );
			// Split by comma.
			$coauthor_splits = array_map( 'trim', explode( ',', $coauthor ) );
			// If the split result in terms from the false positive list, undo the split.
			$skip_split = false;
			foreach ( $false_positive_coauthors as $false_positive_coauthor ) {
				if ( in_array( $false_positive_coauthor, $coauthor_splits ) ) {
					$skip_split = true;
					break;
				}
			}

			if ( ! $skip_split ) {
				$coauthors = array_merge( $coauthors, $coauthor_splits );
				unset( $coauthors[ array_search( $coauthor, $coauthors ) ] );
			}
		}

		return $coauthors;
	}

	/**
	 * Get or create co-authors.
	 *
	 * @param array $coauthors Array of co-authors.
	 * @return array Array of co-authors IDs.
	 */
	private function get_generate_coauthor_ids( $coauthors ) {
		$coauthor_ids = [];
		foreach ( $coauthors as $coauthor ) {
			// Set as a co-author.
			$guest_author = $this->coauthorsplus_logic->get_guest_author_by_display_name( $coauthor );
			if ( $guest_author ) {
				$coauthor_ids[] = $guest_author->ID;
			} else {
				$coauthor_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $coauthor ] );
				if ( ! is_wp_error( $coauthor_id ) ) {
					$coauthor_ids[] = $coauthor_id;
				} else {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not create co-author %s: %s', $coauthor, $coauthor_id->get_error_message() ), Logger::ERROR );
				}
			}
		}

		return $coauthor_ids;
	}

	/**
	 * Get or create a category.
	 *
	 * @param string $name Category name.
	 * @return int|null Category ID.
	 */
	private function get_or_create_category( $name ) {
		$term = get_term_by( 'name', $name, 'category' );
		if ( $term ) {
			return $term->term_id;
		}

		$term = wp_insert_term( $name, 'category' );
		if ( is_wp_error( $term ) ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Could not create category %s: %s', $name, $term->get_error_message() ), Logger::ERROR );
			return null;
		}

		return $term['term_id'];
	}

	/**
	 * Get the post slug from the SEO link.
	 *
	 * @param string $seo_link SEO link.
	 * @return string Post slug.
	 */
	private function migrate_post_slug( $seo_link ) {
		// get the slug from the format: "2011/03/31/post-slug.
		return substr( $seo_link, strrpos( $seo_link, '/' ) + 1 );
	}

	/**
	 * Migrate photos in content.
	 *
	 * @param int    $wp_post_id WP post ID.
	 * @param string $content Post content.
	 * @param array  $photos Array of photos data.
	 * @param string $story_photos_dir_path Path to the directory containing the stories\'s photos files to import.
	 *
	 * @return string Migrated post content.
	 */
	private function migrate_photos( $wp_post_id, $content, $photos, $story_photos_dir_path ) {
		// Photos can be in the content in the format {photo 40 25877}
		// where 40 is the percentage of the column and 25877 is the photo ID.
		preg_match_all( '/(?<shortcode>{photo (?<width>(\d|\w)+) (?<id>\d+)})/', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$photo_index = array_search( $match['id'], array_column( $photos, 'photo_id' ) );
			if ( false !== $photo_index ) {
				$photo         = $photos[ $photo_index ];
				$attachment_id = $this->get_attachment_from_media( $wp_post_id, $photo, $story_photos_dir_path );

				if ( $attachment_id ) {
					$image_classname  = 'inline-' . $match['width'] . '-photo';
					$photo_block_html = serialize_block( $this->gutenberg_block_generator->get_image( get_post( $attachment_id ), 'full', true, $image_classname ) );

					$content = str_replace( $match['shortcode'], $photo_block_html, $content );
				} else {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not find attachment for photo %s for the post %d', $photo['photo_id'], $wp_post_id ), Logger::WARNING );
				}
			}
		}

		return $content;
	}

	/**
	 * Migrate media in content.
	 *
	 * @param int    $wp_post_id WP post ID.
	 * @param int    $story_id Story ID.
	 * @param string $content Post content.
	 * @param array  $media_list Array of media data.
	 * @param array  $photos Array of photos data.
	 * @param string $story_photos_dir_path Path to the directory containing the stories\'s photos files to import.
	 * @param array  $carousel_items Array of carousel items data.
	 *
	 * @return string Migrated post content.
	 */
	private function migrate_media( $wp_post_id, $story_id, $content, $media_list, $photos, $story_photos_dir_path, $carousel_items ) {
		// Media can be in the content in the format {media_type 40 25877}
		// where media_type can be one of the following: carousel, flour, map, more_stories, pull_quote, timeline, video.
		// 40 is the percentage of the column and 25877 is the media ID.

		preg_match_all( '/(?<shortcode>{(?<type>carousel|flour|map|more_stories|pull_quote|timeline|video)(\s+(?<width>(\d|\w)+)?)?(\s+(?<id>\d+)?)?})/', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			switch ( $match['type'] ) {
				case 'carousel':
					$media_index = array_search( $match['id'], array_column( $media_list, 'media_id' ) );
					if ( false !== $media_index ) {
						$media = $media_list[ $media_index ];

						$media_carousel_items = array_filter(
							$carousel_items,
							function( $carousel_item ) use ( $media ) {
								return $carousel_item['carousel_media_id'] === $media['media_id'];
							}
						);

						// order $media_carousel_items by sort_order column.
						usort(
							$media_carousel_items,
							function( $a, $b ) {
								return intval( $a['sort_order'] ) <=> intval( $b['sort_order'] );
							}
						);

						$carousel_attachments_items = array_values(
							array_filter(
								array_map(
									function( $carousel_item ) use ( $wp_post_id, $photos, $story_photos_dir_path ) {
										$photo_index = array_search( $carousel_item['photo_id'], array_column( $photos, 'photo_id' ) );
										if ( false !== $photo_index ) {
											$photo         = $photos[ $photo_index ];
											$attachment_id = $this->get_attachment_from_media( $wp_post_id, $photo, $story_photos_dir_path );

											if ( $attachment_id ) {
												return $attachment_id;
											} else {
												$this->logger->log( self::LOG_FILE, sprintf( 'Could not find attachment for carousel item %s for the post %d', $carousel_item['carousel_item_id'], $wp_post_id ), Logger::WARNING );
												return null;
											}
										}


									},
									$media_carousel_items
								)
							)
						);

						$media_content = serialize_block( $this->gutenberg_block_generator->get_jetpack_slideshow( $carousel_attachments_items ) );

						if ( ! empty( $media['media_caption'] ) ) {
							$media_content .= serialize_block( $this->gutenberg_block_generator->get_paragraph( $media['media_caption'], '', 'medium-gray', 'small' ) );
						}

						$content = str_replace( $match['shortcode'], $media_content, $content );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find carousel %s for the post %d', $match['id'], $wp_post_id ), Logger::WARNING );
					}
					break;
				case 'flour':
					$media_index = array_search( $match['id'], array_column( $media_list, 'media_id' ) );
					if ( false !== $media_index ) {
						$media = $media_list[ $media_index ];

						$media_content = "<div class='inline-" . $match['width'] . "-photo'>" . $media['media_link'] . '</div>';

						if ( ! empty( $media['media_caption'] ) ) {
							$media_content .= serialize_block( $this->gutenberg_block_generator->get_paragraph( $media['media_caption'], '', 'medium-gray', 'small' ) );
						}

						$content = str_replace( $match['shortcode'], $media_content, $content );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find flour %s for the post %d', $match['id'], $wp_post_id ), Logger::WARNING );
					}

					break;
				case 'map':
					$media_index = array_search( $match['id'], array_column( $media_list, 'media_id' ) );
					if ( false !== $media_index ) {
						$media = $media_list[ $media_index ];

						$media_content = $media['media_link'];

						if ( ! empty( $media['media_caption'] ) ) {
							$media_content .= serialize_block( $this->gutenberg_block_generator->get_paragraph( $media['media_caption'], '', 'medium-gray', 'small' ) );
						}

						$content = str_replace( $match['shortcode'], $media_content, $content );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find map %s for the post %d', $match['id'], $wp_post_id ), Logger::WARNING );
					}
					break;
				case 'timeline':
					$this->logger->log( self::LOG_FILE, sprintf( 'Timeline shortcode found for the post %d', $wp_post_id ), Logger::WARNING );
					break;
				case 'video':
					$media_index = array_search( $match['id'], array_column( $media_list, 'media_id' ) );
					if ( false !== $media_index ) {
						$media         = $media_list[ $media_index ];
						$media_content = '';
						// Get the YouTube video ID from $media['video_link'] if it's a youtube URL.
						if ( strpos( $media['video_link'], 'youtube.com' ) !== false ) {
							preg_match( '/(?<=v=)[^&]+/', $media['video_link'], $matches );
							if ( ! empty( $matches ) ) {
								$video_id      = $matches[0];
								$media_content = $this->generate_youtube_block( $video_id, $wp_post_id );
							}
						} elseif ( strpos( $media['video_link'], 'youtu.be' ) !== false ) {
							$video_id      = substr( $media['video_link'], strrpos( $media['video_link'], '/' ) + 1 );
							$media_content = $this->generate_youtube_block( $video_id, $wp_post_id );
						} else {
							$this->logger->log( self::LOG_FILE, sprintf( 'Video link %s is not a YouTube link for the post %d', $media['video_link'], $wp_post_id ), Logger::WARNING );
						}

						if ( ! empty( $media_content ) ) {
							if ( ! empty( $media['video_caption'] ) ) {
								$media_content .= serialize_block( $this->gutenberg_block_generator->get_paragraph( $media['video_caption'], '', 'medium-gray', 'small' ) );
							}
							$content = str_replace( $match['shortcode'], $media_content, $content );
						}
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find video %s for the post %d', $match['id'], $wp_post_id ), Logger::WARNING );
					}
					break;
					break;
				case 'pull_quote':
					// pull quotes are in the format: {pull_quote  659}.
					$media_index = array_search( intval( $match['width'] ), array_column( $media_list, 'media_id' ) );
					if ( false !== $media_index ) {
						$media                 = $media_list[ $media_index ];
						$citation              = implode( ', ', [ $media['pull_quote_name'], $media['pull_quote_citation'] ] );
						$pull_quote_block_html = serialize_block( $this->gutenberg_block_generator->get_quote( $media['pull_quote_text'], $citation ) );
						$content               = str_replace( $match['shortcode'], $pull_quote_block_html, $content );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find pull quote %s for the post %d', $match['id'], $wp_post_id ), Logger::WARNING );
					}

					break;
			}
		}

		return $content;
	}

	/**
	 * Migrate links in content.
	 *
	 * @param string $story_text Post content.
	 * @return string Migrated post content.
	 */
	private function migrate_links( $story_text ) {
		// Links can be in the content in the format [https://www.xyz.com/2023/01/30/abc Example].
		// We need to convert them to <a href="https://www.xyz.com/2023/01/30/abc">Example</a>.
		return preg_replace( '/\[(?<url>(http|www)[^\s]+) (?<text>[^\]]+)\]/', '<a href="${1}">${2}</a>', $story_text );
	}

	/**
	 * Get attachment from media.
	 *
	 * @param int    $wp_post_id WP post ID.
	 * @param string $media Media data.
	 * @param string $story_photos_dir_path Path to the directory containing the stories\'s photos files to import.
	 * @return int|bool Attachment ID or false if not found.
	 */
	private function get_attachment_from_media( $wp_post_id, $media, $story_photos_dir_path ) {
		$attachment_id = $this->get_post_by_meta( self::EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY, $media['photo_id'] );

		if ( $attachment_id ) {
			return $attachment_id;
		}

		$media_month = strtolower( gmdate( 'F', mktime( 0, 0, 0, $media['photo_month'], 1 ) ) );
		$media_path  = $story_photos_dir_path . '/' . $media['photo_year'] . '/' . $media_month . '/' . $media['photo_day'] . '/' . $media['photo_id'] . '_original.jpg';

		if ( file_exists( $media_path ) ) {
			$attachment_id = $this->attachments->import_external_file( $media_path, null, $media['caption'], null . null, $wp_post_id );
			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not import photo %s for the post %d: %s', $media_path, $wp_post_id, $attachment_id->get_error_message() ), Logger::WARNING );
				return false;
			}

			update_post_meta( $attachment_id, self::EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY, $media['photo_id'] );
			return $attachment_id;
		} else {
			$this->logger->log( self::LOG_FILE, sprintf( 'Could not find photo %s for the post %d', $media_path, $wp_post_id ), Logger::WARNING );
			return false;
		}
	}

	/**
	 * Get post ID by meta.
	 *
	 * @param string $meta_name Meta name.
	 * @param string $meta_value Meta value.
	 * @return int|null
	 */
	private function get_post_by_meta( $meta_name, $meta_value ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
				$meta_name,
				$meta_value
			)
		);
	}

	/**
	 * Get post ID by slug.
	 *
	 * @param string $slug Post slug.
	 * @return int|null
	 */
	private function get_post_by_slug( $slug ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_name = %s",
				$slug
			)
		);
	}

	/**
	 * Generate YouTube block.
	 *
	 * @param string $video_id YouTube video ID.
	 * @param int    $post_id WP post ID.
	 *
	 * @return string YouTube block HTML.
	 */
	private function generate_youtube_block( $video_id, $post_id ) {
		$this->logger->log( self::LOG_FILE, sprintf( 'Generating YouTube block for video %s for the post %d, please resave the post.', $video_id, $post_id ), Logger::SUCCESS );
		return '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=' . $video_id . '","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
		<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
		https://www.youtube.com/watch?v=' . $video_id . '
		</div></figure>
		<!-- /wp:embed -->';
	}
}