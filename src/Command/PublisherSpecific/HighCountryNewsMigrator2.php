<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use Exception;
use HTMLPurifier;
use HTMLPurifier_HTML5Config;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use NewspackCustomContentMigrator\Utils\JsonIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
use simplehtmldom\HtmlDocument;
use WP_CLI;
use WP_Post;
use WP_User;

class HighCountryNewsMigrator2 implements InterfaceCommand {

	/**
	 * Singleton.
	 *
	 * @var self
	 */
	private static $instance;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private CoAuthorPlus $coauthorsplus_logic;

	/**
	 * @var Logger.
	 */
	private Logger $logger;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private GutenbergBlockGenerator $gutenberg_block_generator;

	/**
	 * @var JsonIterator
	 */
	private JsonIterator $json_iterator;

	/**
	 * @var Redirection
	 */
	private Redirection $redirection;

	/**
	 * @var Posts
	 */
	private Posts $posts_logic;

	/**
	 * @var Attachments
	 */
	private Attachments $attachments;

	private array $articles_json_arg = [
		'type'        => 'assoc',
		'name'        => 'articles-json',
		'description' => 'Path to the articles JSON file.',
		'optional'    => false,
	];

	private array $images_json_arg = [
		'type'        => 'assoc',
		'name'        => 'images-json',
		'description' => 'Path to the images JSON file.',
		'optional'    => false,
	];

	private array $issues_json_arg = [
		'type'        => 'assoc',
		'name'        => 'issues-json',
		'description' => 'Path to the issues JSON file.',
		'optional'    => false,
	];

	private array $blobs_path_arg = [
		'type'        => 'assoc',
		'name'        => 'blobs-path',
		'description' => 'Path to the blobs directory.',
		'optional'    => false,
	];

	private array $users_json_arg = [
		'type'        => 'assoc',
		'name'        => 'users-json',
		'description' => 'Path to the users JSON file.',
		'optional'    => false,
	];

	private array $num_items_arg = [
		'type'        => 'assoc',
		'name'        => 'num-items',
		'description' => 'Number of items to process',
		'optional'    => true,
	];

	const MAX_POST_ID_FROM_STAGING = 185173; // SELECT max(ID) FROM wp_posts on staging.

	const PARENT_PAGE_FOR_ISSUES = 180504; // The page that all issues will have as parent.
	const DEFAULT_AUTHOR_ID      = 223746; // User ID of default author.
	const TAG_ID_THE_MAGAZINE    = 7640; // All issues will have this tag to create a neat looking page at /topic/the-magazine.
	const CATEGORY_ID_ISSUES     = 385;

	private DateTimeZone $site_timezone;

	private function __construct() {
		// Do nothing.
	}

	/**
	 * Get Instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {

		if ( null === self::$instance ) {
			self::$instance                            = new self();
			self::$instance->coauthorsplus_logic       = new CoAuthorPlus();
			self::$instance->redirection               = new Redirection();
			self::$instance->logger                    = new Logger();
			self::$instance->gutenberg_block_generator = new GutenbergBlockGenerator();
			self::$instance->attachments               = new Attachments();
			self::$instance->json_iterator             = new JsonIterator();
			self::$instance->site_timezone             = new DateTimeZone( 'America/Denver' );
			self::$instance->posts_logic               = new Posts();
		}

		return self::$instance;
	}

	/**
	 * @throws Exception
	 */
	public function register_commands(): void {
		$this->delete_content_commands();
		$this->set_fixes_on_existing_command();
		$this->set_importer_commands();
		$this->set_fixes_command();
	}

	public function delete_content_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator hcn-delete-caps',
			[ $this, 'cmd_delete_caps' ],
			[
				'shortdesc' => 'Delete ALL existing CAP users.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator hcn-delete-posts',
			[ $this, 'cmd_delete_posts' ],
			[
				'shortdesc' => 'Delete posts of type post.',
				'synopsis'  => [
					BatchLogic::$num_items,
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-delete-issues',
			[ $this, 'cmd_delete_issues' ],
			[
				'shortdesc' => 'Delete issues.',
			]
		);
	}

	public function cmd_delete_issues( array $args, array $assoc_args ): void {
		$post_args = [
			'post_type'   => 'page',
			'post_status' => get_post_stati(),
			'numberposts' => - 1,
			'category'    => self::CATEGORY_ID_ISSUES,
			'exclude'     => self::PARENT_PAGE_FOR_ISSUES,
		];

		// Terms:
		$terms_args = [
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'parent'     => self::CATEGORY_ID_ISSUES,
		];

		foreach ( get_posts( $post_args ) as $post ) {
			WP_CLI::log( sprintf( 'Deleting post with title %s', $post->post_title ) );
			wp_delete_post( $post->ID, true );
		}

		foreach ( get_terms( $terms_args ) as $term ) {
			WP_CLI::log( sprintf( 'Deleting term with slug %s', $term->slug ) );
			wp_delete_term( $term->term_id, 'category' );
		}
	}

	public function cmd_delete_caps( array $args, array $assoc_args ): void {

		// Posts
		$post_args = [
			'post_type'   => 'guest-author',
			'post_status' => get_post_stati(),
			'numberposts' => - 1,
		];

		// Terms:
		$terms_args = [
			'taxonomy'   => 'author',
			'hide_empty' => false,
			'number'     => - 1,
		];

		foreach ( get_posts( $post_args ) as $post ) {
			WP_CLI::log( sprintf( 'Deleting post with title %s', $post->post_title ) );
			wp_delete_post( $post->ID, true );
		}

		foreach ( get_terms( $terms_args ) as $term ) {
			WP_CLI::log( sprintf( 'Deleting term with slug %s', $term->slug ) );
			wp_delete_term( $term->term_id, 'author' );
		}
	}

	public function cmd_delete_posts( array $args, array $assoc_args ): void {
		$num_to_delete = $assoc_args['num-items'] ?? PHP_INT_MAX;
		$counter       = 0;
		foreach ( $this->posts_logic->get_all_posts_ids( 'post', get_post_stati() ) as $post_id ) {
			$counter ++;
			wp_delete_post( $post_id, true );
			WP_CLI::log( sprintf( '%d – Deleted post with id: %d', $counter, $post_id ) );
			if ( $counter > $num_to_delete ) {
				break;
			}
		}
	}

	/**
	 * Commands here need to be run on the already migrated content on staging.
	 *
	 * @throws Exception
	 */
	private function set_fixes_on_existing_command(): void {

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-wp-related-links',
			[ $this, 'fix_wp_related_links' ],
			[
				'shortdesc' => 'Fixes existing related link paths.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-urls',
			[ $this, 'fix_post_urls' ],
			[
				'shortdesc' => 'Fix post names (slugs).',
				'synopsis'  => [
					$this->articles_json_arg,
					$this->num_items_arg,
					...BatchLogic::get_batch_args(),
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-redirects',
			array( $this, 'fix_redirects' ),
			array(
				'shortdesc' => 'Fix existing redirects.',
				'synopsis'  => array(
					[
						$this->articles_json_arg,
						...BatchLogic::get_batch_args(),
					],
				),
			)
		);
	}

	/**
	 * Import new data.
	 *
	 * @throws Exception
	 */
	private function set_importer_commands(): void {

		/*
		 * To generate the creators file:
		 * cat HCNNewsArticle.json| jq -r '. [] | .creators | join(",") ' | sort -u > creators.txt
		 * cat HCNImage.json| jq -r '. [] | .creators | join(",") ' | sort -u  >> creators.txt
		 * cat creators.txt| sort -u > sorted-creators.txt
		 * cat sorted-creators.txt|  tr ',' '\n' > final-creators.txt
		 */
		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-users-from-json',
			[ $this, 'import_users_from_json' ],
			[
				'shortdesc' => 'Migrate users from JSON data.',
				'synopsis'  => [
					$this->users_json_arg,
					...BatchLogic::get_batch_args(),
					[
						'type'        => 'assoc',
						'name'        => 'creators-file',
						'description' => 'Creators file – if passed only users in this file get created',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-images-from-json',
			[ $this, 'migrate_images_from_json' ],
			[
				'shortdesc' => 'Migrate images from JSON data.',
				'synopsis'  => [
					$this->images_json_arg,
					$this->blobs_path_arg,
					...BatchLogic::get_batch_args(),
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-import-issues-as-pages',
			[ $this, 'import_issues_as_pages' ],
			[
				'synopsis'  => [
					$this->issues_json_arg,
					$this->articles_json_arg,
					$this->blobs_path_arg,
					...BatchLogic::get_batch_args(),
				],
				'shortdesc' => 'Import issues as pages and clean up issue categories.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-articles-from-json',
			[ $this, 'migrate_articles_from_json' ],
			[
				'shortdesc' => 'Migrate articles from JSON data.',
				'synopsis'  => [
					$this->articles_json_arg,
					...BatchLogic::get_batch_args(),
				],
			]
		);
	}

	/**
	 * These need to be run after a new import.
	 *
	 * @throws Exception
	 */
	private function set_fixes_command(): void {

		// TODO. This one might not be necessary.
		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-related-links-from-json',
			[ $this, 'fix_related_links_from_json' ],
			[
				'shortdesc' => 'Massages related links coming from the JSON',
				'synopsis'  => [
					$this->articles_json_arg,
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-referenced-articles-from-json',
			[ $this, 'fix_referenced_articles_from_json' ],
			[
				'shortdesc' => 'Adds a block to articles with referenced links.',
				'synopsis'  => [
					$this->articles_json_arg,
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-wp-related-links-in-posts',
			[ $this, 'fix_wp_related_links_in_posts' ],
			[
				'shortdesc' => 'Makes related links blocks.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'Specific post id to process',
						'optional'    => true,
					],
					BatchLogic::$num_items,
				],
			]
		);
	}

	/**
	 * Callback for the command hcn-generate-redirects.
	 *
	 * @throws Exception
	 */
	public function fix_redirects( array $args, array $assoc_args ): void {
		$log_file           = __FUNCTION__ . '.log';
		$home_url           = home_url();
		$articles_json_file = $assoc_args[ $this->articles_json_arg['name'] ];

		$categories = get_categories(
			[
				'fields' => 'slugs',
			]
		);

		global $wpdb;


		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $articles_json_file, $assoc_args );
		$counter    = 0;
		foreach ( $this->json_iterator->batched_items( $articles_json_file, $batch_args['start'], $batch_args['end'] ) as $article ) {
			WP_CLI::log( sprintf( 'Processing article (%d of %d)', $counter ++, $batch_args['total'] ) );

			if ( empty( $article->aliases ) ) {
				continue;
			}

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE ID <= %d AND meta_key = 'plone_article_UID' and meta_value = %s",
					self::MAX_POST_ID_FROM_STAGING,
					$article->UID
				)
			);
			if ( ! $post_id ) {
				$this->logger->log( $log_file, sprintf( 'Post with plone_article_UID %s not found', $article->UID ), Logger::WARNING );
				continue;
			}

			foreach ( $article->aliases as $alias ) {
				$existing_redirects = $this->redirection->get_redirects_by_exact_from_url( $alias );
				if ( ! empty( $existing_redirects ) ) {
					foreach ( $existing_redirects as $existing_redirect ) {
						$existing_redirect->delete();
					}
				}
				// We will use a regex redirect for the /hcn/hcn/ prefix, so remove that.
				$no_prefix          = str_replace( '/hcn/hcn', '', $alias );
				$existing_redirects = $this->redirection->get_redirects_by_exact_from_url( $no_prefix );
				if ( ! empty( $existing_redirects ) ) {
					foreach ( $existing_redirects as $existing_redirect ) {
						$existing_redirect->delete();
					}
				}

				$alias_parts = explode( '/', trim( $no_prefix, '/' ) );
				// If the alias is the category we would already have that because of " wp option get permalink_structure %category%/%postname%/".
				// If there are more than 2 parts, e.g. /issues/123/post-name, then it's OK to create the alias.
				if ( ! in_array( $alias_parts[0], $categories, true ) || count( $alias_parts ) > 2 ) {
					$this->redirection->create_redirection_rule(
						'Plone ID ' . $article->UID,
						$no_prefix,
						"/?p={$post_id}",
					);
					$this->logger->log( $log_file, sprintf( 'Created redirect on post ID %d for %s', $post_id, $home_url . $no_prefix ), Logger::SUCCESS );
				}
			}
		}
	}

	/**
	 * Fix existing slugs and urls.
	 *
	 * @param array $args Params.
	 * @param array $assoc_args Params.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function fix_post_urls( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";
		$articles_json_file   = $assoc_args[ $this->articles_json_arg['name'] ];

		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $articles_json_file, $assoc_args );
		$counter    = 0;
		foreach ( $this->json_iterator->batched_items( $articles_json_file, $batch_args['start'], $batch_args['end'] ) as $article ) {
			WP_CLI::log( sprintf( 'Processing article (%d of %d)', $counter ++, $batch_args['total'] ) );

			$post_id = $this->get_post_id_from_uid( $article->UID );
			if ( ! empty( $post_id ) && MigrationMeta::get( $post_id, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post_id ), $command_meta_version ) );
				continue;
			}

			if ( $post_id > self::MAX_POST_ID_FROM_STAGING ) {
				$this->logger->log( sprintf( 'Post ID %d is greater MAX ID (%d), skipping', $post_id, self::MAX_POST_ID_FROM_STAGING ), Logger::WARNING );
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				$this->logger->log( sprintf( "Didn't find a post for: %s", $article->{'@id'} ), Logger::WARNING );
				continue;
			}
			if ( $post->post_status !== 'publish' ) {
				MigrationMeta::update( $post_id, $command_meta_key, 'post', $command_meta_version );
				continue;
			}

			$current_path = trim( parse_url( get_permalink( $post->ID ), PHP_URL_PATH ), '/' );

			$original_path = trim( parse_url( $article->{'@id'}, PHP_URL_PATH ), '/' );
			if ( 0 !== substr_count( $original_path, '/' ) ) {
				$this->set_categories_on_post_from_path( $post, $original_path );
			}

			$correct_post_name = $this->get_post_name( $article->{'@id'} );
			if ( $correct_post_name !== $post->post_name ) {
				wp_update_post(
					[
						'ID'        => $post_id,
						'post_name' => $correct_post_name,
					]
				);
			}

			MigrationMeta::update( $post_id, $command_meta_key, 'post', $command_meta_version );

			$original_path  = trim( parse_url( $article->{'@id'}, PHP_URL_PATH ), '/' );
			$post_permalink = get_permalink( $post_id );
			$wp_path        = trim( parse_url( $post_permalink, PHP_URL_PATH ), '/' );
			if ( $wp_path !== mb_strtolower( $original_path ) ) {
				$this->logger->log(
					$log_file,
					sprintf(
						"Changed post path from:\n %s \n %s\n on %s ",
						$current_path,
						$wp_path,
						"/?p={$post_id}"
					),
					Logger::SUCCESS
				);
			} else {
				$this->logger->log( $log_file, sprintf( 'Postname was fine on: %s, not updating it', $post_permalink ) );
			}
		}
	}

	public function fix_related_links_from_json( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";
		$articles_json_file   = $assoc_args[ $this->articles_json_arg['name'] ];


		$article_url_and_uid = [];
		foreach ( json_decode( file_get_contents( $articles_json_file ), true ) as $article ) {
			$article_url_and_uid[ parse_url( $article['@id'], PHP_URL_PATH ) ] = $article['UID'];
		}

		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_content FROM $wpdb->posts WHERE post_type = 'post' AND post_content LIKE '%[RELATED:%'"
		);

		foreach ( $posts as $post ) {
			if ( ! empty( $post_id ) && MigrationMeta::get( $post_id, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post_id ), $command_meta_version ) );
				continue;
			}

			preg_match_all( '/\[RELATED:(.*?)]/', $post->post_content, $matches, PREG_SET_ORDER );

			$update       = false;
			$post_content = $post->post_content;
			foreach ( $matches as $match ) {
				$path = parse_url( $match[1], PHP_URL_PATH );
				if ( empty( $article_url_and_uid[ $path ] ) ) {
					$this->logger->log( $log_file, sprintf( 'Could not find UID for %s', $match[1] ), Logger::WARNING );
					continue;
				}

				$linked_post_id = $this->get_post_id_from_uid( $article_url_and_uid[ $path ] );
				if ( ! $linked_post_id ) {
					$this->logger->log( $log_file, sprintf( 'Could not find post from UID for %s', $match[1] ), Logger::WARNING );
					continue;
				}


				$update      = true;
				$replacement = $this->get_related_link_markup( $linked_post_id );

				$post_content = str_replace( $match[0], $replacement, $post_content );

			}
			if ( $update ) {
				$result = wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $post_content,
					]
				);

				if ( $result ) {
					$this->logger->log( $log_file, sprintf( 'Updated wp links in "RELATED" on %s', get_permalink( $post->ID ) ), Logger::SUCCESS );
				}
			}
			MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
		}
	}

	public function fix_referenced_articles_from_json( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";

		$file_path  = $assoc_args[ $this->articles_json_arg['name'] ];
		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $file_path, $assoc_args );

		$row_number = 0;
		foreach ( $this->json_iterator->batched_items( $file_path, $batch_args['start'], $batch_args['end'] ) as $article ) {
			$row_number ++;
			WP_CLI::log( sprintf( 'Processing row %d of %d: %s', $row_number, $batch_args['total'], $article->{'@id'} ) );
			$post_id = $this->get_post_id_from_uid( $article->UID );
			if ( ! empty( $post_id ) && MigrationMeta::get( $post_id, $command_meta_key, 'post' ) >= $command_meta_version ) {
				$this->logger->log( $log_file, sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post_id ), $command_meta_version ) );
				continue;
			}
			if ( empty( $article->references->relatesTo ) ) {
				continue;
			}
			$related_links = [];
			foreach ( $article->references->relatesTo as $uid ) {
				$related_post_id = $this->get_post_id_from_uid( $uid );
				if ( $related_post_id ) {
					$related_links[] = [
						'title'     => get_the_title( $related_post_id ),
						'permalink' => "/?p={$post_id}"
					];
				}
			}
			if ( empty( $related_post_id ) ) {
				continue;
			}
			$post    = get_post( $post_id );
			$content = $post->post_content;
			$content .= serialize_block(
				$this->gutenberg_block_generator->get_paragraph( 'Read More:' )
			);

			$content .= serialize_block(
				$this->gutenberg_block_generator->get_list(
					array_map(
						fn( $related_post ) => '<a href="' . $related_post['permalink'] . '">' . $related_post['title'] . '</a>',
						$related_links
					)
				)
			);
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $content,
				]
			);
			$this->logger->log( $log_file, sprintf( 'Added related links block to %s', get_permalink( $post ) ), Logger::SUCCESS );
			MigrationMeta::update( $post_id, $command_meta_key, 'post', $command_meta_version );
		}

		$end = ( PHP_INT_MAX === $batch_args['end'] ) ? 'end' : $batch_args['end'];
		$this->logger->log( $log_file, sprintf( 'Finished processing batch from %d to %s', $batch_args['start'], $end ) );
	}

	public function fix_wp_related_links_in_posts( array $args, array $assoc_args ): void {
		$log_file = __FUNCTION__ . 'log';

		$num_items = $assoc_args['num-items'] ?? PHP_INT_MAX;
		$post_ids  = $assoc_args['post-id'] ?? false;
		if ( ! $post_ids ) {
			global $wpdb;
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_content LIKE '%[RELATED:%' ORDER BY ID DESC LIMIT %d",
					[ $num_items ]
				)
			);
		}
		foreach ( $post_ids as $post_id ) {
			$post   = get_post( $post_id );
			$result = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $this->replace_related_placeholders_with_homepage_blocks( $post->post_content ),
				]
			);
			if ( ! is_wp_error( $result ) ) {
				$this->logger->log( $log_file, sprintf( 'Updated related links in  %s', get_permalink( $post_id ) ), Logger::SUCCESS );
			} else {
				$this->logger->log( $log_file, sprintf( 'Failed to update related links in %s', get_permalink( $post_id ) ), Logger::ERROR );
			}
		}
	}

	/**
	 * This one fixes the related links that were already changed to wp permalinks with paths that were not working.
	 */
	public function fix_wp_related_links( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";

		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE ID <= %d AND post_type = 'post' AND post_content LIKE '%<strong>RELATED:%'",
				self::MAX_POST_ID_FROM_STAGING
			)
		);

		$total_posts = count( $post_ids );
		$counter     = 0;

		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( 'Processing %s of %s with post id: %d', $counter ++, $total_posts, $post_id ) );

			if ( ! empty( $post_id ) && MigrationMeta::get( $post_id, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post_id ), $command_meta_version ) );
				continue;
			}

			$post         = get_post( $post_id );
			$update       = false;
			$post_content = $post->post_content;
			preg_match_all( '@<p><strong>RELATED:</strong>.*href=[\'"]([^ ]*)[\'"].*</p>@', $post_content, $matches, PREG_SET_ORDER );

			if ( empty( $matches ) ) {
				$this->logger->log( $log_file, sprintf( 'Post seems to have wrong format related link %s', get_permalink( $post_id ) ), Logger::ERROR );
			}

			foreach ( $matches as $match ) {
				$linked_url_post_name = trim( basename( $match[1] ), '/' );
				$post_linked_to       = get_page_by_path( $linked_url_post_name, OBJECT, 'post' );
				if ( ! $post_linked_to ) {
					$this->logger->log( $log_file, sprintf( 'Could not find post for %s', $match[1] ), Logger::WARNING );
					continue;
				}

				$update      = true;
				$replacement = $this->get_related_link_markup( $post_linked_to->ID );

				$post_content = str_replace( $match[0], $replacement, $post_content );
			}

			if ( $update ) {
				$result = wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $post_content,
					]
				);

				if ( $result ) {
					$this->logger->log( $log_file, sprintf( 'Updated wp links in "RELATED" on %s', get_permalink( $post_id ) ), Logger::SUCCESS );
				}
			}

			MigrationMeta::update( $post_id, $command_meta_key, 'post', $command_meta_version );
		}
	}

	/**
	 * Import users
	 */
	public function import_users_from_json( array $args, array $assoc_args ): void {
		$file_path  = $assoc_args[ $this->users_json_arg['name'] ];
		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $file_path, $assoc_args );

		$creators = [];
		if ( file_exists( $assoc_args['creators-file'] ?? '' ) ) {
			$creators = array_map( 'trim', file( $assoc_args['creators-file'] ) );
		}

		$row_number = 0;
		foreach ( $this->json_iterator->batched_items( $file_path, $batch_args['start'], $batch_args['end'] ) as $row ) {
			$row_number ++;
			WP_CLI::log( sprintf( 'Processing row %d of %d: %s', $row_number, $batch_args['total'], $row->username ) );

			if ( empty( $row->email ) ) {
				continue; // Nope. No email, no user.
			}
			if ( ! empty( $creators ) && ! in_array( $row->username, $creators ) ) {
				continue;
			}

			$date_created = new DateTime( 'now', $this->site_timezone );

			if ( ! empty( $row->date_created ) ) {
				$date_created = DateTime::createFromFormat( 'm-d-Y_H:i', $row->date_created, $this->site_timezone );
			}

			$nicename = $row->fullname ?? ( $row->first_name ?? '' . ' ' . $row->last_name ?? '' );

			$result = wp_insert_user(
				[
					'user_login'      => $row->username,
					'user_pass'       => wp_generate_password(),
					'user_email'      => $row->email,
					'display_name'    => $row->fullname,
					'first_name'      => $row->first_name,
					'last_name'       => $row->last_name,
					'user_registered' => $date_created->format( 'Y-m-d H:i:s' ),
					'role'            => 'subscriber', // Set this role on all users. We change it to author later if they have content.
					'user_nicename'   => $nicename,
				]
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::log( $result->get_error_message() );
			} else {
				WP_CLI::success( "User $row->email created." );
			}
		}
	}


	/**
	 * Import issues into pages and update issue categories.
	 *
	 * Note that this command can be run over and over. Will only process issues not yet processed. It
	 * can also be run again from scratch with no duplicates – just update the $command_meta_version number.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return void
	 * @throws Exception
	 */
	public function import_issues_as_pages( array $args, array $assoc_args ): void {

		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_{$command_meta_version}.log";
		$articles_json        = $assoc_args[ $this->articles_json_arg['name'] ];
		$issues_json          = $assoc_args[ $this->issues_json_arg['name'] ];
		$blobs_folder         = trailingslashit( realpath( $assoc_args[ $this->blobs_path_arg['name'] ] ) );

		$pdfurls_array = $this->get_pdf_urls_from_json( $articles_json );
		if ( empty( $pdfurls_array ) ) {
			WP_CLI::error( sprintf( 'Could not find any PDF urls in %s', $articles_json ) );
		}

		global $wpdb;

		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $issues_json, $assoc_args );
		$row_number = 0;
		foreach ( $this->json_iterator->batched_items( $issues_json, $batch_args['start'], $batch_args['end'] ) as $issue ) {
			WP_CLI::log( sprintf( 'Processing issue (%d of %d): %s', ++ $row_number, $batch_args['total'], $issue->{'@id'} ) );
			$alias = false;

			$slug = substr( $issue->{'@id'}, strrpos( $issue->{'@id'}, '/' ) + 1 );
			if ( preg_match( '/^[0-9]+$/', $slug ) ) {
				$alias = "/issues/$slug";
				$slug  = 'issue-' . $slug;
			}

			$post_date  = new DateTime( ( $issue->effective ?? $issue->created ), $this->site_timezone );
			$issue_name = $post_date->format( 'F j, Y' ) . ': ' . $issue->title;

			$cat = get_category_by_slug( $slug );
			if ( ! $cat ) {
				$id = wp_insert_category(
					[
						'cat_name'          => $slug,
						'category_nicename' => $issue_name,
						'category_parent'   => self::CATEGORY_ID_ISSUES,
					]
				);

				$cat = get_term( $id, 'category' );
			}
			if ( ! $cat instanceof \WP_Term ) {
				$this->logger->log( $log_file, sprintf( 'Could not find or create category for %s', $issue->{'@id'} ), Logger::ERROR );
				continue;
			}
			update_term_meta( $cat->term_id, 'plone_issue_UID', $issue->id );

			if ( MigrationMeta::get( $cat->term_id, $command_meta_key, 'term' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_term_link( $cat->term_id ), $command_meta_version ) );
				continue;
			}

			$post_date_formatted = $post_date->format( 'Y-m-d H:i:s' );
			$page_data           = [
				'post_title'    => $issue_name,
				'post_name'     => $slug,
				'post_status'   => 'publish',
				'post_author'   => self::DEFAULT_AUTHOR_ID, // We don't bother finding an author – it's not displayed on the current live site either.
				'post_type'     => 'page',
				'post_parent'   => self::PARENT_PAGE_FOR_ISSUES,
				'post_category' => [ $cat->term_id, self::CATEGORY_ID_ISSUES ],
				'post_date'     => $post_date_formatted,
				'meta_input'    => [
					'plone_issue_page_UID' => $issue->UID,
				],
			];

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_issue_page_UID' AND meta_value = %s",
					$issue->UID,
				)
			);
			if ( ! $post_id ) {
				$post_id = wp_insert_post( $page_data );
				$this->logger->log( $log_file, sprintf( 'Created page for issue %s', $issue->{'@id'} ), Logger::SUCCESS );
			}

			$pdf_id = $this->get_issue_pdf_attachment_id( $post_id, $issue, $pdfurls_array );
			if ( ! $pdf_id ) {
				$this->logger->log( $log_file, sprintf( 'Could not find a PDF for %s', $issue->{'@id'} ), Logger::WARNING );
			}

			$image_id = 0;
			if ( ! empty( $issue->image ) ) {
				$blob_file_path      = $blobs_folder . $issue->image->blob_path;
				$image_attachment_id = $this->attachments->import_attachment_for_post(
					$post_id,
					$blob_file_path,
					'Magazine cover: ' . $issue_name,
					[],
					$issue->image->filename
				);

				if ( ! is_wp_error( $image_attachment_id ) ) {
					$image_id                                                    = $image_attachment_id;
					$page_data['meta_input']['_thumbnail_id']                    = $image_id;
					$page_data['meta_input']['newspack_featured_image_position'] = 'hidden';
				} else {
					$this->logger->log( $log_file, sprintf( 'Could not find an image for %s', $issue->{'@id'} ), Logger::WARNING );
				}
			}

			$page_data['ID']           = $post_id;
			$page_data['post_content'] = $this->get_issue_page_content_for_category( $cat->term_id, $issue->description ?? '', $image_id, $pdf_id );
			$page_data['post_excerpt'] = $issue->description ?? '';

			wp_update_post( $page_data );
			wp_set_post_tags( $post_id, [ self::TAG_ID_THE_MAGAZINE ], true );
			update_post_meta( $page_data['ID'], 'plone_issue_page_UID', $issue->UID );
			$this->logger->log( $log_file, sprintf( 'Updated issue page: %s', get_permalink( $page_data['ID'] ) ), Logger::SUCCESS );

			if ( $alias ) {
				$this->redirection->create_redirection_rule(
					'Pagename redirect: ' . $alias,
					$alias,
					"/?p={$post_id}"
				);
				$this->logger->log( $log_file, sprintf( 'Added alias for numeric issue page slug: %s', home_url( $alias ) ), Logger::SUCCESS );
			}

			wp_update_term(
				$cat->term_id,
				'category',
				[
					'name'        => $issue_name,
					'slug'        => $slug,
					'description' => '', // Remove the HTML if it was already added earlier on issues.
				]
			);
			update_term_meta( $cat->term_id, 'plone_issue_UID', $issue->id );
			MigrationMeta::update( $cat->term_id, $command_meta_key, 'term', $command_meta_version );
			$this->logger->log( $log_file, sprintf( 'Updated issue category: %s', get_term_link( $cat->term_id ) ), Logger::SUCCESS );
		}

	}

	/**
	 * Migrate new images and update the old ones if they need to be.
	 *
	 * @throws Exception
	 */
	public function migrate_images_from_json( array $args, array $assoc_args ): void {
		$log_file   = __FUNCTION__ . '.log';
		$file_path  = $assoc_args[ $this->images_json_arg['name'] ];
		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $file_path, $assoc_args );
		$blobs_path = untrailingslashit( $assoc_args[ $this->blobs_path_arg['name'] ] );

		$media_lib_search_url = home_url() . '/wp-admin/upload.php?search=%s';
		$row_number           = 0;
		foreach ( $this->json_iterator->batched_items( $file_path, $batch_args['start'], $batch_args['end'] ) as $row ) {
			WP_CLI::log( sprintf( 'Processing row %d of %d: %s', $row_number ++, $batch_args['total'], $row->{'@id'} ) );

			if ( empty( $row->image->filename ) ) {
				// TODO. There are some "legacyPath" images. They seem to point at empty urls, but let's get back to this.
				continue;
			}
			$has_gallery = ! empty( $row->gallery ) && 'inline' !== $row->gallery; // TODO. Figure out what an inline gallery is.
			$tree_path   = trim( parse_url( $row->{'@id'}, PHP_URL_PATH ), '/' );
			$existing_id = $this->get_attachment_id_by_uid( $row->UID );
			if ( $existing_id ) {
				$this->logger->log( $log_file, sprintf( 'Image already imported to %s', get_permalink( $existing_id ) ), Logger::WARNING );
				// Gallery.
				if ( $has_gallery ) {
					update_post_meta( $existing_id, 'plone_gallery_id', $row->gallery );
					$this->logger->log( $log_file, sprintf( 'Updated gallery id on %s', get_permalink( $existing_id ) ), Logger::SUCCESS );
				}
				// Tree path.
				update_post_meta( $existing_id, 'plone_tree_path', $tree_path );
				// Caption.
				if ( ! empty( $row->description ) ) {
					wp_update_post(
						[
							'ID'           => $existing_id,
							'post_excerpt' => $row->description,
						]
					);
					$this->logger->log( $log_file, sprintf( 'Updated caption on %s to: %s', get_permalink( $existing_id ), $row->description ), Logger::SUCCESS );
				}
				// Credit.
				if ( ! empty( $row->credit ) ) {
					update_post_meta( $existing_id, '_media_credit', $row->credit );
					$this->logger->log( $log_file, sprintf( 'Updated credit on %s to: %s', get_permalink( $existing_id ), $row->credit ), Logger::SUCCESS );
				}
				continue;
			}

			$post_author = self::DEFAULT_AUTHOR_ID;

			if ( ! empty( $row->creators[0] ) ) {
				$user = get_user_by( 'login', $row->creators[0] );

				if ( $user ) {
					$this->upgrade_user_to_author( $user->ID );
					$post_author = $user->ID;
				}
			}

			$created_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row->created, $this->site_timezone );
			$updated_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row->modified, $this->site_timezone );

			$img_post_data = [
				'post_author'   => $post_author,
				'post_date'     => $created_at->format( 'Y-m-d H:i:s' ),
				'post_modified' => $updated_at->format( 'Y-m-d H:i:s' ),
				'meta_input'    => [
					'plone_image_UID'   => $row->UID,
					'_media_credit'     => $row->credit ?? '',
					'_media_credit_url' => $row->creditUrl ?? '',
					'plone_tree_path'   => $tree_path,
				],
			];
			if ( $has_gallery ) {
				$img_post_data['meta_input']['plone_gallery_id'] = $row->gallery;
			}

			$attachment_id = $this->attachments->import_external_file(
				$blobs_path . '/' . $row->image->blob_path,
				$row->image->filename,
				$row->description ?? '',
				$row->description ?? '',
				$row->description ?? '',
				0,
				$img_post_data,
				$row->image->filename,
			);

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( $log_file, $attachment_id->get_error_message(), Logger::ERROR );
			} else {
				$media_lib_url = sprintf(
					$media_lib_search_url,
					$row->image->filename
				);
				$this->logger->log(
					$log_file,
					sprintf( 'Image imported to %s (%s)', $media_lib_url, $row->{'@id'} ),
					Logger::SUCCESS
				);
			}
		}
	}

	/**
	 * Migrate articles from JSON.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function migrate_articles_from_json( array $args, array $assoc_args ): void {
		$log_file   = __FUNCTION__ . '.log';
		$file_path  = $assoc_args[ $this->articles_json_arg['name'] ];
		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $file_path, $assoc_args );

		$row_number = 0;
		foreach ( $this->json_iterator->batched_items( $file_path, $batch_args['start'], $batch_args['end'] ) as $row ) {
			WP_CLI::log( sprintf( 'Article %d of %d: %s', $row_number ++, $batch_args['total'], $row->{'@id'} ) );
			if ( empty( $row->text ) ) {
				$this->logger->log( $log_file, sprintf( 'Article %s has no text. Skipping', $row->{'@id'} ), Logger::WARNING );
			}

			if ( 'download-entire-issue' === $row->id ) {
				$this->logger->log( $log_file, sprintf( 'Article is a download-entire-issue. Skipping: %s', $row->{'@id'} ) );
				continue;
			}

			$tree_path   = trim( parse_url( $row->{'@id'}, PHP_URL_PATH ), '/' );
			$existing_id = $this->get_post_id_from_uid( $row->UID );
			if ( $existing_id ) {
				$this->logger->log( $log_file, sprintf( 'Article already imported. Skipping: %s', $row->{'@id'} ) );
				update_post_meta( $existing_id, 'plone_tree_path', $tree_path );
				continue;
			}

			$post_date_string     = $row->effective ?? $row->created;
			$post_modified_string = $row->modified ?? $post_date_string;
			$post_date            = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_date_string, $this->site_timezone );
			$post_modified        = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_modified_string, $this->site_timezone );

			$post_data = [
				'post_title'    => $row->title,
				'post_status'   => ( 'public' === $row->review_state ) ? 'publish' : 'draft',
				'post_date'     => $post_date->format( 'Y-m-d H:i:s' ),
				'post_modified' => $post_modified->format( 'Y-m-d H:i:s' ),
				'post_author'   => self::DEFAULT_AUTHOR_ID,
				'post_excerpt'  => $row->subheadline ?? '',
				'meta_input'    => [
					'plone_article_UID'      => $row->UID,
					'newspack_post_subtitle' => $row->subheadline ?? '',
					'plone_tree_path'        => $tree_path,
				],
			];

			$post_name = $this->get_post_name( $row->{'@id'} );
			// Some articles have a numeric post name. WP does not like that, so only add it if
			// not numeric. We'll have to rely on a redirect for those.
			if ( ! is_numeric( $post_name ) ) {
				$post_data['post_name'] = $post_name;
			}

			if ( ! empty( $row->creators[0] ) ) {
				$author_by_login = get_user_by( 'login', $row->creators[0] );

				if ( $author_by_login instanceof WP_User ) {
					$post_data['post_author'] = $author_by_login->ID;
					$this->upgrade_user_to_author( $author_by_login->ID );
				}
			}

			$text      = $this->cleanup_text( $row->text ?? '' );
			$replacers = [];
			if ( str_contains( $text, 'twitter-tweet' ) ) {
				$replacers[] = fn( $html_doc ) => $this->replace_twitter_embeds( $html_doc );
			}
			if ( str_contains( $text, 'resolveuid/' ) ) {
				$replacers[] = fn( $html_doc ) => $this->replace_img_tags( $html_doc );
			}
			if ( str_contains( $text, '<video' ) ) {
				$replacers[] = fn( $html_doc ) => $this->replace_videos( $html_doc );
			}
			if ( ! empty( $replacers ) ) {
				$html_doc = new HtmlDocument( $text );
				foreach ( $replacers as $replacer ) {
					$replacer( $html_doc );
				}
				$text = $html_doc->save();
			}

			$article_layout          = $row->layout ?? '';
			$featured_image_position = 'fullwidth_article_view' === $article_layout ? 'above' : 'hidden';

			// Featured image.
			if ( ! empty( $row->image ) ) {
				$filename  = $row->image->filename;
				$path_info = pathinfo( $filename );
				if ( str_ends_with( $path_info['filename'], '-thumb' ) ) {
					$filename_without_extension = str_replace( '-thumb', '', $path_info['filename'] );
					$filename                   = $filename_without_extension . '.' . $path_info['extension'];
				}
				$attachment_id = $this->attachments->get_attachment_by_filename( $filename );

				if ( $attachment_id ) {
					$post_data['meta_input']['_thumbnail_id'] = $attachment_id;
				} else {
					$attachment_id = $this->grab_featured_image( $row->{'@id'} );
					if ( $attachment_id ) {
						$post_data['meta_input']['_thumbnail_id'] = $attachment_id;
					}
				}
				$post_data['meta_input']['newspack_featured_image_position'] = $featured_image_position;
			}

			$gallery = '';
			if ( $row->gallery_enabled ) {
				$gallery_images = $this->get_attachment_ids_by_tree_path( $tree_path );
				$gallery        = serialize_block( $this->gutenberg_block_generator->get_jetpack_slideshow( $gallery_images ) );
			}

			$intro                     = $row->intro ?? '';
			$post_data['post_content'] = wp_kses_post( $intro . $gallery . $text );

			$created_post_id = wp_insert_post( $post_data );
			if ( is_wp_error( $created_post_id ) ) {
				$this->logger->log( $log_file, sprintf( "Failed creating post: %s \n\t%s ", $row->{'@id'}, $created_post_id->get_error_message() ), Logger::ERROR );
				continue;
			}

			// Now we have the ID of the created post, do a few more things:
			$co_authors = array_map(
				fn( $author ) => $this->coauthorsplus_logic->get_guest_author_by_id( $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $author ] ) ),
				$this->parse_author_string( $row->author ?? '' )
			);
			$this->coauthorsplus_logic->assign_authors_to_post( $co_authors, $created_post_id );

			if ( ! empty( $row->subjects ) ) {
				wp_set_object_terms( $created_post_id, $row->subjects, 'post_tag' );
				$primary_tag = get_term_by( 'name', $row->subjects[0], 'post_tag' );
				update_post_meta( $created_post_id, '_yoast_wpseo_primary_post_tag', $primary_tag->term_id );
			}
			$this->set_categories_on_post_from_path( get_post( $created_post_id ), $tree_path );
			$this->add_post_redirects( get_post( $created_post_id ), $row, $tree_path );

			$this->logger->log( $log_file, sprintf( 'Created post: %s', get_permalink( $created_post_id ) ), Logger::SUCCESS );
		}

	}

	private function add_post_redirects( WP_Post $post, object $article, string $tree_path ): void {

		$wp_path = trim( parse_url( get_permalink( $post ), PHP_URL_PATH ), '/' );
		if ( mb_strtolower( $wp_path ) !== mb_strtolower( $tree_path ) && ! $this->redirection->redirect_from_exists( '/' . $tree_path ) ) {
			$this->redirection->create_redirection_rule(
				'Postname redirect: ' . $tree_path,
				$tree_path,
				"/?p={$post->ID}"
			);
		}

		if ( empty( $article->aliases ) ) {
			return;
		}

		foreach ( $article->aliases as $alias ) {
			// We will use a regex redirect for the /hcn/hcn/ prefix, so remove that.
			$no_prefix = str_replace( '/hcn/hcn', '', $alias );

			// If we have existing ones, get rid of them.
			$existing_redirects = $this->redirection->get_redirects_by_exact_from_url( $no_prefix );
			if ( ! empty( $existing_redirects ) ) {
				foreach ( $existing_redirects as $existing_redirect ) {
					$existing_redirect->delete();
				}
			}

			$this->redirection->create_redirection_rule(
				'Plone ID ' . $article->UID,
				$no_prefix,
				"/?p={$post->ID}"
			);
		}
	}

	/**
	 * Fix the HTML and remove stuff we don't like.
	 *
	 * See http://htmlpurifier.org/live/configdoc/plain.html
	 *
	 * @param string $text The text to clean up.
	 *
	 * @return string
	 */
	private function cleanup_text( string $text ): string {
		static $purifier = null;
		if ( null === $purifier ) {
			$config = HTMLPurifier_HTML5Config::createDefault();
			$config->set( 'AutoFormat.RemoveEmpty.RemoveNbsp', true );
			$config->set( 'AutoFormat.RemoveEmpty', true );
			$config->set( 'Attr.AllowedFrameTargets', [ '_blank' ] );
			$config->set( 'AutoFormat.RemoveSpansWithoutAttributes', true );
			$purifier = new HTMLPurifier( $config );
		}

		return $purifier->purify( $text );
	}

	private function grab_featured_image( $url ): int {
		$response = wp_remote_get( $url );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $response['body'] ) ) {
			return 0;
		}
		global $wpdb;
		$html_doc = new HtmlDocument( $response['body'] );

		$img = $html_doc->find( 'header img', 0 );
		$src = $img?->getAttribute( 'src' );
		if ( ! $src ) {
			return 0;
		}
		if ( str_ends_with( $src, '/image' ) ) {
			$src = substr( $src, 0, - 6 );
		}
		$path          = trim( parse_url( $src, PHP_URL_PATH ), '/' );
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts LEFT JOIN $wpdb->postmeta ON ID = post_id WHERE post_type = 'attachment' AND meta_key = 'plone_tree_path' AND meta_value = %s;",
				$path
			)
		);

		return empty( $attachment_id ) ? 0 : (int) $attachment_id;
	}

	private function replace_related_placeholders_with_homepage_blocks( string $content ): string {
		if ( ! str_contains( $content, '[RELATED:' ) || ! preg_match_all( '@<p>\[RELATED:(.*?)]</p>@', $content, $matches, PREG_SET_ORDER ) ) {
			return $content;
		}
		global $wpdb;
		foreach ( $matches as $match ) {
			$path         = trim( parse_url( $match[1], PHP_URL_PATH ), '/' );
			$related_post = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_tree_path' AND meta_value = %s;",
					$path
				)
			);

			$args  = [
				'showReadMore'  => false,
				'showDate'      => false,
				'showAuthor'    => false,
				'specificMode'  => true,
				'typeScale'     => 3,
				'sectionHeader' => 'Related',
				'postsToShow'   => 1,
				'showExcerpt'   => false,
			];
			$block = $this->gutenberg_block_generator->get_homepage_articles_for_specific_posts( [ $related_post ], $args, );
			$group = serialize_block( $this->gutenberg_block_generator->get_group_constrained( [ $block ], [ 'is-style-border', 'alignright' ] ) );

			$content = str_replace( $match[0], $group, $content );
		}

		return $content;
	}

	private function replace_videos( HtmlDocument $html_doc ): void {
		global $wpdb;

		$vids = $html_doc->find( 'video' );
		foreach ( $vids as $vid ) {
			$src = $vid->find( 'source', 0 )?->getAttribute( 'src' );
			if ( empty( $src ) ) {
				continue;
			}
			$attachment_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts LEFT JOIN $wpdb->postmeta ON ID = post_id WHERE post_type = 'attachment' AND meta_key = 'plone_tree_path' AND meta_value LIKE %s;",
					'%' . $wpdb->esc_like( $src )
				)
			);
			if ( ! $attachment_id ) {
				continue;
			}
			$video_post     = get_post( $attachment_id );
			$vid->outertext = serialize_block( $this->gutenberg_block_generator->get_video( $video_post ) );
		}
	}

	private function replace_twitter_embeds( HtmlDocument $html_doc ): void {

		$twitter_blockquoutes = $html_doc->find( 'blockquote.twitter-tweet' );
		foreach ( $twitter_blockquoutes as $tb ) {
			$link = $tb->find( 'a', - 1 );
			if ( empty( $link ) ) {
				continue;
			}
			$url = $link->getAttribute( 'href' );
			if ( $url ) {
				$tb->outertext = serialize_block( $this->gutenberg_block_generator->get_twitter( $url ) );
			}
		}
	}

	private function replace_img_tags( HtmlDocument $html_doc ): void {
		$imgs_to_resolve = $html_doc->find( 'img' );
		foreach ( $imgs_to_resolve as $img ) {
			if ( ! preg_match( '@^resolveuid/([0-9a-z]+)/?(.*)@', $img->getAttribute( 'src' ), $matches ) ) {
				continue;
			}

			$attachment_id = $this->get_attachment_id_by_uid( $matches[1] );
			if ( ! $attachment_id ) {
				continue;
			}

			$img_post = get_post( $attachment_id );
			if ( ! $img_post ) {
				continue;
			}
			$align = null;

			$container_class = $img->find_ancestor_tag( 'div' )?->getAttribute( 'class' ) ?? '';
			if ( str_contains( $container_class, 'full-bleed' ) ) {
				$align = 'full';
			}
			$img->outertext = serialize_block(
				$this->gutenberg_block_generator->get_image(
					$img_post,
					'full',
					false,
					null,
					$align
				)
			);
		}
	}

	private function get_post_name( string $url ): string {
		$path_parts = explode( '/', trim( parse_url( $url, PHP_URL_PATH ), '/' ) );
		$post_name  = array_pop( $path_parts );

		return sanitize_title( $post_name );
	}

	private function set_categories_on_post_from_path( WP_Post $post, string $original_path ): void {
		$trimmed_path = trim( $original_path, '/' );
		$path_parts   = explode( '/', $trimmed_path );
		// Pop the last part, which is the post name, so the path is only categories.
		array_pop( $path_parts );
		if ( empty( $path_parts ) ) {
			// This post only has one part to the url. E.g. site.com/about.
			return;
		}

		if ( 'articles' === $path_parts[0] ) {
			$path_parts = array_slice( $path_parts, 0, 1 );
		} elseif ( count( $path_parts ) > 2 ) {
			// We pop a part off if there are more than 2 parts to the path (after having popped of the post name). For example:
			// issues/43.10/how-developers-and-businessmen-cash-in-on-grand-canyon-overflights/park-service-finally-drafts-a-solution-to-conflicts-over-canyon-flights
			// we remove the part after "43.10".
			$path_parts = array_slice( $path_parts, 0, 2 );
		}

		$post_categories      = array_map( fn( $cat ) => $cat->term_id, get_the_category( $post->ID ) );
		$categories_from_path = [];
		$parent               = 0;
		foreach ( $path_parts as $part ) {
			$cat_id = false;
			$cat    = get_category_by_slug( $part );
			if ( $cat ) {
				$cat_id = $cat->term_id;
			}
			if ( ! $cat_id ) {
				$cat_id = wp_insert_category(
					[
						'cat_name'          => ucfirst( str_replace( [ '-', '_' ], ' ', $part ) ),
						'category_nicename' => $part,
						'category_parent'   => $parent,
					]
				);
			}
			$categories_from_path[] = $cat_id;
			$parent                 = $cat_id;
		}

		if ( $post_categories !== $categories_from_path ) {
			wp_set_post_categories( $post->ID, $categories_from_path );
		}
		if ( count( $categories_from_path ) > 1 ) {
			// Set the last item in the path as primary to keep the url structure.
			update_post_meta( $post->ID, '_yoast_wpseo_primary_category', end( $categories_from_path ) );
		}
	}

	/**
	 * Get articles that have a pdf url.
	 *
	 * @param string $articles_json_file_path Path to the articles JSON file.
	 *
	 * @return array Array with UID as key and pdf url as value.
	 * @throws Exception
	 */
	private function get_pdf_urls_from_json( string $articles_json_file_path ): array {
		if ( ! file_exists( $articles_json_file_path ) ) {
			throw new Exception( sprintf( 'The file supplied is either not a file or does not exist: %s', $articles_json_file_path ) );
		}

		$pdfurls_array = [];
		$jq_query      = <<<QUERY
cat $articles_json_file_path | jq '. [] | select(."pdfurl"|test("pdf$")) | {UID: .parent.UID, pdfurl}' | jq -s 
QUERY;
		exec( $jq_query, $output_array );
		if ( ! empty( $output_array[0] ) ) {
			$json = json_decode( implode( '', $output_array ) );
			foreach ( (array) $json as $arr ) {
				$pdfurls_array[ $arr->UID ] = $arr->pdfurl;
			}
		}

		return $pdfurls_array;
	}

	/**
	 * Tries various tricks to get the PDF attachment ID for an issue.
	 *
	 * @param int    $post_id The post ID to attach the PDF to.
	 * @param object $issue The issue object.
	 * @param array  $pdfurls array of UID => pdfurl. See get_pdf_urls_from_json().
	 *
	 * @return int The attachment ID or 0 if not found.
	 * @throws Exception
	 */
	private function get_issue_pdf_attachment_id( int $post_id, object $issue, array $pdfurls ): int {
		if ( array_key_exists( $issue->UID ?? '', $pdfurls ) ) {
			$pdf_url           = 'https://s3.amazonaws.com/hcn-media/archive-pdf/' . $pdfurls[ $issue->UID ];
			$pdf_attachment_id = $this->attachments->import_attachment_for_post(
				$post_id,
				$pdf_url,
			);
			if ( ! is_wp_error( $pdf_attachment_id ) ) {
				return $pdf_attachment_id;
			}
		}

		// The pagesuite urls all don't work, so no need to try to download them if it's the pagesuite url.
		if ( ! empty( $issue->digitalEditionURL ) && ! str_starts_with( $issue->digitalEditionURL, 'http://edition.pagesuite-professional' ) ) {
			$filename = basename( $issue->digitalEditionURL );
			// Create a filename for the ones that don't have a .pdf extension.
			if ( ! str_ends_with( $filename, '.pdf' ) ) {
				$effective_date = new DateTime( $issue->effective, $this->site_timezone );
				$filename       = 'issue-' . $effective_date->format( 'Y_m_d' ) . '.pdf';
			}
			$pdf_attachment_id = $this->attachments->import_attachment_for_post(
				$post_id,
				$issue->digitalEditionURL,
				'',
				[],
				$filename
			);
			if ( ! is_wp_error( $pdf_attachment_id ) ) {
				return $pdf_attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Builds block content for an issue page.
	 *
	 * @param int    $category_id
	 * @param string $description
	 * @param int    $image_attachment_id
	 * @param int    $pdf_id
	 *
	 * @return string
	 */
	private function get_issue_page_content_for_category( int $category_id, string $description, int $image_attachment_id, int $pdf_id ): string {
		$left_column_blocks  = [ $this->gutenberg_block_generator->get_paragraph( $description, '', '', 'small' ) ];
		$right_column_blocks = [];
		if ( 0 !== $image_attachment_id ) {
			$img_post              = get_post( $image_attachment_id );
			$right_column_blocks[] = $this->gutenberg_block_generator->get_image( $img_post, 'full', false );
		}
		if ( 0 !== $pdf_id ) {
			$link                  = wp_get_attachment_url( $pdf_id );
			$right_column_blocks[] = $this->gutenberg_block_generator->get_paragraph( '<a href="' . $link . '">Download the Digital Issue</a>' );
		}
		$left_column      = $this->gutenberg_block_generator->get_column( $left_column_blocks );
		$right_column     = $this->gutenberg_block_generator->get_column( $right_column_blocks );
		$content_blocks[] = $this->gutenberg_block_generator->get_columns( [ $left_column, $right_column ] );
		$content_blocks[] = $this->gutenberg_block_generator->get_separator( 'is-style-wide' );

		$content_blocks[] = $this->gutenberg_block_generator->get_homepage_articles_for_category(
			[ $category_id ],
			[
				'moreButton'     => true,
				'moreButtonText' => 'More from this issue',
				'showAvatar'     => false,
				'postsToShow'    => 60,
				'mediaPosition'  => 'left',
			]
		);

		return array_reduce(
			$content_blocks,
			fn( string $carry, array $item ): string => $carry . serialize_block( $item ),
			''
		);
	}

	private function upgrade_user_to_author( $user_id ): bool {
		$user = get_userdata( $user_id );
		$user->set_role( 'author' );

		return ! is_wp_error( wp_update_user( $user ) );
	}

	/**
	 * Helper to get post ID from the Plone ID.
	 *
	 * @param string $uid Plone ID
	 *
	 * @return int Post ID or 0 if we couldn't find it.
	 */
	private function get_post_id_from_uid( string $uid ): int {
		global $wpdb;
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_article_UID' AND meta_value = %s",
				$uid
			)
		);
		if ( ! empty( $post_id ) ) {
			return (int) $post_id;
		}

		return 0;
	}

	private function get_attachment_id_by_uid( string $uid ): int {
		global $wpdb;
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_image_UID' AND meta_value = %s",
				$uid
			)
		);

		return empty( $attachment_id ) ? 0 : (int) $attachment_id;
	}

	private function get_attachment_ids_by_tree_path( string $tree_path ): array {
		global $wpdb;
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts LEFT JOIN $wpdb->postmeta ON ID = post_id WHERE post_type = 'attachment' AND meta_key = 'plone_tree_path' AND meta_value LIKE %s;",
				$wpdb->esc_like( $tree_path ) . '%'
			)
		);

		return $attachment_ids;
	}

	private function get_related_link_markup( int $post_id ): string {
		$permalink  = "/?p={$post_id}";
		$post_title = get_the_title( $post_id );

		return <<<HTML
<p class="hcn-related"><span class="hcn-related__label">Related:</span> <a href="$permalink" class="hcn-related__link" target="_blank">$post_title</a></p>
HTML;
	}

	/**
	 * Quick lo-tech author parser. Weeds out some very site specific things and probably misses a lot of stuff.
	 *
	 * @param string $authors A comma, and, or & and separated byline.
	 *
	 * @return array
	 */
	private function parse_author_string( string $authors ): array {
		$bad     = [
			'MD',
		];
		$strip   = [
			'with additional reporting by',
			'based on information provided by High Country News',
			'Updated by',
		];
		$authors = str_replace( '\n', '', $authors );

		$good = [];
		// Split by , and &.
		foreach ( preg_split( '/(,\s|\s&\s|(\sand\s))/', $authors ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( empty( $candidate ) || is_numeric( $candidate ) || in_array( $candidate, $bad ) ) {
				continue;
			}
			foreach ( $strip as $strip_candidate ) {
				$candidate = str_replace( $strip_candidate, '', $candidate );
			}
			if ( ! empty( $candidate ) ) {
				$good[] = trim( trim( $candidate, '.' ) );
			}
		}

		return $good;
	}
}