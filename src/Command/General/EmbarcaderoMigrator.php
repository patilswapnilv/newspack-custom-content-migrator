<?php

namespace NewspackCustomContentMigrator\Command\General;

use DateTimeZone;
use DOMDocument;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Utils\WordPressXMLHandler;
use WP_CLI;

/**
 * This class implements the logic for migrating content from Embarcadero custom CMS.
 *
 * The export contains content in different CSV files, and a folder with all the images.
 *
 * To migrate the content we need to run the following commands:
 *     - wp newspack-content-migrator embarcadero-import-posts-content \
 *       --index-from=0 \
 *       --index-to=2000 \
 *       --story-csv-file-path=/path/to/story.csv \
 *       --story-byline-email-file-path=/path/to/story_byline_email.csv \
 *       --story-sections-file-path=/path/to/story_sections.csv \
 *       --story-photos-file-path=/path/to/story_photos.csv \
 *       --story-photos-dir-path=/path/to/photos \
 *       --story-media-file-path=/path/to/story_media.csv \
 *       --story-carousel-items-dir-path=/path/to/story_carousel_items.csv
 *
 *     - wp newspack-content-migrator embarcadero-migrate-post-tags \
 *       --story-tags-csv-file-path=/path/to/story_tags.csv
 *
 *     - wp newspack-content-migrator embarcadero-migrate-posts-featured-image \
 *       --story-photos-file-path=/path/to/story_photos.csv \
 *       --story-photos-dir-path=/path/to/photos
 *
 *     - wp newspack-content-migrator embarcadero-migrate-more-posts-block \
 *       --story-csv-file-path=/path/to/story.csv \
 *       --story-media-file-path=/path/to/story_media.csv
 *
 *     - wp newspack-content-migrator embarcadero-migrate-comments \
 *       --comments-csv-file-path=/path/to/comments.csv \
 *       --comments-zones-file-path=/path/to/comment_zones.csv
 *       --users-file-path==/path/to/registrated_users.csv
 *
 *     - wp newspack-content-migrator embarcadero-migrate-print-issues \
 *       --publication-name="Danville San Ramon" \
 *       --publication-email="info@danvillesanramon.com" \
 *       --print-issues-csv-file-path=/path/to/print_issues.csv \
 *       --print-sections-csv-file-path=/path/to/print_sections.csv \
 *       --print-pdf-dir-path=/path/to/morguepdf \
 *       --print-cover-dir-path=/path/to/covers
 *
 * *** How to fix CSVs if migrator reports that some rows can't be read (different count of header columns and found data columns for a row):
 *  1. convert your CSV to TSV using OSX's Pages
 *  2. feed the TSV to the helper command embarcadero-helper-fix-tsv-file and get a new fixed TSV file
 *  3. feed the new fixed TSV file instead of the --csv-file= argument in the import command -- it will also accept TSVs, even though the argument name says CSV
 *
 * @package NewspackCustomContentMigrator
 */
class EmbarcaderoMigrator implements InterfaceCommand {
	const LOG_FILE                                  = 'embarcadero_importer.log';
	const TAGS_LOG_FILE                             = 'embarcadero_tags_migrator.log';
	const FEATURED_IMAGES_LOG_FILE                  = 'embarcadero_featured_images_migrator.log';
	const MORE_POSTS_LOG_FILE                       = 'embarcadero_more_posts_migrator.log';
	const EMBARCADERO_ORIGINAL_ID_META_KEY          = '_newspack_import_id';
	const EMBARCADERO_ORIGINAL_TOPIC_ID_META_KEY    = '_newspack_import_topic_id';
	const EMBARCADERO_IMPORTED_TAG_META_KEY         = '_newspack_import_tag_id';
	const EMBARCADERO_IMPORTED_FEATURED_META_KEY    = '_newspack_import_featured_image_id';
	const EMBARCADERO_IMPORTED_MORE_POSTS_META_KEY  = '_newspack_import_more_posts_image_id';
	const EMBARCADERO_IMPORTED_COMMENT_META_KEY     = '_newspack_import_comment_id';
	const EMBARCADERO_IMPORTED_PRINT_ISSUE_META_KEY = '_newspack_import_print_issue_id';
	const EMBARCADERO_MIGRATED_POST_SLUG_META_KEY   = '_newspack_migrated_post_slug_id';
	const EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY    = '_newspack_media_import_id';
	const DEFAULT_AUTHOR_NAME                       = 'Staff';

	const ALLOWED_CATEGORIES = [
		'a&e',
		'alameda county',
		'alamo',
		'atherton',
		'belle haven',
		'blackhawk',
		'business',
		'city government',
		'city politics',
		'coastside',
		'community',
		'community leaders',
		'contra costa county',
		'courts',
		'cover story',
		'covid',
		'crime',
		'danville',
		'dublin',
		'east palo alto',
		'editorial',
		'education ',
		'election',
		'enterprise story',
		'environment',
		'family/lifestyle',
		'fire/wildfire',
		'food',
		'guest opinion',
		'health',
		'health care',
		'housing',
		'investigative story',
		'ladera',
		'land use',
		'livermore',
		'los altos',
		'los altos hills',
		'menlo park',
		'mountain view',
		'neighborhood',
		'north fair oaks',
		'obituary',
		'outdoor recreation',
		'palo alto',
		'peninsula',
		'pleasanton',
		'police',
		'portola valley',
		'poverty',
		'profile',
		'real estate',
		'redwood city',
		'regional politics',
		'san carlos',
		'san mateo county',
		'san ramon',
		'san ramon valley',
		'santa clara county',
		'seniors',
		'social justice',
		'social services',
		'sports',
		'stanford',
		'stanford university',
		'state',
		'sunol',
		'technology',
		'traffic',
		'transportation',
		'tri-valley',
		'video',
		'woodside',
		'youth',
		'top stories',
		'palo alto news',
		'around the region',
		'palo alto people',
		'palo alto city',
		'palo alto schools',
		'editorials',
		'guest opinion',
		'letters to the editor',
		'news',
		'roundup',
		'feature',
		'profile',
		'features',
		'meet the artist',
		'news & events',
		'coming up',
		'arts',
		'home improvement',
		'neighborhoods',
		'home sales',
		'diablo',
		'walnut creek',
		'pet of the week',
		'community leaders',
		'city limits',
		'triumph',
		'community kindness',
	];

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Logger instance.
	 *
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
	 * CoAuthorsPlus instance.
	 *
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * @var DateTimeZone Embarcadero sites timezone.
	 */
	private $site_timezone;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger                    = new Logger();
		$this->attachments               = new Attachments();
		$this->coauthorsplus_logic       = new CoAuthorPlus();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();

		$this->site_timezone = new DateTimeZone( 'America/Los_Angeles' );
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
				'shortdesc' => 'Import Embarcadero\'s post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'index-from',
						'description' => 'Start importing from this index in the CSV file.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'index-to',
						'description' => 'Import till this index in the CSV file.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'email-domain',
						'description' => 'Domain to use for the email address of the users that don\'t have an email address in the CSV file.',
						'optional'    => false,
						'repeating'   => false,
					],
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
					[
						'type'        => 'flag',
						'name'        => 'refresh-content',
						'description' => 'Refresh the content of the posts that were already imported.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'skip-post-content',
						'description' => 'Skip refreshing the post content. Useful when you want to refresh only the post meta.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'skip-post-photos',
						'description' => 'Skip refreshing the post media in content.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-import-missing-posts',
			array( $this, 'cmd_embarcadero_import_missing_posts' ),
			[
				'shortdesc' => 'Import Embarcadero\'s post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'email-domain',
						'description' => 'Domain to use for the email address of the users that don\'t have an email address in the CSV file.',
						'optional'    => false,
						'repeating'   => false,
					],
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
					[
						'type'        => 'assoc',
						'name'        => 'story-report-items-dir-path',
						'description' => 'Path to the CSV file containing the stories\'s report items to import.',
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

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-timeline-block',
			array( $this, 'cmd_embarcadero_migrate_timeline_block' ),
			[
				'shortdesc' => 'Import Embarcadero\s post "timeline" block.',
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

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-fix-more-posts-block',
			array( $this, 'cmd_embarcadero_fix_more_posts_block' ),
			[
				'shortdesc' => 'Fix Embarcadero\s post "more posts" block.',
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

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-images',
			array( $this, 'cmd_embarcadero_migrate_images' ),
			[
				'shortdesc' => 'Migrate images that we missed by the import-posts-content command.',
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
			'newspack-content-migrator embarcadero-migrate-comments',
			array( $this, 'cmd_embarcadero_migrate_comments' ),
			[
				'shortdesc' => 'Migrate comments that we missed by the import-posts-content command.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'comments-csv-file-path',
						'description' => 'Path to the CSV file containing the stories\'s comments to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'comments-zones-file-path',
						'description' => 'Path to the CSV file containing the comments\'s zones to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'users-file-path',
						'description' => 'Path to the CSV file containing the comments\'s users to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-print-issues',
			array( $this, 'cmd_embarcadero_migrate_print_issues' ),
			[
				'shortdesc' => 'Migrate print issues that we missed by the import-posts-content command.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'publication-name',
						'description' => 'Publication name to use for the print issues.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'publication-email',
						'description' => 'Publication email to use for the print issues.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'pdf-section-suffix',
						'description' => 'Suffix to add to the PDF section name to create the PDF file name. Example: if the file name is "2006_05_19.mvv.section1.pdf" the suffix would be "mvv".',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'print-issues-csv-file-path',
						'description' => 'Path to the CSV file containing print issues to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'print-sections-csv-file-path',
						'description' => 'Path to the CSV file containing print sections to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'print-pdf-dir-path',
						'description' => 'Path to the directory containing the print issues PDF files to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'print-cover-dir-path',
						'description' => 'Path to the directory containing the print issues cover files to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-post-slugs',
			array( $this, 'cmd_embarcadero_migrate_post_slugs' ),
			[
				'shortdesc' => 'Migrate Embarcadero\'s post slugs.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-csv-file-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-helper-fix-tsv-file',
			array( $this, 'cmd_embarcadero_helper_fix_tsv_file' ),
			[
				'shortdesc' => 'A helper command which takes a TSV file and tries to fix the ambiguous \"" and \" escaping.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'tsv-file-input',
						'description' => 'Full path to TSV file which is having issues being read by filecsv( $file, null, "\t" ).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'tsv-file-output',
						'description' => 'Where to save the new (hopefully fixed) content. Full file path.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-helper-validate-csv-file',
			array( $this, 'cmd_embarcadero_helper_validate_csv_file' ),
			[
				'shortdesc' => 'A helper command which validates a CSV file and outputs rows with issues.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-file-input',
						'description' => 'Full path to CSV file which is having issues being read by filecsv( $file, null, "\t" ).',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-helper-fix-dates',
			array( $this, 'cmd_fix_post_times' ),
			[
				'shortdesc' => 'Fix post dates to match timezone.',
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
						'name'        => 'index-from',
						'description' => 'Start importing from this index in the CSV file.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'index-to',
						'description' => 'Import till this index in the CSV file.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-rearrange-categories',
			array( $this, 'cmd_embarcadero_rearrange_categories' ),
			[
				'shortdesc' => 'Import Embarcadero\'s post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'index-from',
						'description' => 'Start importing from this index in the CSV file.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'index-to',
						'description' => 'Import till this index in the CSV file.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-csv-file-path',
						'description' => 'Path to the CSV file containing the stories to import.',
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
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-import-six-fifty-content',
			[ $this, 'cmd_migrate_six_fifty_content' ],
			[
				'shortdesc' => 'Six Fifty content needs to be merged into Embarcadero sites.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'media-xml-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts-xml-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-fix-six-fifty-missing-authors',
			[ $this, 'cmd_fix_six_fifty_missing_authors' ],
			[
				'shortdesc' => 'Fixes missing authors for Six Fifty content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'media-xml-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts-xml-path',
						'description' => 'Path to the CSV file containing the stories to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-fix-content-styling',
			array( $this, 'cmd_embarcadero_fix_content_styling' ),
			[
				'shortdesc' => 'Import Embarcadero\'s post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-post-launch-qa',
			array( $this, 'cmd_embarcadero_post_launch_qa' ),
			[
				'shortdesc' => 'Check for migration issues after the launch.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-csv-file-paths',
						'description' => 'Path to the CSV files separated by a comma containing the stories to import (e.g. export/file1.csv,export/file2.csv).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-photos-file-paths',
						'description' => 'Path to the CSV files separated by a comma containing the stories\'s photos to import (e.g. export/file1.csv,export/file2.csv).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-media-file-paths',
						'description' => 'Path to the CSV files separated by a comma containing the stories\'s media to import (e.g. export/file1.csv,export/file2.csv).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-carousel-items-dir-paths',
						'description' => 'Path to the CSV files separated by a comma containing the stories\'s carousel items to import (e.g. export/file1.csv,export/file2.csv).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'story-report-items-dir-paths',
						'description' => 'Path to the CSV files separated by a comma containing the stories\'s report items to import (e.g. export/file1.csv,export/file2.csv).',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator embarcadero-migrate-pdfs',
			array( $this, 'cmd_embarcadero_migrate_pdfs' ),
			[
				'shortdesc' => 'Check for migration issues after the launch.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'story-report-items-dir-path',
						'description' => 'Path to the CSV file containing the stories\'s report items to import.',
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
		$email_domain                      = $assoc_args['email-domain'];
		$index_from                        = isset( $assoc_args['index-from'] ) ? intval( $assoc_args['index-from'] ) : 0;
		$index_to                          = isset( $assoc_args['index-to'] ) ? intval( $assoc_args['index-to'] ) : -1;
		$refresh_content                   = isset( $assoc_args['refresh-content'] ) ? true : false;
		$skip_post_content                 = isset( $assoc_args['skip-post-content'] ) ? true : false;
		$skip_post_photos                  = isset( $assoc_args['skip-post-photos'] ) ? true : false;
		$update_post_content               = ( $refresh_content && ! $skip_post_content ) || ! $refresh_content;

		// Validate co-authors plugin is active.
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$posts                 = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$contributors          = $this->get_data_from_csv_or_tsv( $story_byline_emails_csv_file_path );
		$sections              = $this->get_data_from_csv_or_tsv( $story_sections_csv_file_path );
		$photos                = $this->get_data_from_csv_or_tsv( $story_photos_csv_file_path );
		$media                 = $this->get_data_from_csv_or_tsv( $story_media_csv_file_path );
		$carousel_items        = $this->get_data_from_csv_or_tsv( $story_carousel_items_dir_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_ORIGINAL_ID_META_KEY );

		// Get selected posts.
		if ( -1 !== $index_to ) {
			$posts = array_slice( $posts, $index_from, $index_to - $index_from + 1 );
		}

		// Skip already imported posts if needed.
		if ( ! $refresh_content ) {
			$posts = array_values(
				array_filter(
					$posts,
					function ( $post ) use ( $imported_original_ids ) {
						return ! in_array( $post['story_id'], $imported_original_ids );
					}
				)
			);
		}

		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Importing post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::LINE );

			$wp_contributor_id = null;
			if ( ! empty( $post['byline'] ) && ! empty( $post['author_email'] ) ) {
				$wp_contributor_id = $this->get_or_create_user( $post['byline'], $post['author_email'], 'contributor' );
				if ( is_wp_error( $wp_contributor_id ) ) {
					$wp_contributor_id = null;
				}
			}

			// Get the post slug.
			$post_name = $this->migrate_post_slug( $post['seo_link'] );

			// phpcs:ignore
			$story_text         = str_replace( "\n", "</p>\n<p>", '<p>' . $post['story_text'] . '</p>' );

			$post_data = [
				'post_title'   => $post['headline'],
				'post_content' => $story_text,
				'post_excerpt' => $post['front_paragraph'],
				'post_status'  => 'Yes' === $post['approved'] ? 'publish' : 'draft',
				'post_type'    => 'post',
				'post_date'    => $this->get_post_date_from_timestamp( $post['date_epoch'] ),
				'post_author'  => $wp_contributor_id,
			];

			if ( ! empty( $post_name ) ) {
				$post_data['post_name'] = $post_name;
			}

			if ( ! empty( $post['date_updated_epoch'] ) ) {
				$post_data['post_modified'] = $this->get_post_date_from_timestamp( $post['date_updated_epoch'] );
			}

			// Create or get the post.
			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			// If the post exists and we don't want to refresh it, skip it.
			if ( ! $refresh_content && $wp_post_id ) {
				$this->logger->log(
					self::LOG_FILE,
					sprintf( 'Skipping entry %s because it\'s already imported.', $post['story_id'] ),
					$this->logger::WARNING
				);

				continue;
			}

			$post_created = false;
			if ( ! $wp_post_id ) {
				$wp_post_id   = wp_insert_post( $post_data );
				$post_created = true;
			}

			if ( is_wp_error( $wp_post_id ) ) {
				$err = $wp_post_id->get_error_message();
				$this->logger->log( self::LOG_FILE, "uid: {$post['story_id']} errorInserting: " . $err );
				continue;
			}

			if ( ! $wp_post_id ) {
				$this->logger->log( self::LOG_FILE, "uid: {$post['story_id']} errorGetting." );
				continue;
			}

			WP_CLI::line(
				$post_created
				? "Created post ID $wp_post_id for {$post['seo_link']}"
				: "Fetched post ID $wp_post_id for {$post['seo_link']}"
			);

			// Migrate post content shortcodes.
			$post_content = get_post_field( 'post_content', $wp_post_id );

			$updated_post_content = $update_post_content
			? $this->migrate_post_content_shortcodes( $post['story_id'], $wp_post_id, $post_content, $photos, $story_photos_dir_path, $media, $carousel_items, $skip_post_photos )
			: $post_content;

			// Set the original ID.
			update_post_meta( $wp_post_id, self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			if ( ! empty( $post['topic_id'] ) ) {
				update_post_meta( $wp_post_id, self::EMBARCADERO_ORIGINAL_TOPIC_ID_META_KEY, $post['topic_id'] );
			}

			// Set the post subhead.
			update_post_meta( $wp_post_id, 'newspack_post_subtitle', $post['subhead'] );

			// Add tag_2 to the post.
			if ( ! empty( $post['tag_2'] ) ) {
				$updated_post_content .= serialize_block(
					$this->gutenberg_block_generator->get_paragraph( '<em>' . $post['tag_2'] . '</em>' )
				);
			}

			// Set co-author if needed.
			if ( ! $wp_contributor_id ) {
				$coauthors       = $this->get_co_authors_from_bylines( $post['byline'] );
				$co_author_users = $this->get_generate_coauthor_users( $coauthors, $contributors, $email_domain );
				if ( 1 === count( $co_author_users ) ) {
					$author_user = current( $co_author_users );
					wp_update_post(
						[
							'ID'          => $wp_post_id,
							'post_author' => $author_user->ID,
						]
					);
				} else {
					$co_author_nicenames = array_map(
						function ( $co_author_user ) {
							return $co_author_user->user_nicename;
						},
						$co_author_users
					);

					$this->coauthorsplus_logic->coauthors_plus->add_coauthors( $wp_post_id, $co_author_nicenames );
				}

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
			if ( $update_post_content && ( $updated_post_content !== $post['story_text'] ) ) {
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
				$section = $sections[ $post_section_index ];

				if ( ! in_array( strtolower( $section['section'] ), self::ALLOWED_CATEGORIES ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Section %s is not allowed for post %s', $section['section'], $post['headline'] ), Logger::WARNING );
					// Create and set "General" as the post category.
					$category_id = $this->get_or_create_category( 'General' );
				} else {
					$category_id = $this->get_or_create_category( $section['section'] );
				}

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

			if ( str_starts_with( $post['story_text'], '{photo' ) ) {
				update_post_meta( $wp_post_id, 'newspack_featured_image_position', 'hidden' );
			}

			$this->logger->log( self::LOG_FILE, sprintf( 'Imported post %d/%d: %d with the ID %d', $post_index + 1, count( $posts ), $post['story_id'], $wp_post_id ), Logger::SUCCESS );
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-import-missing-posts".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_import_missing_posts( $args, $assoc_args ) {
		$posts_per_batch                   = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch                             = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;
		$story_csv_file_path               = $assoc_args['story-csv-file-path'];
		$story_byline_emails_csv_file_path = $assoc_args['story-byline-email-file-path'];
		$story_sections_csv_file_path      = $assoc_args['story-sections-file-path'];
		$story_photos_csv_file_path        = $assoc_args['story-photos-file-path'];
		$story_photos_dir_path             = $assoc_args['story-photos-dir-path'];
		$story_media_csv_file_path         = $assoc_args['story-media-file-path'];
		$story_carousel_items_dir_path     = $assoc_args['story-carousel-items-dir-path'];
		$story_report_items_dir_path       = $assoc_args['story-report-items-dir-path'];
		$email_domain                      = $assoc_args['email-domain'];

		// Validate co-authors plugin is active.
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$stories        = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$contributors   = $this->get_data_from_csv_or_tsv( $story_byline_emails_csv_file_path );
		$sections       = $this->get_data_from_csv_or_tsv( $story_sections_csv_file_path );
		$photos         = $this->get_data_from_csv_or_tsv( $story_photos_csv_file_path );
		$media          = $this->get_data_from_csv_or_tsv( $story_media_csv_file_path );
		$carousel_items = $this->get_data_from_csv_or_tsv( $story_carousel_items_dir_path );
		$report_items   = $this->get_data_from_csv_or_tsv( $story_report_items_dir_path );

		$date_query = [
			[
				'before'    => '2024-01-15',
				'inclusive' => false,
				'column'    => 'post_modified',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'fields'         => 'ids',
				// 'date_query'     => $date_query,
				'no_found_rows'  => true,
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				// 'p'              => 48,
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'fields'         => 'ids',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				// 'date_query'     => $date_query,
			]
		);

		$posts       = $query->get_posts();
		$total_posts = count( $posts );

		foreach ( $posts as $post_index => $post_id ) {
			$this->logger->log( 'import-missing-drafts.log', sprintf( 'Checking draft post %d/%d: %d', $post_index + 1, $total_posts, $post_id ), Logger::LINE );

			// Get draft post original ID.
			$original_id = get_post_meta( $post_id, self::EMBARCADERO_ORIGINAL_ID_META_KEY, true );

			// If the post hasn't been imported, skip it.
			if ( ! $original_id ) {
				$this->logger->log( 'import-missing-drafts.log', sprintf( 'Skipping draft post %d/%d: %d because it hasn\'t been imported.', $post_index + 1, $total_posts, $post_id ), Logger::INFO );
				continue;
			}

			// Get the original story data.
			$story_index = array_search( $original_id, array_column( $stories, 'story_id' ) );

			// If the story doesn't exist, skip it.
			if ( false === $story_index ) {
				$this->logger->log( 'import-missing-drafts.log', sprintf( 'Skipping draft post %d/%d: %d because the story doesn\'t exist.', $post_index + 1, $total_posts, $post_id ), Logger::WARNING );
				continue;
			}

			$story = $stories[ $story_index ];

			$wp_contributor_id = null;
			if ( ! empty( $story['byline'] ) && ! empty( $story['author_email'] ) ) {
				$wp_contributor_id = $this->get_or_create_user( $story['byline'], $story['author_email'], 'contributor' );
				if ( is_wp_error( $wp_contributor_id ) ) {
					$wp_contributor_id = null;
				}
			}

			// Get the post slug.
			$post_name = $this->migrate_post_slug( $story['seo_link'] );

			// phpcs:ignore
			$story_text         = str_replace( "\n", "</p>\n<p>", '<p>' . $story['story_text'] . '</p>' );

			// Migrate post content shortcodes.
			$updated_post_content = $this->migrate_post_content_shortcodes( $story['story_id'], $post_id, $story_text, $photos, $story_photos_dir_path, $media, $carousel_items, false, $report_items );

			// Add tag_2 to the post.
			if ( ! empty( $story['tag_2'] ) ) {
				$updated_post_content .= serialize_block(
					$this->gutenberg_block_generator->get_paragraph( '<em>' . $story['tag_2'] . '</em>' )
				);
			}

			if ( 'tag' === $story['byline_tag_option'] ) {
				if ( ! $wp_contributor_id ) {
					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_paragraph( '<em>By ' . $story['byline'] . '</em>' )
					);
				} else {
					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_author_profile( $wp_contributor_id )
					);
				}

				// Add "Bottom Byline" tag to the post.
				wp_set_post_tags( $post_id, 'Bottom Byline', true );
			}

			$post_data = [
				'ID'           => $post_id,
				'post_title'   => $story['headline'],
				'post_content' => $updated_post_content,
				'post_excerpt' => $story['front_paragraph'],
				'post_status'  => 'Yes' === $story['approved'] ? 'publish' : 'draft',
				'post_type'    => 'post',
				'post_date'    => $this->get_post_date_from_timestamp( $story['date_epoch'] ),
				'post_author'  => $wp_contributor_id,
			];

			if ( ! empty( $post_name ) ) {
				$post_data['post_name'] = $post_name;
			}

			if ( ! empty( $story['date_updated_epoch'] ) ) {
				$post_data['post_modified'] = $this->get_post_date_from_timestamp( $story['date_updated_epoch'] );
			}

			wp_update_post( $post_data );

			if ( ! empty( $story['topic_id'] ) ) {
				update_post_meta( $post_id, self::EMBARCADERO_ORIGINAL_TOPIC_ID_META_KEY, $story['topic_id'] );
			}

			// Set the post subhead.
			update_post_meta( $post_id, 'newspack_post_subtitle', $story['subhead'] );

			// Set co-author if needed.
			if ( ! $wp_contributor_id ) {
				$coauthors       = $this->get_co_authors_from_bylines( $story['byline'] );
				$co_author_users = $this->get_generate_coauthor_users( $coauthors, $contributors, $email_domain );
				if ( 1 === count( $co_author_users ) ) {
					$author_user = current( $co_author_users );
					wp_update_post(
						[
							'ID'          => $post_id,
							'post_author' => $author_user->ID,
						]
					);
				} else {
					$co_author_nicenames = array_map(
						function ( $co_author_user ) {
							return $co_author_user->user_nicename;
						},
						$co_author_users
					);

					$this->coauthorsplus_logic->coauthors_plus->add_coauthors( $post_id, $co_author_nicenames );
				}

				$this->logger->log( 'import-missing-drafts.log', sprintf( 'Assigned co-authors %s to post "%s"', implode( ', ', $coauthors ), $story['headline'] ), Logger::LINE );
			}

			// Set categories from sections data.
			$post_section_index = array_search( $story['section_id'], array_column( $sections, 'section_id' ) );
			if ( false === $post_section_index ) {
				$this->logger->log( 'import-missing-drafts.log', sprintf( 'Could not find section %s for post %s', $story['section_id'], $story['headline'] ), Logger::WARNING );
			} else {
				$section = $sections[ $post_section_index ];

				if ( ! in_array( strtolower( $section['section'] ), self::ALLOWED_CATEGORIES ) ) {
					$this->logger->log( 'import-missing-drafts.log', sprintf( 'Section %s is not allowed for post %s', $section['section'], $story['headline'] ), Logger::WARNING );
					// Create and set "General" as the post category.
					$category_id = $this->get_or_create_category( 'General' );
				} else {
					$category_id = $this->get_or_create_category( $section['section'] );
				}

				if ( $category_id ) {
					wp_set_post_categories( $post_id, [ $category_id ] );
				}
			}

			// A few meta fields.
			if ( 'Yes' === $story['baycities'] ) {
				update_post_meta( $post_id, 'newspack_post_baycities', true );
			}

			if ( 'Yes' === $story['calmatters'] ) {
				update_post_meta( $post_id, 'newspack_post_calmatters', true );
			}

			if ( 'Yes' === $story['council'] ) {
				update_post_meta( $post_id, 'newspack_post_council', true );
			}

			if ( ! empty( $story['layout'] ) ) {
				wp_set_post_tags( $post_id, 'Layout ' . $story['layout'], true );
			}

			if ( ! empty( $story['hero_headline_size'] ) ) {
				wp_set_post_tags( $post_id, 'Headline ' . $story['hero_headline_size'], true );
			}

			if ( str_starts_with( $story['story_text'], '{photo' ) ) {
				update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
			}

			$this->logger->log( 'import-missing-drafts.log', sprintf( 'Imported post %d/%d: %d with the ID %d', $post_index + 1, count( $posts ), $story['story_id'], $post_id ), Logger::SUCCESS );
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

		$tags                  = $this->get_data_from_csv_or_tsv( $story_tags_csv_file_path );
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
			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $story_id );

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

		$photos                = $this->get_data_from_csv_or_tsv( $story_photos_csv_file_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_IMPORTED_FEATURED_META_KEY );

		$featured_photos = array_values(
			array_filter(
				$photos,
				function ( $photo ) use ( $imported_original_ids ) {
					return 'yes' === $photo['feature'] && ! in_array( $photo['photo_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $featured_photos as $post_index => $photo ) {
			$this->logger->log( self::FEATURED_IMAGES_LOG_FILE, sprintf( 'Importing featured image %d/%d: %d', $post_index + 1, count( $featured_photos ), $photo['photo_id'] ), Logger::LINE );

			$wp_post_id         = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $photo['story_id'] );
			$attachment_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY, $photo['photo_id'] );

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
	 * Get a date string with site timezone from a timestamp.
	 *
	 * @param int $timestamp Timestamp.
	 *
	 * @return string Date in format Y-m-d H:i:s in the site timezone.
	 */
	private function get_post_date_from_timestamp( int $timestamp ): string {
		$date = \DateTime::createFromFormat( 'U', $timestamp );
		$date->setTimezone( $this->site_timezone );

		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Fixes dates on posts to match the site timezone.
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 *
	 * @return void
	 */
	public function cmd_fix_post_times( array $args, array $assoc_args ): void {
		$story_csv_file_path = $assoc_args['story-csv-file-path'];
		$index_from          = isset( $assoc_args['index-from'] ) ? intval( $assoc_args['index-from'] ) : 0;
		$index_to            = isset( $assoc_args['index-to'] ) ? intval( $assoc_args['index-to'] ) : -1;

		$posts    = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$log_file = 'fix-post-times.log';

		// Get selected posts.
		if ( -1 !== $index_to ) {
			$posts = array_slice( $posts, $index_from, $index_to - $index_from + 1 );
		}

		$total_posts = count( $posts );

		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( $log_file, sprintf( 'Fixing timezone for the post %d/%d: %d', $index_from + $post_index + 1, $total_posts, $post['story_id'] ), Logger::LINE );

			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			if ( ! $wp_post_id ) {
				$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $post['story_id'] ), Logger::WARNING );
				continue;
			}
			$post_data = [
				'ID'        => $wp_post_id,
				'post_date' => $this->get_post_date_from_timestamp( $post['date_epoch'] ),
			];
			$result    = wp_update_post( $post_data );
			if ( is_wp_error( $result ) ) {
				$this->logger->log( $log_file, sprintf( 'Failed to fix date on post %d/%d: %d', $post_index + 1, $total_posts, $post['story_id'] ), Logger::ERROR );
			}
			$this->logger->log( $log_file, sprintf( 'Fixed date on post %d/%d: %d', $post_index + 1, $total_posts, $post['story_id'] ), Logger::LINE );
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-rearrange-categories".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_rearrange_categories( $args, $assoc_args ) {
		$story_csv_file_path          = $assoc_args['story-csv-file-path'];
		$story_sections_csv_file_path = $assoc_args['story-sections-file-path'];
		$index_from                   = isset( $assoc_args['index-from'] ) ? intval( $assoc_args['index-from'] ) : 0;
		$index_to                     = isset( $assoc_args['index-to'] ) ? intval( $assoc_args['index-to'] ) : -1;

		$posts       = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$sections    = $this->get_data_from_csv_or_tsv( $story_sections_csv_file_path );
		$section_ids = array_column( $sections, 'section_id' );

		// Get selected posts.
		if ( -1 !== $index_to ) {
			$posts = array_slice( $posts, $index_from, $index_to - $index_from + 1 );
		}

		foreach ( $posts as $post_index => $post ) {
			// Get the post.
			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			if ( ! $wp_post_id ) {
				$this->logger->log(
					self::LOG_FILE,
					sprintf( 'Entry not found %s.', $post['story_id'] ),
					$this->logger::WARNING
				);

				continue;
			}

			// Set categories from sections data.
			$imported_category  = '';
			$post_section_index = array_search( $post['section_id'], $section_ids );
			if ( false === $post_section_index ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find section %s for post %s', $post['section_id'], $post['headline'] ), Logger::WARNING );
			} else {
				$section = $sections[ $post_section_index ];

				if ( ! in_array( strtolower( $section['section'] ), self::ALLOWED_CATEGORIES ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Section %s is not allowed for post %s', $section['section'], $post['headline'] ), Logger::WARNING );
					// Create and set "General" as the post category.
					$category_id       = $this->get_or_create_category( 'General' );
					$imported_category = 'General';
				} else {
					$category_id       = $this->get_or_create_category( $section['section'] );
					$imported_category = $section['section'];
				}

				if ( $category_id ) {
					wp_set_post_categories( $wp_post_id, [ $category_id ] );
				}
			}

			$this->logger->log( self::LOG_FILE, sprintf( '(%d/%d) Post %d category: %s', $post_index + 1, count( $posts ), $wp_post_id, $imported_category ), Logger::SUCCESS );
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-fix-content-styling".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_fix_content_styling( $args, $assoc_args ) {
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
			]
		);

		$posts       = $query->get_posts();
		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			\WP_CLI::line( sprintf( 'Post %d/%d (%d)', $index + 1, $total_posts, $post->ID ) );

			$new_content = $this->migrate_text_styling( $post->post_content );

			if ( $new_content !== $post->post_content ) {
				wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $new_content,
					]
				);

				$this->logger->log( self::LOG_FILE, sprintf( 'Updated post %d with the ID %d', $index + 1, $post->ID ), Logger::SUCCESS );
			}
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-post-launch-qa".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_post_launch_qa( $args, $assoc_args ) {
		global $wpdb;

		$story_csv_file_paths           = $assoc_args['story-csv-file-paths'];
		$story_photos_csv_file_paths    = $assoc_args['story-photos-file-paths'];
		$story_media_csv_file_paths     = $assoc_args['story-media-file-paths'];
		$story_carousel_items_dir_paths = $assoc_args['story-carousel-items-dir-paths'];
		$story_report_items_dir_paths   = $assoc_args['story-report-items-dir-paths'];

		$stories = array_reduce(
			explode( ',', $story_csv_file_paths ),
			function ( $carry, $item ) {
				return array_merge( $carry, $this->get_data_from_csv_or_tsv( $item ) );
			},
			[]
		);

		$photos = array_reduce(
			explode( ',', $story_photos_csv_file_paths ),
			function ( $carry, $item ) {
				return array_merge( $carry, $this->get_data_from_csv_or_tsv( $item ) );
			},
			[]
		);

		$media = array_reduce(
			explode( ',', $story_media_csv_file_paths ),
			function ( $carry, $item ) {
				return array_merge( $carry, $this->get_data_from_csv_or_tsv( $item ) );
			},
			[]
		);

		$carousel_items = array_reduce(
			explode( ',', $story_carousel_items_dir_paths ),
			function ( $carry, $item ) {
				return array_merge( $carry, $this->get_data_from_csv_or_tsv( $item ) );
			},
			[]
		);

		$report_items = array_reduce(
			explode( ',', $story_report_items_dir_paths ),
			function ( $carry, $item ) {
				return array_merge( $carry, $this->get_data_from_csv_or_tsv( $item ) );
			},
			[]
		);

		// QA broken links.
		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%>http</a>%'"
		);

		$this->logger->log( self::LOG_FILE, sprintf( 'Found %d posts with broken links', count( $posts ) ), Logger::INFO );

		foreach ( $posts as $post ) {
			$story_id             = get_post_meta( $post->ID, self::EMBARCADERO_ORIGINAL_ID_META_KEY, true );
			$original_story_index = array_search( $story_id, array_column( $stories, 'story_id' ) );

			if ( false !== $original_story_index ) {
				$story = $stories[ $original_story_index ];
				// phpcs:ignore
				$story_text = str_replace( "\n", "</p>\n<p>", '<p>' . $story['story_text'] . '</p>' );

				$fixed_content = $this->migrate_post_content_shortcodes( $story_id, $post->ID, $story_text, $photos, '/tmp', $media, $carousel_items, false, $report_items );

				preg_match_all( '/>http<\/a>/', $fixed_content, $matches );

				if ( ! empty( $matches[0] ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not fix post with the ID %d for the broken links', $post->ID ), Logger::ERROR );
				} else {
					$this->logger->log( self::LOG_FILE, sprintf( 'Fixed post with the ID %d for the broken links', $post->ID ), Logger::SUCCESS );
					wp_update_post(
						[
							'ID'           => $post->ID,
							'post_content' => $fixed_content,
						]
					);
				}
			} else {
				$this->logger->log(
					self::LOG_FILE,
					sprintf( 'Could not find the story with the original ID %d for the post with the ID %d', $story_id, $post->ID ),
					Logger::WARNING
				);
			}
		}

		// QA Content Styling.
		$content_styling_shortcodes = [ '==I', '==B', '==BI', '==SH' ];

		// Get all the posts with the content styling shortcodes.
		foreach ( $content_styling_shortcodes as $shortcode ) {
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
					'%' . $shortcode . '%'
				)
			);

			$this->logger->log( self::LOG_FILE, sprintf( 'Found %d posts with the shortcode "%s"', count( $posts ), $shortcode ), Logger::INFO );

			foreach ( $posts as $post ) {
				$regex = '/(?<shortcode>(' . $shortcode . '\s+(.*?)==)|(' . $shortcode . '\s+(.*?)\n))/';
				preg_match_all( $regex, $post->post_content, $matches );

				if ( ! empty( $matches['shortcode'] ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Trying to fix post with the ID %d for the shortcode: %s', $post->ID, $shortcode ), Logger::INFO );
					$fixed_content = $this->migrate_text_styling( $post->post_content );

					preg_match_all( $regex, $fixed_content, $after_fix_matches );

					if ( ! empty( $after_fix_matches['shortcode'] ) ) {
						$matches_per_line = array_reduce(
							$after_fix_matches[0],
							function ( $carry, $item ) {
								return $carry . "\n" . $item;
							},
							''
						);
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not fix post with the ID %d for the shortcode "%s": %s', $post->ID, $shortcode, $matches_per_line ), Logger::ERROR );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Fixed post with the ID %d for the shortcode: %s', $post->ID, $shortcode ), Logger::SUCCESS );
						wp_update_post(
							[
								'ID'           => $post->ID,
								'post_content' => $fixed_content,
							]
						);
					}
				} else {
					// Probably a false positive.
					$highlighted_results = $this->highlight_text( $post->post_content, $shortcode );

					foreach ( $highlighted_results as $result ) {
						$this->logger->log( self::LOG_FILE, "Found a false positive for the shortcode '$shortcode' in the post with the ID {$post->ID}: $result", Logger::WARNING );
					}
				}
			}
		}

		// QA Content Shortcodes.
		$content_shortcodes = [ 'carousel', 'flour', 'map', 'more_stories', 'pull_quote', 'timeline', 'video' ];
		foreach ( $content_shortcodes as $shortcode ) {
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
					'%{' . $shortcode . '%'
				)
			);

			$this->logger->log( self::LOG_FILE, sprintf( 'Found %d posts with the shortcode "%s"', count( $posts ), $shortcode ), Logger::INFO );

			foreach ( $posts as $post ) {
				$regex = '/(?<shortcode>{(?<type>' . $shortcode . ')(\s+(?<width>(\d|\w)+)?)?(\s+(?<id>\d+)?)?})/';
				preg_match_all( $regex, $post->post_content, $matches );

				if ( ! empty( $matches['shortcode'] ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Trying to fix post with the ID %d for the shortcode: %s', $post->ID, $shortcode ), Logger::INFO );

					$story_id      = get_post_meta( $post->ID, self::EMBARCADERO_ORIGINAL_ID_META_KEY, true );
					$fixed_content = $this->migrate_media( $post->ID, $story_id, $post->post_content, $media, $photos, '/tmp', $carousel_items );

					preg_match_all( $regex, $fixed_content, $after_fix_matches );

					if ( ! empty( $after_fix_matches['shortcode'] ) ) {
						$matches_per_line = array_reduce(
							$after_fix_matches[0],
							function ( $carry, $item ) {
								return $carry . "\n" . $item;
							},
							''
						);
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not fix post with the ID %d for the shortcode "%s": %s', $post->ID, $shortcode, $matches_per_line ), Logger::ERROR );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Fixed post with the ID %d for the shortcode: %s', $post->ID, $shortcode ), Logger::SUCCESS );
						wp_update_post(
							[
								'ID'           => $post->ID,
								'post_content' => $fixed_content,
							]
						);
					}
				}
			}
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-pdfs".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_pdfs( $args, $assoc_args ) {
		global $wpdb;

		$story_report_items_dir_path = $assoc_args['story-report-items-dir-path'];
		$report_items                = $this->get_data_from_csv_or_tsv( $story_report_items_dir_path );

		// Posts with the "{pdf" shorcode.
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
				'%{pdf%'
			)
		);

		$this->logger->log( self::LOG_FILE, sprintf( 'Found %d posts with PDF shortcodes', count( $posts ) ), Logger::INFO );

		foreach ( $posts as $post ) {
			$content = $post->post_content;

			preg_match_all( '/(?<shortcode>{pdf?(\s+(?<id>\d+)?)?})/', $post->post_content, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				if ( isset( $match['id'] ) ) {
					$media_index = array_search( $match['id'], array_column( $report_items, 'report_id' ) );
					if ( false !== $media_index ) {
						$report = $report_items[ $media_index ];

						$media_content = "<a href='https://www.paloaltoonline.com/media/reports_pdf/{$report['seo_link']}'>{$report['report_title']}</a>";

						$content = str_replace( $match['shortcode'], $media_content, $content );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find report %s for the post %d', $match['id'], $post->ID ), Logger::WARNING );
					}
				} else {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not find report ID for the shortcode %s in the post %d', $match['shortcode'], $post->ID ), Logger::WARNING );
				}
			}

			if ( $content !== $post->post_content ) {
				wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $content,
					]
				);

				$this->logger->log( self::LOG_FILE, sprintf( 'Updated post %d with the ID %d', $post->ID, $post->ID ), Logger::SUCCESS );
			}
		}
	}

	/**
	 * Search for a string in a text and highlight the results.
	 *
	 * @param string $text Text to search in.
	 * @param string $search String to search for.
	 * @param int    $chars_to_show Number of characters to show before and after the search text.
	 *
	 * @return array Array of highlighted results.
	 */
	private function highlight_text( $text, $search, $chars_to_show = 10 ) {
		$position = 0;
		$results  = [];

		$search_length = strlen( $search );

		// Loop through all occurrences of the search text.
		while ( ( $position = strpos( $text, $search, $position ) ) !== false ) {
			// Calculate start position to show few chars before the search text.
			$start = $position - $chars_to_show;
			if ( $start < 0 ) {
				$start = 0;
			}

			// Calculate the length to show search text + few chars after.
			$length = $chars_to_show + $search_length + $chars_to_show;

			// Extract the substring.
			$extracted = substr( $text, $start, $length );

			// Highlight the search text.
			$color               = '%G';
			$colorized_shortcode = \cli\Colors::colorize( "$color$search%n" );
			$highlighted         = str_replace( $search, $colorized_shortcode, trim( preg_replace( '/\s+/', ' ', $extracted ) ) );

			// Save the result.
			$results[] = $highlighted;

			// Move position forward to find next occurrence.
			$position = $position + $search_length;
		}

		// Return highlighted results.
		return $results;
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

		$posts                 = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$media_list            = $this->get_data_from_csv_or_tsv( $story_media_csv_file_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_IMPORTED_MORE_POSTS_META_KEY );

		// Skip already imported posts.
		$posts = array_values(
			array_filter(
				$posts,
				function ( $post ) use ( $imported_original_ids ) {
					return ! in_array( $post['story_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Importing post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::LINE );

			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

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
					function ( $media_item ) use ( $post ) {
						return $media_item['story_id'] === $post['story_id'] && 'more_stories' === $media_item['media_type'];
					}
				)
			);

			if ( ! empty( $more_stories_media ) ) {
				$more_posts_blocks = [ $this->gutenberg_block_generator->get_heading( 'More stories' ) ];

				foreach ( $more_stories_media as $more_stories_item ) {
					$more_posts_blocks[] = $this->gutenberg_block_generator->get_paragraph( '<strong><a href="' . $more_stories_item['more_link'] . '">' . $more_stories_item['more_headline'] . '</a></strong><br>' . $more_stories_item['more_blurb'] );
				}

				$more_posts_block_group = $this->gutenberg_block_generator->get_group_constrained( $more_posts_blocks, [ 'more-stories', 'alignright' ], [ 'align' => 'right' ] );

				preg_match( '/(?<shortcode>{(?<type>more_stories)(\s+(?<width>(\d|\w)+)?)?(\s+(?<id>\d+)?)?})/', $wp_post->post_content, $match );

				if ( array_key_exists( 'shortcode', $match ) ) {
					$content = str_replace( $match['shortcode'], serialize_block( $more_posts_block_group ), $wp_post->post_content );

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

			update_post_meta( $wp_post_id, self::EMBARCADERO_IMPORTED_MORE_POSTS_META_KEY, $post['story_id'] );
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-fix-more-posts-block".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_fix_more_posts_block( $args, $assoc_args ) {
		$story_csv_file_path       = $assoc_args['story-csv-file-path'];
		$story_media_csv_file_path = $assoc_args['story-media-file-path'];

		$posts                 = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$media_list            = $this->get_data_from_csv_or_tsv( $story_media_csv_file_path );
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_IMPORTED_MORE_POSTS_META_KEY );

		// Get only posts with already migrated more posts block.
		$posts = array_values(
			array_filter(
				$posts,
				function ( $post ) use ( $imported_original_ids ) {
					return in_array( $post['story_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $posts as $post ) {
			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

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
					function ( $media_item ) use ( $post ) {
						return $media_item['story_id'] === $post['story_id'] && 'more_stories' === $media_item['media_type'];
					}
				)
			);

			if ( ! empty( $more_stories_media ) ) {
				$content_blocks    = parse_blocks( $wp_post->post_content );
				$more_posts_blocks = [ $this->gutenberg_block_generator->get_heading( 'More stories' ) ];

				foreach ( $more_stories_media as $more_stories_item ) {
					$more_posts_blocks[] = $this->gutenberg_block_generator->get_paragraph( '<strong><a href="' . $more_stories_item['more_link'] . '">' . $more_stories_item['more_headline'] . '</a></strong><br>' . $more_stories_item['more_blurb'] );
				}

				$more_posts_block_group = $this->gutenberg_block_generator->get_group_constrained( $more_posts_blocks, [ 'more-stories', 'alignright' ], [ 'align' => 'right' ] );

				// Check if the post contains more than one "More stories" block.
				if ( 1 < substr_count( $wp_post->post_content, '>More stories<' ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Post %d with the ID %d contains more than one "More stories" block.', $post['story_id'], $wp_post_id ), Logger::WARNING );
					continue;
				}

				// Find the 'More stories' heading block index.
				$more_stories_heading_block_index = array_search(
					$this->gutenberg_block_generator->get_heading( 'More stories' )['innerHTML'],
					array_column( $content_blocks, 'innerHTML' ),
					true
				);

				// Check if the post contains the "More stories" heading block.
				if ( false === $more_stories_heading_block_index ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Post %d with the ID %d does not contain the "More stories" heading block.', $post['story_id'], $wp_post_id ), Logger::WARNING );
					continue;
				}

				$indexes_to_remove = [ $more_stories_heading_block_index ];

				$blocks_count = count( $content_blocks );
				for ( $i = $more_stories_heading_block_index + 1; $i < $blocks_count; $i++ ) {
					if (
						'core/paragraph' === $content_blocks[ $i ]['blockName']
						&& str_starts_with( $content_blocks[ $i ]['innerHTML'], '<p><strong><a href="' )
						) {
						$indexes_to_remove[] = $i;
					} else {
						break;
					}
				}

				// Delete the blocks.
				foreach ( $indexes_to_remove as $index_to_remove ) {
					unset( $content_blocks[ $index_to_remove ] );
				}

				// Insert the new block.
				array_splice( $content_blocks, $more_stories_heading_block_index, 0, [ $more_posts_block_group ] );

				wp_update_post(
					[
						'ID'           => $wp_post_id,
						'post_content' => serialize_blocks( $content_blocks ),
					]
				);

				$this->logger->log( self::LOG_FILE, sprintf( 'Updated post %d with the ID %d with more posts block.', $post['story_id'], $wp_post_id ), Logger::SUCCESS );
			} else {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find more_stories %s for the post %d', $post['story_id'], $wp_post_id ), Logger::WARNING );
			}
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-timeline-block".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_timeline_block( $args, $assoc_args ) {
		$story_csv_file_path       = $assoc_args['story-csv-file-path'];
		$story_media_csv_file_path = $assoc_args['story-media-file-path'];

		$posts      = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$media_list = $this->get_data_from_csv_or_tsv( $story_media_csv_file_path );

		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Importing timeline for the post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::LINE );

			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			if ( ! $wp_post_id ) {
				$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $post['story_id'] ), Logger::WARNING );
				continue;
			}

			$wp_post = get_post( $wp_post_id );

			if ( ! str_contains( $post['story_text'], '{timeline' ) ) {
				continue;
			}

			// Timeline can be in the content in the format {timeline 40 25877}
			// where 40 is the percentage of the column and 25877 is the media ID.
			$content = $wp_post->post_content;
			preg_match_all( '/(?<shortcode>{timeline (?<width>(\d|\w)+) (?<id>\d+)})/', $content, $matches, PREG_SET_ORDER );

			foreach ( $matches as $match ) {
				$media_index = array_search( $match['id'], array_column( $media_list, 'media_id' ) );
				if ( false !== $media_index ) {
					$timeline_media = $media_list[ $media_index ];

					if ( ! str_starts_with( $timeline_media['media_link'], '<iframe' ) ) {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find iframe code for the post %d', $wp_post_id ), Logger::WARNING );
						continue;
					}

					$photo_block_html = serialize_block( $this->gutenberg_block_generator->get_html( $timeline_media['media_link'] ) );

					$content = str_replace( $match['shortcode'], $photo_block_html, $content );
				} else {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not find media for the timeline %s for the post %d', $match['id'], $wp_post_id ), Logger::WARNING );
					continue;
				}
			}

			if ( $content !== $wp_post->post_content ) {
				$content = str_replace( '<p><!-- wp:html -->', '<!-- wp:html -->', $content );
				$content = str_replace( '<!-- /wp:html --></p>', '<!-- /wp:html -->', $content );

				wp_update_post(
					[
						'ID'           => $wp_post_id,
						'post_content' => $content,
					]
				);

				$this->logger->log( self::LOG_FILE, sprintf( 'Updated post %d with the ID %d with more posts block.', $post['story_id'], $wp_post_id ), Logger::SUCCESS );
			}
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-images".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_images( $args, $assoc_args ) {
		$story_csv_file_path        = $assoc_args['story-csv-file-path'];
		$story_photos_csv_file_path = $assoc_args['story-photos-file-path'];
		$story_photos_dir_path      = $assoc_args['story-photos-dir-path'];

		$posts  = $this->get_data_from_csv_or_tsv( $story_csv_file_path );
		$photos = $this->get_data_from_csv_or_tsv( $story_photos_csv_file_path );

		foreach ( $posts as $post_index => $post ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Migrating images for the post %d/%d: %d', $post_index + 1, count( $posts ), $post['story_id'] ), Logger::LINE );

			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $post['story_id'] );

			if ( ! $wp_post_id ) {
				$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $post['story_id'] ), Logger::WARNING );
				continue;
			}

			$wp_post = get_post( $wp_post_id );

			if ( ! str_contains( $wp_post->post_content, '{photo' ) ) {
				continue;
			}

			$updated_content = $this->migrate_photos( $wp_post_id, $wp_post->post_content, $photos, $story_photos_dir_path );

			if ( $updated_content !== $wp_post->post_content ) {
				wp_update_post(
					[
						'ID'           => $wp_post_id,
						'post_content' => $updated_content,
					]
				);

				$this->logger->log( self::LOG_FILE, sprintf( 'Updated post %d with the ID %d with more posts block.', $post['story_id'], $wp_post_id ), Logger::SUCCESS );
			}
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-comments".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_comments( $args, $assoc_args ) {
		$comments_csv_file_path       = $assoc_args['comments-csv-file-path'];
		$comments_zones_csv_file_path = $assoc_args['comments-zones-file-path'];
		$users_file_path              = $assoc_args['users-file-path'];
		$imported_original_ids        = $this->get_comments_meta_values_by_key( self::EMBARCADERO_IMPORTED_COMMENT_META_KEY );

		$comments = $this->get_data_from_csv_or_tsv( $comments_csv_file_path );
		$zones    = $this->get_data_from_csv_or_tsv( $comments_zones_csv_file_path );
		$users    = $this->get_data_from_csv_or_tsv( $users_file_path );

		// Skip already imported comments.
		$comments = array_values(
			array_filter(
				$comments,
				function ( $comment ) use ( $imported_original_ids ) {
					return ! in_array( $comment['comment_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $comments as $comment_index => $comment ) {
			if ( empty( $comment['comment'] ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Skipping empty comment for the post %d/%d: %d', $comment_index + 1, count( $comments ), $comment['topic_id'] ), Logger::LINE );
				continue;
			}

			$this->logger->log( self::LOG_FILE, sprintf( 'Migrating comment for the post %d/%d: %d', $comment_index + 1, count( $comments ), $comment['topic_id'] ), Logger::LINE );

			$wp_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_TOPIC_ID_META_KEY, $comment['topic_id'] );

			if ( ! $wp_post_id ) {
				$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $comment['topic_id'] ), Logger::WARNING );
				continue;
			}

			// Get or create subscriber user.
			$user_index = array_search( $comment['user_id'], array_column( $users, 'user_id' ) );
			if ( false === $user_index ) {
				// Will skip providing user data.
				$wp_user = null;
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find user %s for the comment %d', $comment['user_id'], $comment['comment_id'] ), Logger::WARNING );
			} else {
				// Get WP_User object.
				$raw_user = $users[ $user_index ];

				// Update default email.
				if ( 'blank' == $raw_user['email'] ) {
					// Using "@newspack.com" for security reasons (a valid domain not owned by us or the Publisher could emulate this email).
					$raw_user['email'] = uniqid() . '@newspack.com';
				}

				$wp_user_id = $this->get_or_create_user( $raw_user['user_name'], $raw_user['email'], 'subscriber' );

				if ( is_wp_error( $wp_user_id ) ) {
					$wp_user    = null;
					$wp_user_id = null;
					if ( $wp_user_id ) {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not get or create contributor %s: %s', $contributor['full_name'] ?? 'na/', $wp_user_id->get_error_message() ), Logger::WARNING );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not get or create contributor %s: %s', $contributor['full_name'] ?? 'na/', 'unknown error' ), Logger::WARNING );
					}
				} else {
					$wp_user = get_user_by( 'id', $wp_user_id );
				}
			}

			$comment_data = [
				'comment_post_ID'      => $wp_post_id,
				'comment_approved'     => 'no' === $comment['hide'],
				'user_id'              => $wp_user ? $wp_user->ID : '',
				'comment_author'       => $wp_user ? $wp_user->user_nicename : $comment['user_name'],
				'comment_author_email' => $wp_user ? $wp_user->user_email : '',
				'comment_author_url'   => $wp_user ? $wp_user->user_url : '',
				'comment_author_IP'    => $comment['ip_address'] ?? '',
				'comment_content'      => $comment['comment'] ?? '',
				'comment_date'         => $this->get_post_date_from_timestamp( $comment['posted_epoch'] ),
				'comment_meta'         => [],
			];

			// Get user zone.
			$zone_index = array_search( $comment['zone_id'], array_column( $zones, 'zone_id' ) );
			if ( false !== $zone_index ) {
				$comment_data['comment_meta']['zone_name'] = $zones[ $zone_index ]['name'];
				$comment_data['comment_meta']['zone_type'] = $zones[ $zone_index ]['type'];
			}

			$comment_id = wp_insert_comment( $comment_data );

			if ( is_wp_error( $comment_id ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not create comment %s: %s', $comment['comment_id'], $comment_id->get_error_message() ), Logger::WARNING );
				continue;
			}

			update_comment_meta( $comment_id, self::EMBARCADERO_IMPORTED_COMMENT_META_KEY, $comment['comment_id'] );

			$this->logger->log( self::LOG_FILE, sprintf( 'Created comment %d with the ID %d', $comment['comment_id'], $comment_id ), Logger::SUCCESS );
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-print-issues".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_print_issues( $args, $assoc_args ) {
		$publication_name             = $assoc_args['publication-name'];
		$publication_email            = $assoc_args['publication-email'];
		$pdf_section_suffix           = $assoc_args['pdf-section-suffix'];
		$print_issues_csv_file_path   = $assoc_args['print-issues-csv-file-path'];
		$print_sections_csv_file_path = $assoc_args['print-sections-csv-file-path'];
		$print_pdf_dir_path           = $assoc_args['print-pdf-dir-path'];
		$print_cover_dir_path         = $assoc_args['print-cover-dir-path'];

		$print_issues   = $this->get_data_from_csv_or_tsv( $print_issues_csv_file_path );
		$print_sections = $this->get_data_from_csv_or_tsv( $print_sections_csv_file_path );

		foreach ( $print_issues as $print_issue_index => $print_issue ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Migrating print issue %d/%d: %d (%s)', $print_issue_index + 1, count( $print_issues ), $print_issue['issue_number'], $print_issue['seo_link'] ), Logger::LINE );

			// Get PDF file path from $print_issue['seo_link'].
			// seo_link is in the format yyyy/mm/dd.
			// The PDF file path is in the format $print_pdf_dir_path . '/' . $year . '/yyyy_mm_dd.' . $pdf_section_suffix. '.section' . $i . '.pdf'
			$seo_link = explode( '/', $print_issue['seo_link'] );
			$year     = $seo_link[0];
			$month    = $seo_link[1];
			$day      = $seo_link[2];

			// Check if the year is correct.
			if ( ! is_numeric( $year ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not get year from seo_link %s', $print_issue['seo_link'] ), Logger::WARNING );
				continue;
			}

			$pdf_files_paths = [];

			for ( $i = 1; $i <= 12; $i++ ) {
				$pdf_file_path = $print_pdf_dir_path . '/' . $year . '/' . $year . '_' . $month . '_' . $day . '.' . $pdf_section_suffix . '.section' . $i . '.pdf';

				if ( ! file_exists( $pdf_file_path ) ) {
					break;
				}

				$pdf_files_paths[] = $pdf_file_path;
			}

			if ( empty( $pdf_files_paths ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find PDF files for issue %s', $print_issue['issue_number'] ), Logger::WARNING );
				continue;
			}

			// Get author based on the publication name.
			$author_id = $this->get_or_create_user( $publication_name, $publication_email, 'editor' );

			// Create a new issue post.
			$wp_issue_post_id = $this->get_or_create_post(
				$print_issue['issue_number'],
				self::EMBARCADERO_IMPORTED_PRINT_ISSUE_META_KEY,
				[
					'post_type'    => 'post',
					'post_title'   => $print_issue['seo_link'],
					'post_status'  => 'publish',
					'post_author'  => $author_id,
					'post_date'    => "$year-$month-$day",
					'post_content' => '',
				]
			);

			if ( is_wp_error( $wp_issue_post_id ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not create issue %s: %s', $print_issue['issue_number'], $wp_issue_post_id->get_error_message() ), Logger::WARNING );
				continue;
			}

			// Issue section.
			$section_name  = 'Section ' . $print_issue['sections'];
			$section_index = array_search( $print_issue['sections'], array_column( $print_sections, 'section_id' ) );
			if ( false === $section_index ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find section %s for issue %s', $print_issue['sections'], $print_issue['issue_number'] ), Logger::WARNING );
			} else {
				$section_name = $print_sections[ $section_index ]['section_title'];
			}

			$post_content_blocks = [];
			foreach ( $pdf_files_paths as $pdf_file_path ) {
				// Upload file.
				$file_post_id = $this->attachments->import_external_file( $pdf_file_path, null, null, null, null, $wp_issue_post_id );

				if ( is_wp_error( $file_post_id ) ) {
					wp_delete_post( $wp_issue_post_id, true );
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not upload file %s: %s', $pdf_file_path, $file_post_id->get_error_message() ), Logger::WARNING );
					continue;
				}

				$attachment_post = get_post( $file_post_id );

				$post_content_blocks[] = $this->gutenberg_block_generator->get_file_pdf( $attachment_post, $section_name );
			}

			$post_content = serialize_blocks( $post_content_blocks );

			wp_update_post(
				[
					'ID'           => $wp_issue_post_id,
					'post_content' => $post_content,
				]
			);

			// Handle post cover.
			$cover_file_path = $print_cover_dir_path . '/' . $seo_link[0] . '/' . $seo_link[0] . '_' . $seo_link[1] . '_' . $seo_link[2] . '.cover.jpg';

			if ( ! is_file( $cover_file_path ) ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Could not find cover file %s', $cover_file_path ), Logger::WARNING );
			} else {
				$cover_file_post_id = $this->attachments->import_external_file( $cover_file_path, null, null, null, null, $wp_issue_post_id );

				if ( is_wp_error( $cover_file_post_id ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Could not upload cover file %s: %s', $cover_file_path, $cover_file_post_id->get_error_message() ), Logger::WARNING );
					continue;
				} else {
					update_post_meta( $wp_issue_post_id, '_thumbnail_id', $cover_file_post_id );
					update_post_meta( $wp_issue_post_id, 'newspack_featured_image_position', 'hidden' );
				}
			}

			// Set post category as Print Edition > YYYY.
			$print_edition_category_id = $this->get_or_create_category( 'Print Edition' );
			$year_category_id          = $this->get_or_create_category( $year, $print_edition_category_id );

			wp_set_post_categories( $wp_issue_post_id, [ $print_edition_category_id, $year_category_id ] );

			update_post_meta( $wp_issue_post_id, self::EMBARCADERO_IMPORTED_PRINT_ISSUE_META_KEY, $print_issue['issue_number'] );

			$this->logger->log( self::LOG_FILE, sprintf( 'Created post issue %d with the ID %d', $print_issue['issue_number'], $wp_issue_post_id ), Logger::SUCCESS );
		}
	}

	/**
	 * Callable for "newspack-content-migrator embarcadero-migrate-post-slugs".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_embarcadero_migrate_post_slugs( $args, $assoc_args ) {
		$story_csv_file_path   = $assoc_args['story-csv-file-path'];
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::EMBARCADERO_MIGRATED_POST_SLUG_META_KEY );

		$stories = $this->get_data_from_csv_or_tsv( $story_csv_file_path );

		// Skip already fixed stories.
		$stories = array_values(
			array_filter(
				$stories,
				function ( $story ) use ( $imported_original_ids ) {
					return ! in_array( $story['story_id'], $imported_original_ids );
				}
			)
		);

		foreach ( $stories as $story_index => $story ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Fixing story slug %d/%d: %d', $story_index + 1, count( $stories ), $story['story_id'] ), Logger::LINE );

			$wp_issue_post_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_ID_META_KEY, $story['story_id'] );

			if ( ! $wp_issue_post_id ) {
				$this->logger->log( self::TAGS_LOG_FILE, sprintf( 'Could not find post with the original ID %d', $story['story_id'] ), Logger::WARNING );
				continue;
			}

			// Update post slug.
			$seo_link = explode( '/', $story['seo_link'] );
			$slug     = trim( $seo_link[ count( $seo_link ) - 1 ], "-/\t\n\r\0\x0B" );

			// Get current post slug.
			$current_post = get_post( $wp_issue_post_id );

			if ( $current_post->post_name === $slug ) {
				$this->logger->log( self::LOG_FILE, sprintf( 'Post %d with the ID %d already has the correct slug %s', $story['story_id'], $wp_issue_post_id, $slug ), Logger::LINE );
				continue;
			}

			wp_update_post(
				[
					'ID'        => $wp_issue_post_id,
					'post_name' => $slug,
				]
			);

			update_post_meta( $wp_issue_post_id, self::EMBARCADERO_MIGRATED_POST_SLUG_META_KEY, $story['story_id'] );

			$this->logger->log( self::LOG_FILE, sprintf( 'Updated post %d with the ID %d with the slug %s', $story['story_id'], $wp_issue_post_id, $slug ), Logger::SUCCESS );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator embarcadero-helper-fix-tsv-file`.
	 *
	 * Takes a TSV file and tries to fix the ambiguous \"" and \" escaping and produces a new, fixed TSV file.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_embarcadero_helper_fix_tsv_file( $pos_args, $assoc_args ) {
		$file_input  = $assoc_args['tsv-file-input'];
		$file_output = $assoc_args['tsv-file-output'];

		if ( ! file_exists( $file_input ) ) {
			WP_CLI::error( 'File does not exist: ' . $file_input );
		}
		$tsv_file = fopen( $file_input, 'r' );
		if ( false === $tsv_file ) {
			WP_CLI::error( 'Could not open file: ' . $file_input );
		}
		$tsv_headers = fgetcsv( $tsv_file, null, "\t" );
		if ( false === $tsv_headers ) {
			WP_CLI::error( 'Could not read TSV headers from file: ' . $file_input );
		}

		// We'll work on a temp file until fixed, then we mv it to the output file.
		$file_tmp = $file_output . '.tmp';
		copy( $file_input, $file_tmp );

		$fixes_made = false;
		$faulty_row = null;
		do {
			$fixed = true;

			$tsv_file    = fopen( $file_tmp, 'r' );
			$tsv_array   = fgetcsv( $tsv_file, null, "\t" );
			$tsv_headers = array_map( 'trim', $tsv_array );

			// Read TSV until a faulty row is found, and then set $fixed to false and stop.
			while (
				( ( $tsv_row = fgetcsv( $tsv_file, null, "\t" ) ) !== false )
				&& ( false !== $fixed )
			) {
				if ( count( $tsv_row ) !== count( $tsv_headers ) ) {
					$faulty_row = current( $tsv_row );
					WP_CLI::warning( 'Can not read TSV row ID ' . $faulty_row );
					$fixed = false;
					continue;
				}
			}

			/**
			 * It failed for the first time (), then try and replace the faulty row with a fixed one.
			 */
			if ( false === $fixed ) {

				$next_row = $faulty_row + 1;

				$from = "\n{$faulty_row}\t";
				$to   = "\n{$next_row}\t";

				// Get faulty row.
				$full_file_contents = file_get_contents( $file_tmp );
				$pos_from           = strpos( $full_file_contents, $from );
				$pos_to             = strpos( $full_file_contents, $to );
				if ( ! $pos_from ) {
					// This shouldn't be, but check just in case.
					WP_CLI::error( sprintf( 'Was not able to match \'%s\' string position in TSV file.', $from ) );
				}
				// And if $to is not found, do replacements in file until the end of content ($pos_to=null will be used as $length argument for substr down below).
				if ( false === $pos_to ) {
					$pos_to = null;
				}

				WP_CLI::line( sprintf( 'Fixing quotes from %d to %s ...', $faulty_row, $next_row ?? 'end' ) );
				$offending_record          = substr( $full_file_contents, $pos_from, $pos_to - $pos_from + 1 );
				$offending_record_replaced = str_replace( '\""', '\"', $offending_record );
				$full_file_contents        = str_replace( $offending_record, $offending_record_replaced, $full_file_contents );
				file_put_contents( $file_tmp, $full_file_contents );

				$fixes_made = true;
			}
		} while ( true !== $fixed );

		fclose( $tsv_file );

		// Move file from $file_tmp to $file_output.
		if ( true === $fixes_made ) {
			rename( $file_tmp, $file_output );
			WP_CLI::success( 'Fixed file saved to: ' . $file_output );
		}
	}

	public function cmd_embarcadero_helper_validate_csv_file( $pos_args, $assoc_args ) {
		$file_input = $assoc_args['csv-file-input'];

		if ( ! file_exists( $file_input ) ) {
			WP_CLI::error( 'File does not exist: ' . $file_input );
		}
		$csv_file = fopen( $file_input, 'r' );
		if ( false === $csv_file ) {
			WP_CLI::error( 'Could not open file: ' . $file_input );
		}
		$csv_headers = fgetcsv( $csv_file );
		if ( false === $csv_headers ) {
			WP_CLI::error( 'Could not read TSV headers from file: ' . $file_input );
		}

		do {
			$fixed = true;

			$tsv_array   = fgetcsv( $csv_file );
			$csv_headers = array_map( 'trim', $tsv_array );

			$wrong_rows = 0;

			// Read TSV until a faulty row is found, and then set $fixed to false and stop.
			while (
				( ( $tsv_row = fgetcsv( $csv_file ) ) !== false )
			) {
				if ( count( $tsv_row ) !== count( $csv_headers ) ) {
					WP_CLI::warning( sprintf( 'Can not read CSV row beginning with >>> %s <<<', substr( $tsv_row[0], 0, 50 ) ) );
					++$wrong_rows;
				}
			}
		} while ( true !== $fixed );

		fclose( $csv_file );

		if ( $wrong_rows > 0 ) {
			WP_CLI::error( sprintf( 'Detected %d wrong rows. Fix them before continuing.', $wrong_rows ) );
		} else {
			WP_CLI::success( 'No wrong rows detected.' );
		}
	}

	/**
	 * This command will process the XML export files form Six Fifty and import the content into the site where
	 * this is executed.
	 *
	 * @param array $args Positional argumemnts.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws WP_CLI\ExitException When the command fails.
	 */
	public function cmd_migrate_six_fifty_content( $args, $assoc_args ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$media_xml_path = $assoc_args['media-xml-path'];
		$posts_xml_path = $assoc_args['posts-xml-path'];
		$xml            = new DOMDocument();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml->loadXML( file_get_contents( $media_xml_path ), LIBXML_PARSEHUGE | LIBXML_BIGLINES );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		$media_channel_children = $rss->childNodes->item( 1 )->childNodes;

		$posts   = [];
		$authors = get_users( [ 'role__in' => [ 'administrator', 'editor', 'author', 'contributor' ] ] );
		foreach ( $authors as $key => $author ) {
			$authors[ $author->user_login ] = $author;
			$modded_user_login              = strtolower( substr( $author->first_name, 0, 1 ) . $author->last_name );
			$authors[ $modded_user_login ]  = $author;
			unset( $authors[ $key ] );
		}
		$attachments = [];

		WP_CLI::line( 'Processing Media XML items' );
		foreach ( $media_channel_children as $child ) {
			// Process only the authors first.
			if ( 'wp:author' === $child->nodeName ) {
				$author = WordPressXMLHandler::get_or_create_author( $child );

				if ( ! array_key_exists( $author->user_login, $authors ) ) {
					$authors[ $author->user_login ] = $author;
				}
			}
		}
		WP_CLI::line( 'Got authors...' );

		foreach ( $media_channel_children as $child ) {
			if ( 'item' === $child->nodeName ) {
				WP_CLI::line( 'Processing item...' );
				$data = WordPressXMLHandler::get_parsed_data( $child, $authors );
				if ( isset( $data['post'] ) ) {
					$old_post_id = $data['post']['ID'];
					unset( $data['post']['ID'] );
					unset( $data['post']['post_parent'] );
					$result = wp_insert_attachment( $data['post'] );

					if ( is_wp_error( $result ) ) {
						WP_CLI::warning( sprintf( 'Could not import attachment %d: %s', $old_post_id, $result->get_error_message() ) );
						continue;
					}

					$attachments[ $old_post_id ] = $result;

					$this->process_meta(
						$result,
						array_map(
							function ( $meta ) {

								if ( '_wp_attachment_metadata' === $meta['meta_key'] ) {
									// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
									$meta['meta_value'] = maybe_unserialize( $meta['meta_value'] );
								}

								return $meta;
							},
							$data['post']['meta']
						)
					);

					$this->process_six_fifty_categories_and_tags( $result, $data['categories'], $data['tags'] );
				}
			}
		}

		for ( $i = 0; $i < 5; $i++ ) {
			echo "\n";
		}

		$xml = new DOMDocument();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml->loadXML( file_get_contents( $posts_xml_path ), LIBXML_PARSEHUGE | LIBXML_BIGLINES );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		$posts_channel_children = $rss->childNodes->item( 1 )->childNodes;

		WP_CLI::line( 'Processing Post XML items' );
		foreach ( $posts_channel_children as $child ) {
			// Process only the authors first.
			if ( 'wp:author' === $child->nodeName ) {
				$author = WordPressXMLHandler::get_or_create_author( $child );

				if ( ! array_key_exists( $author->user_login, $authors ) ) {
					$authors[ $author->user_login ] = $author;
				}
			}
		}
		WP_CLI::line( 'Got second set of authors...' );

		$six_fifty_tag_exists = tag_exists( 'The Six Fifty' );
		$six_fifty_tag_id     = null;
		if ( null !== $six_fifty_tag_exists ) {
			$six_fifty_tag_id = (int) $six_fifty_tag_exists['term_id'];
		} else {
			$six_fifty_tag_id = (int) wp_insert_term( 'The Six Fifty', 'post_tag' )['term_id'];
		}

		foreach ( $media_channel_children as $child ) {
			if ( 'item' === $child->nodeName ) {
				WP_CLI::line( 'Processing item...' );
				$data = WordPressXMLHandler::get_parsed_data( $child, $authors );
				WP_CLI::line( sprintf( 'Old ID: %d, Post Name: %s', $data['post']['ID'], $data['post']['post_name'] ) );

				if ( isset( $data['post'] ) ) {
					$old_post_id = $data['post']['ID'];
					unset( $data['post']['ID'] );

					if ( isset( $data['post']['post_parent'] ) && array_key_exists( $data['post']['post_parent'], $posts ) ) {
						$data['post']['post_parent'] = $posts[ $data['post']['post_parent'] ];
					} else {
						unset( $data['post']['post_parent'] );
					}

					$result = wp_insert_post( $data['post'] );

					if ( is_wp_error( $result ) ) {
						WP_CLI::warning( sprintf( 'Could not import post %d: %s', $old_post_id, $result->get_error_message() ) );
						continue;
					}

					$posts[ $old_post_id ] = $result;

					$this->process_meta(
						$result,
						array_map(
							function ( $meta ) use ( $attachments ) {

								if ( '_thumbnail_id' === $meta['meta_key'] ) {
									if ( array_key_exists( $meta['meta_value'], $attachments ) ) {
										// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
										$meta['meta_value'] = $attachments[ $meta['meta_value'] ];
									}
								}

								return $meta;
							},
							$data['post']['meta']
						)
					);

					$this->process_six_fifty_categories_and_tags( $result, $data['categories'], $data['tags'] );
					if ( null !== $six_fifty_tag_id ) {
						wp_set_post_tags( $result, [ $six_fifty_tag_id ], true );
					}
				}
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * This command addresses a bug that was introduced in the Six Fifty migration where the authors were not
	 * properly set for the posts.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws WP_CLI\ExitException Halts if an author is not properly found.
	 */
	public function cmd_fix_six_fifty_missing_authors( $args, $assoc_args ) {
		$six_fifty_tag_exists = tag_exists( 'The Six Fifty' );
		$six_fifty_tag_id     = null;
		if ( null !== $six_fifty_tag_exists ) {
			$six_fifty_tag_id = (int) $six_fifty_tag_exists['term_id'];
		} else {
			WP_CLI::error( 'The Six Fifty tag was not found.' );
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$media_xml_path = $assoc_args['media-xml-path'];
		$posts_xml_path = $assoc_args['posts-xml-path'];
		$xml            = new DOMDocument();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml->loadXML( file_get_contents( $media_xml_path ), LIBXML_PARSEHUGE | LIBXML_BIGLINES );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		$media_channel_children = $rss->childNodes->item( 1 )->childNodes;

		$authors = get_users( [ 'role__in' => [ 'administrator', 'editor', 'author', 'contributor', 'site_editor' ] ] );
		foreach ( $authors as $key => $author ) {
			$authors[ $author->user_login ] = $author;
			$modded_user_login              = strtolower( substr( $author->first_name, 0, 1 ) . $author->last_name );
			$authors[ $modded_user_login ]  = $author;
			unset( $authors[ $key ] );
		}

		WP_CLI::line( 'Processing Media XML items' );
		foreach ( $media_channel_children as $child ) {
			// Process only the authors first.
			if ( 'wp:author' === $child->nodeName ) {
				$author = WordPressXMLHandler::get_or_create_author( $child );

				if ( ! array_key_exists( $author->user_login, $authors ) ) {
					$authors[ $author->user_login ] = $author;
				}
			}
		}
		WP_CLI::line( 'Got authors...' );

		$xml = new DOMDocument();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$xml->loadXML( file_get_contents( $posts_xml_path ), LIBXML_PARSEHUGE | LIBXML_BIGLINES );

		$rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

		$posts_channel_children = $rss->childNodes->item( 1 )->childNodes;

		WP_CLI::line( 'Processing Post XML items' );
		foreach ( $posts_channel_children as $child ) {
			// Process only the authors first.
			if ( 'wp:author' === $child->nodeName ) {
				$author = WordPressXMLHandler::get_or_create_author( $child );

				if ( ! array_key_exists( $author->user_login, $authors ) ) {
					$authors[ $author->user_login ] = $author;
				}
			}
		}
		WP_CLI::line( 'Got second set of authors...' );

		global $wpdb;

		foreach ( $media_channel_children as $child ) {
			if ( 'item' === $child->nodeName ) {
				echo "\n\n";
				WP_CLI::line( 'Processing item...' );
				$data = WordPressXMLHandler::get_parsed_data( $child, $authors )['post'];
				WP_CLI::line( sprintf( 'Post %s ( OLD ID: %d )', $data['post_name'], $data['ID'] ) );

				if ( 0 === $data['post_author'] ) {
					WP_CLI::warning( 'Post does not have an author' );
					WP_CLI::halt( 1 );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM $wpdb->posts p
         				WHERE p.post_title = %s AND p.post_date = %s AND p.post_name LIKE %s",
						$data['post_title'],
						$data['post_date'],
						$wpdb->esc_like( $data['post_name'] ) . '%',
					)
				);

				if ( ! $post ) {
					WP_CLI::warning( sprintf( 'Could not find post with the name %s', $data['post_name'] ) );
					continue;
				}

				if ( 0 !== intval( $post->post_author ) ) {
					WP_CLI::warning( 'Post already has an author' );
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$wpdb->posts,
					[
						'post_author' => $data['post_author'],
					],
					[
						'ID' => $post->ID,
					]
				);

				if ( $result ) {
					WP_CLI::success( 'Updated' );
				} else {
					WP_CLI::line( 'NOT UPDATED' );
				}
			}
		}

		foreach ( $posts_channel_children as $child ) {
			if ( 'item' === $child->nodeName ) {
				echo "\n\n";
				WP_CLI::line( 'Processing item...' );
				$data = WordPressXMLHandler::get_parsed_data( $child, $authors )['post'];
				WP_CLI::line( sprintf( 'Post %s ( OLD ID: %d )', $data['post_name'], $data['ID'] ) );

				if ( 0 === $data['post_author'] ) {
					WP_CLI::warning( 'Post does not have an author' );
					WP_CLI::halt( 1 );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM $wpdb->posts p
    						INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id
         				WHERE p.post_title = %s
         				  AND p.post_date = %s
         				  AND p.post_name LIKE %s
         				  AND tr.term_taxonomy_id = %d",
						$data['post_title'],
						$data['post_date'],
						$wpdb->esc_like( $data['post_name'] ) . '%',
						$six_fifty_tag_id
					)
				);

				if ( ! $post ) {
					WP_CLI::warning( sprintf( 'Could not find post with the name %s', $data['post_name'] ) );
					continue;
				}

				if ( 0 !== intval( $post->post_author ) ) {
					WP_CLI::warning( 'Post already has an author' );
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update(
					$wpdb->posts,
					[
						'post_author' => $data['post_author'],
					],
					[
						'ID' => $post->ID,
					]
				);

				if ( $result ) {
					WP_CLI::success( 'Updated' );
				} else {
					WP_CLI::line( 'NOT UPDATED' );
				}
			}
		}
	}

	/**
	 * Function to save a post's meta data.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $meta The meta data.
	 *
	 * @return void
	 */
	private function process_meta( int $post_id, array $meta ) {
		foreach ( $meta as $data ) {
			update_post_meta( $post_id, $data['meta_key'], $data['meta_value'] );
		}
	}

	/**
	 * This function handles the custom logic necessary to associate categories to posts for The Six Fifty.
	 *
	 * @param int   $post_id The Post ID.
	 * @param array $categories The categories which were set for the post.
	 * @param array $tags The tags which were set for the post.
	 *
	 * @return array|false|mixed[]|\WP_Error
	 */
	private function process_six_fifty_categories_and_tags( int $post_id, array $categories, array $tags ) {
		$allowed_category_list = [];
		foreach ( array_merge( $categories, $tags ) as $item ) {
			$category_or_tag_name = strtolower( $item['name'] );
			if ( array_key_exists( $category_or_tag_name, $allowed_category_list ) ) {
				continue;
			}

			if ( ! in_array( $category_or_tag_name, self::ALLOWED_CATEGORIES, true ) ) {
				WP_CLI::line( sprintf( 'Category: `%s` is not allowed.', $category_or_tag_name ) );
				continue;
			}

			$allowed_category_list[ $category_or_tag_name ] = $item;
		}

		foreach ( $allowed_category_list as $name => &$item ) {
			$exists = category_exists( $name );

			if ( null !== $exists ) {
				WP_CLI::line( sprintf( 'Category already exists: %s', $name ) );
				$item = (int) $exists;
			} else {
				WP_CLI::line( sprintf( 'Creating category: %s', $item['name'] ) );
				$result = wp_insert_category(
					[
						'cat_name'          => $item['name'],
						'category_nicename' => $item['slug'],
					]
				);

				if ( is_int( $result ) && $result > 0 ) {
					$item = $result;
				} else {
					WP_CLI::line( 'ERROR CREATING CATEGORY' );
				}
			}
		}

		if ( ! empty( $allowed_category_list ) ) {
			return wp_set_post_terms( $post_id, array_values( $allowed_category_list ), 'category' );
		} else {
			return false;
		}
	}

	/**
	 * Get data from CSV file.
	 *
	 * @param string $story_csv_file_path Path to the CSV file containing the stories to import.
	 * @return array Array of data.
	 */
	private function get_data_from_csv_or_tsv( $story_csv_file_path ) {
		$data = [];

		// Reading CSV or TSV.
		$separator = ',';
		if ( '.tsv' == strtolower( substr( $story_csv_file_path, -4 ) ) ) {
			$separator = "\t";
		}

		if ( ! file_exists( $story_csv_file_path ) ) {
			$this->logger->log( self::LOG_FILE, 'File does not exist: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_file = fopen( $story_csv_file_path, 'r' );
		if ( false === $csv_file ) {
			$this->logger->log( self::LOG_FILE, 'Could not open file: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_row = fgetcsv( $csv_file, null, $separator );
		if ( false === $csv_row ) {
			$this->logger->log( self::LOG_FILE, 'Could not read CSV headers from file: ' . $story_csv_file_path, Logger::ERROR );
		}

		$csv_headers = array_map( 'trim', $csv_row );

		while ( ( $csv_row = fgetcsv( $csv_file, null, $separator ) ) !== false ) {
			if ( count( $csv_row ) !== count( $csv_headers ) ) {
				$this->logger->log( self::LOG_FILE, 'Could not read CSV row (' . current( $csv_row ) . ') from file: ' . $story_csv_file_path, Logger::WARNING );
				continue;
			}
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
	 * Get imported comments original IDs.
	 *
	 * @param string $meta_key Meta key to search for.
	 *
	 * @return array
	 */
	private function get_comments_meta_values_by_key( $meta_key ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM $wpdb->commentmeta WHERE meta_key = %s",
				$meta_key
			)
		);
	}

	/**
	 * Get or create a contributor.
	 *
	 * @param string $full_name Full name of the contributor.
	 * @param string $email_address Email address of the contributor.
	 * @param string $role Role of the user.
	 *
	 * @return int|null WP user ID.
	 */
	private function get_or_create_user( $full_name, $email_address, $role ) {
		// Check if user exists.
		$wp_user = get_user_by( 'email', $email_address );
		if ( $wp_user && ! empty( $email_address ) ) {
			return $wp_user->ID;
		}

		// Create a WP user with the contributor role.
		$user_login = 60 < strlen( $email_address ) ? substr( $email_address, 0, 60 ) : $email_address;
		$wp_user_id = wp_create_user( $user_login, wp_generate_password(), $email_address );
		if ( is_wp_error( $wp_user_id ) ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Could not create user %s: %s', $full_name, $wp_user_id->get_error_message() ), Logger::ERROR );
		} else {

			// Set the Contributor role.
			$user = new \WP_User( $wp_user_id );
			$user->set_role( $role );

			// Set WP User display name.
			wp_update_user(
				[
					'ID'            => $wp_user_id,
					'display_name'  => $full_name,
					'first_name'    => current( explode( ' ', $full_name ) ),
					'last_name'     => join( ' ', array_slice( explode( ' ', $full_name ), 1 ) ),
					'user_nicename' => sanitize_title( $full_name ),
				]
			);
		}

		return $wp_user_id;
	}

	/**
	 * Get or create a post.
	 *
	 * @param int    $original_id Original ID of the post.
	 * @param string $meta_name Meta name to search for.
	 * @param array  $post_data Post data.
	 *
	 * @return int|WP_Error WP post ID.
	 */
	private function get_or_create_post( $original_id, $meta_name, $post_data ) {
		// Get post by meta.
		$wp_post_id = $this->get_post_id_by_meta( $meta_name, $original_id );

		if ( $wp_post_id ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Post %d already exists with the ID %d', $original_id, $wp_post_id ), Logger::LINE );
			return intval( $wp_post_id );
		}

		// Create a WP post.
		return wp_insert_post( $post_data, true );
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
	 * @param bool   $skip_post_photos Whether to skip post media in content.
	 * @param array  $report_items Array of report items data.
	 *
	 * @return string Migrated post content.
	 */
	private function migrate_post_content_shortcodes( $story_id, $wp_post_id, $story_text, $photos, $story_photos_dir_path, $media, $carousel_items, $skip_post_photos, $report_items ) {
		// Story text contains different shortcodes in the format: {shorcode meta meta ...}.
		if ( ! $skip_post_photos ) {
			$story_text = $this->migrate_photos( $wp_post_id, $story_text, $photos, $story_photos_dir_path );
		}

		$story_text = $this->migrate_media( $wp_post_id, $story_id, $story_text, $media, $photos, $story_photos_dir_path, $carousel_items, $report_items );
		$story_text = $this->migrate_links( $story_text );
		$story_text = $this->migrate_text_styling( $story_text );
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
	 * @param array  $coauthors Array of co-authors.
	 * @param array  $all_contributors Array of all contributors.
	 * @param string $email_domain Email domain.
	 * @return array Array of co-authors users.
	 */
	private function get_generate_coauthor_users( $coauthors, $all_contributors, $email_domain ) {
		$coauthor_users = [];
		foreach ( $coauthors as $coauthor ) {
			$contributor_index = array_search( $coauthor, array_column( $all_contributors, 'full_name' ) );

			if ( false !== $contributor_index ) {
				$contributor      = $all_contributors[ $contributor_index ];
				$author_id        = $this->get_or_create_user( $contributor['full_name'], $contributor['email_address'], 'contributor' );
				$coauthor_users[] = get_user_by( 'id', $author_id );
			} else {
				$author_email     = sanitize_title( $coauthor, true ) . '@' . $email_domain;
				$author_id        = $this->get_or_create_user( $coauthor, $author_email, 'contributor' );
				$coauthor_users[] = get_user_by( 'id', $author_id );
			}
		}

		return $coauthor_users;
	}

	/**
	 * Get or create a category.
	 *
	 * @param string $name Category name.
	 * @param int    $parent_id Parent category ID.
	 *
	 * @return int|null Category ID.
	 */
	private function get_or_create_category( $name, $parent_id = null ) {
		$term = get_term_by( 'name', $name, 'category' );
		if ( $term ) {
			return $term->term_id;
		}

		$args = [];

		if ( $parent_id ) {
			$args['parent'] = $parent_id;
		}

		$term = wp_insert_term( $name, 'category', $args );
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
	 * @param array  $report_items Array of report items data.
	 *
	 * @return string Migrated post content.
	 */
	private function migrate_media( $wp_post_id, $story_id, $content, $media_list, $photos, $story_photos_dir_path, $carousel_items, $report_items ) {
		// Media can be in the content in the format {media_type 40 25877}
		// where media_type can be one of the following: carousel, flour, map, more_stories, pull_quote, timeline, video.
		// 40 is the percentage of the column and 25877 is the media ID.

		preg_match_all( '/(?<shortcode>{(?<type>carousel|flour|map|more_stories|pull_quote|timeline|video|pdf)(\s+(?<width>(\d|\w)+)?)?(\s+(?<id>\d+)?)?})/', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			switch ( $match['type'] ) {
				case 'carousel':
					$media_index = array_search( $match['id'], array_column( $media_list, 'media_id' ) );
					if ( false !== $media_index ) {
						$media = $media_list[ $media_index ];

						$media_carousel_items = array_filter(
							$carousel_items,
							function ( $carousel_item ) use ( $media ) {
								return $carousel_item['carousel_media_id'] === $media['media_id'];
							}
						);

						// order $media_carousel_items by sort_order column.
						usort(
							$media_carousel_items,
							function ( $a, $b ) {
								return intval( $a['sort_order'] ) <=> intval( $b['sort_order'] );
							}
						);

						$carousel_attachments_items = array_values(
							array_filter(
								array_map(
									function ( $carousel_item ) use ( $wp_post_id, $photos, $story_photos_dir_path ) {
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
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find map %s for the post %d', ( $match['id'] ?? '' ), $wp_post_id ), Logger::WARNING );
					}
					break;
				case 'timeline':
					$media_index = array_search( $match['id'], array_column( $media_list, 'media_id' ) );
					if ( false !== $media_index ) {
						$timeline_media = $media_list[ $media_index ];

						if ( str_starts_with( $timeline_media['media_link'], '<iframe' ) ) {
							$photo_block_html = serialize_block( $this->gutenberg_block_generator->get_html( $timeline_media['media_link'] ) );
							$content          = str_replace( $match['shortcode'], $photo_block_html, $content );
						} else {
							$this->logger->log( self::LOG_FILE, sprintf( 'Could not find iframe code for the post %d', $wp_post_id ), Logger::WARNING );
						}
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find media for the timeline %s for the post %d', $match['id'], $wp_post_id ), Logger::WARNING );
					}
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
				case 'pdf':
					// pdf are in the format: {pdf 123}.
					$media_index = array_search( $match['width'], array_column( $report_items, 'report_id' ) );
					if ( false !== $media_index ) {
						$report = $report_items[ $media_index ];

						$media_content = "<a href='https://www.paloaltoonline.com/media/reports_pdf/{$report['seo_link']}'>{$report['report_title']}</a>";

						$content = str_replace( $match['shortcode'], $media_content, $content );
					} else {
						$this->logger->log( self::LOG_FILE, sprintf( 'Could not find report %s for the post %d', $match['width'], $wp_post_id ), Logger::WARNING );
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
		return preg_replace( '/\[(?<url>https?:\/\/[^\s\]]+)(\s+(?<text>[^\]]+))?\]/', '<a href="${1}">${2}</a>', $story_text );
	}

	/**
	 * Migrate text styling in content.
	 *
	 * @param string $story_text Post content.
	 * @return string Migrated post content.
	 */
	private function migrate_text_styling( $story_text ) {
		// The content contain some styling in the format ==I whatever text here should be italic==.
		// We need to convert them to <em>whatever text here should be italic</em>.
		$story_text = preg_replace( '/==I\s+(.*?)==/', '<em>${1}</em>', $story_text );
		// Same goes for bold.
		$story_text = preg_replace( '/==B\s+(.*?)==/', '<strong>${1}</strong>', $story_text );
		// Same goes for italic and bold text at the same time.
		$story_text = preg_replace( '/==BI\s+(.*?)==/', '<strong><em>${1}</em></strong>', $story_text );
		// Same goes for sub header.
		$story_text = preg_replace( '/==SH\s+(.*?)==/', '<h3>${1}</h3>', $story_text );

		// The content contain some styling in the format ==I whatever text here should be italic\n.
		// We need to convert them to <em>whatever text here should be italic</em>.
		$story_text = preg_replace( '/==I\s+(.*?)\n/', '<em>${1}</em>', $story_text );
		// Same goes for bold.
		$story_text = preg_replace( '/==B\s+(.*?)\n/', '<strong>${1}</strong>', $story_text );
		// Same goes for italic and bold text at the same time.
		$story_text = preg_replace( '/==BI\s+(.*?)\n/', '<strong><em>${1}</em></strong>', $story_text );
		// Same goes for sub header.
		$story_text = preg_replace( '/==SH\s+(.*?)\n/', '<h3>${1}</h3>', $story_text );

		return $story_text;
	}

	/**
	 * Get attachment from media.
	 *
	 * @param int    $wp_post_id WP post ID.
	 * @param array  $media Media data.
	 * @param string $story_photos_dir_path Path to the directory containing the stories\'s photos files to import.
	 * @return int|bool Attachment ID or false if not found.
	 */
	private function get_attachment_from_media( $wp_post_id, $media, $story_photos_dir_path ) {
		$attachment_id = $this->get_post_id_by_meta( self::EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY, $media['photo_id'] );

		if ( $attachment_id ) {
			return $attachment_id;
		}

		$media_year  = $media['photo_year'];
		$media_month = strtolower( gmdate( 'F', mktime( 0, 0, 0, $media['photo_month'], 1 ) ) );
		$media_dir   = $story_photos_dir_path . '/' . $media_year . '/' . $media_month . '/' . $media['photo_day'];

		// Try various suffixes on the photo name. Some years lack originals, some lack fulls, etc.
		// This list decreases in photo quality.
		$filenames[] = $media['photo_name'] . '_original.jpg';
		$filenames[] = $media['photo_name'] . '_full.jpg';
		$filenames[] = $media['photo_name'] . '_main.jpg';
		$filenames[] = $media['photo_name'] . '_thumb.jpg';

		foreach ( $filenames as $filename ) {
			$media_path = $media_dir . '/' . $filename;
			if ( file_exists( $media_path ) ) {
				$attachment_id = $this->attachments->import_attachment_for_post(
					$wp_post_id,
					$media_path,
					$media['caption'],
					[
						'post_excerpt' => $media['caption'],
					]
				);
				if ( is_wp_error( $attachment_id ) ) {
					$this->logger->log(
						self::LOG_FILE,
						sprintf( 'Could not import photo %s for the post %d: %s', $media_path, $wp_post_id, $attachment_id->get_error_message() ),
						Logger::WARNING
					);
					continue;
				}

				update_post_meta( $attachment_id, self::EMBARCADERO_ORIGINAL_MEDIA_ID_META_KEY, $media['photo_id'] );

				if ( str_ends_with( $filename, '_thumb.jpg' ) ) {
					$this->logger->log( self::LOG_FILE, sprintf( 'Only could find a thumbniail: %s for the post %d', $media_path, $wp_post_id ), Logger::WARNING );
				}

				$this->logger->log( 'imported_inages.log', sprintf( 'Imported photo %s for the post %d', $media_path, $wp_post_id ), Logger::LINE );
				return $attachment_id;
			}
		}
		$this->logger->log( self::LOG_FILE, sprintf( 'Could not find photo %s for the post %d', $media_path, $wp_post_id ), Logger::WARNING );

		return false;
	}

	/**
	 * Get post ID by meta.
	 *
	 * @param string $meta_name Meta name.
	 * @param string $meta_value Meta value.
	 * @return int|null
	 */
	private function get_post_id_by_meta( $meta_name, $meta_value ) {
		global $wpdb;

		if ( empty( $meta_value ) ) {
			return null;
		}

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
		return '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=' . $video_id . '","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
		<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
		https://www.youtube.com/watch?v=' . $video_id . '
		</div></figure>
		<!-- /wp:embed -->';
	}
}
