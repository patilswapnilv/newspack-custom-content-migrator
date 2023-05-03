<?php

namespace NewspackCustomContentMigrator\Logic;

use \CoAuthors_Plus;
use \CoAuthors_Guest_Authors;
use \WP_CLI;
use WP_Post;

/**
 * CoAuthorPlus general logic class.
 */
class CoAuthorPlus {

	/**
	 * CoAuthors_Plus.
	 *
	 * @var null|CoAuthors_Plus $coauthors_plus
	 */
	public $coauthors_plus;

	/**
	 * CoAuthors_Guest_Authors.
	 *
	 * @var null|CoAuthors_Guest_Authors
	 */
	public $coauthors_guest_authors;

	/**
	 * CoAuthorPlus constructor.
	 */
	public function __construct() {
		// Set Co-Authors Plus dependencies.
		global $coauthors_plus;

		$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

		$file_1     = $plugin_path . '/co-authors-plus/co-authors-plus.php';
		$file_2     = $plugin_path . '/co-authors-plus/php/class-coauthors-guest-authors.php';
		$included_1 = is_file( $file_1 ) && include_once $file_1;
		$included_2 = is_file( $file_2 ) && include_once $file_2;

		if ( is_null( $coauthors_plus ) || ( false === $included_1 ) || ( false === $included_2 ) || ( ! $coauthors_plus instanceof CoAuthors_Plus ) ) {
			// CoAuthors Plus is a dependency, and will have to be installed before the public functions/commands can be used.
			return;
		}

		$this->coauthors_plus          = $coauthors_plus;
		$this->coauthors_guest_authors = new CoAuthors_Guest_Authors();
	}

	/**
	 * Validates whether Co-Author Plus plugin's dependencies were successfully set.
	 *
	 * @return bool Is everything set up OK.
	 */
	public function validate_co_authors_plus_dependencies() {
		if ( ( ! $this->coauthors_plus instanceof CoAuthors_Plus ) || ( ! $this->coauthors_guest_authors instanceof CoAuthors_Guest_Authors ) ) {
			return false;
		}

		if ( false === $this->is_coauthors_active() ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether Co-authors Plus is installed and active.
	 *
	 * @return bool Is active.
	 */
	public function is_coauthors_active() {
		$active = false;
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( $plugin, 'co-authors-plus.php' ) ) {
				$active = true;
			}
		}

		return $active;
	}

	/**
	 * Creates Guest Authors from their full names.
	 *
	 * @param array $args {
	 *     The $args param for the \CoAuthors_Guest_Authors::create method.
	 *
	 *     @type string $display_name This is the only required param.
	 *     @type string $user_login   This param is optional, since this function automatically creates it from the $display_name.
	 *     @type string $first_name
	 *     @type string $last_name
	 *     @type string $user_email
	 *     @type string $website
	 *     @type string $description
	 *     @type int    $avatar       Attachment ID for the Avatar image.
	 * }
	 *
	 * @return int|array Created Guest Author ID, or an array of created Guest Author IDs.
	 *
	 * @throws \UnexpectedValueException In case mandatory argument values aren't provided.
	 */
	public function create_guest_author( array $args ) {
		if ( ! isset( $args['display_name'] ) ) {
			throw new \UnexpectedValueException( 'The `display_name` param is mandatory for Guest Author creation.' );
		}

		// If not provided, automatically set `user_login` from display_name.
		if ( ! isset( $args['user_login'] ) ) {
			$args['user_login'] = sanitize_title( $args['display_name'] );
		}

		$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'user_login', $args['user_login'] );
		if ( false === $guest_author ) {
			$coauthor_id = $this->coauthors_guest_authors->create( $args );
		} else {
			$coauthor_id = $guest_author->ID;
		}

		return $coauthor_id;
	}

	/**
	 * Assigns Guest Authors to the Post. Completely overwrites the existing list of authors.
	 *
	 * @param array $guest_author_ids Guest Author IDs.
	 * @param int   $post_id          Post IDs.
	 * @param bool  $append_to_existing_users Append to existing Guest Authors.
	 */
	public function assign_guest_authors_to_post( array $guest_author_ids, $post_id, bool $append_to_existing_users = false ) {
		$coauthors = [];
		foreach ( $guest_author_ids as $guest_author_id ) {
			$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'id', $guest_author_id );
			$coauthors[]  = $guest_author->user_nicename;
		}
		$this->coauthors_plus->add_coauthors( $post_id, $coauthors, $append_to_existing_users );
	}

	/**
	 * Links a Guest Author to an existing WP User.
	 *
	 * @param int     $ga_id Guest Author ID.
	 * @param \WPUser $user  WP User.
	 */
	public function link_guest_author_to_wp_user( $ga_id, $user ) {
		// Since GAs and WP Users can't have the same login, update it if they're the same.
		$cap_user_login = get_post_meta( $ga_id, 'cap-user_login', true );
		if ( $cap_user_login == $user->user_nicename ) {
			update_post_meta( $ga_id, 'cap-user_login', 'cap-' . $cap_user_login );
		}

		// WP User mapping.
		update_post_meta( $ga_id, 'cap-linked_account', $user->user_login );
	}

	/**
	 * Returns the Guest Author object by ID (as defined by the CAP plugin).
	 *
	 * @param int $ga_id Guest Author ID.
	 *
	 * @return false|object Guest Author object.
	 */
	public function get_guest_author_by_id( $ga_id ) {
		return $this->coauthors_guest_authors->get_guest_author_by( 'ID', $ga_id );
	}

	/**
	 * Gets the Guest Author object by `user_login` (as defined by the CAP plugin).
	 *
	 * @param int $ga_user_login Guest Author ID.
	 *
	 * @return false|object Guest Author object.
	 */
	public function get_guest_author_by_user_login( $ga_user_login ) {
		return $this->coauthors_guest_authors->get_guest_author_by( 'user_login', $ga_user_login );
	}

	/**
	 * Gets the Guest Author object by `display_name` (as defined by the CAP plugin).
	 *
	 * @param string $display_name Guest Author ID.
	 *
	 * @return false|object Guest Author object.
	 */
	public function get_guest_author_by_display_name( $display_name ) {

		// This class' method self::create_guest_author just sanitizes 'display_name' to get 'user_login'.
		$user_login = sanitize_title( $display_name );

		return $this->get_guest_author_by_user_login( $user_login );
	}

	/**
	 * Gets the corresponding Guest Author for a WP User, creating it if necessary.
	 *
	 * @param WP_User|int $wp_user ID of the User or a WP_User object
	 *
	 * @return false|object Guest author object.
	 */
	public function get_or_create_guest_author_from_user( $wp_user ) {

		// Convert IDs to WP User objects.
		if ( is_int( $wp_user ) ) {
			$wp_user = get_user_by( 'id', $wp_user );
		}

		// Make sure it's a valid user.
		if ( ! is_a( $wp_user, 'WP_User' ) ) {
			return false;
		}

		// Get the GA by using the user login.
		$guest_author = $this->get_guest_author_by_user_login( $wp_user->data->user_login );
		if ( ! $guest_author ) {
			// Doesn't exist, let's create it!
			$guest_author = $this->create_guest_author_from_wp_user( $wp_user->ID );
		}

		return $guest_author;

	}

	/**
	 * Gets Post's Guest Authors, leaving out WP User authors.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return mixed|void
	 */
	public function get_guest_authors_for_post( $post_id ) {
		$guest_authors = [];

		$coauthors = get_coauthors( $post_id );

		// Also returns \WP_User type authors. Let's filter those out.
		foreach ( $coauthors as $coauthor ) {
			if ( $coauthor instanceof \WP_User ) {
				continue;
			}

			$guest_authors[] = $coauthor;
		}

		return $guest_authors;
	}

	/**
	 * Returns a list of GA IDs assigned to Post.
	 * It works off of self::get_guest_authors_for_post() and just filters the GA IDs.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array List of GA IDs.
	 */
	public function get_posts_existing_ga_ids( $post_id ) {
		$existing_guest_author_ids = [];

		$existing_guest_authors = $this->get_guest_authors_for_post( $post_id );
		foreach ( $existing_guest_authors as $existing_guest_author ) {
			if ( 'guest-author' == $existing_guest_author->type ) {
				$existing_guest_author_ids[] = $existing_guest_author->ID;
			}
		}

		return $existing_guest_author_ids;
	}

	/**
	 * Creates a Guest Author user from an existing WP user.
	 *
	 * @param int $user_id WP User ID.
	 *
	 * @return int|\WP_Error Guest Author ID.
	 */
	public function create_guest_author_from_wp_user( $user_id ) {
		return $this->coauthors_guest_authors->create_guest_author_from_user_id( $user_id );
	}

	/**
	 * This function will facilitate obtaining all posts for a given Guest Author.
	 *
	 * @param int  $ga_id Guest Author ID (Post ID).
	 * @param bool $get_post_objects Flag which determines whether to return array of Post IDs, or Post Objects.
	 *
	 * @return int[]|WP_Post[]
	 */
	public function get_all_posts_for_guest_author( int $ga_id, bool $get_post_objects = false ) {
		global $wpdb;

		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
	                object_id
				FROM $wpdb->term_relationships 
				WHERE term_taxonomy_id = ( 
				    SELECT 
				           term_taxonomy_id 
				    FROM $wpdb->term_relationships 
				    WHERE object_id = %d )",
				$ga_id
			)
		);

		$post_ids = array_map( fn( $row ) => (int) $row->object_id, $records );
		$post_ids = array_filter( $post_ids, fn( $post_id ) => $post_id !== $ga_id );

		if ( ! empty( $post_ids ) ) {
			if ( ! $get_post_objects ) {
				return $post_ids;
			}

			return get_posts(
				[
					'numberposts' => -1,
					'post__in'    => $post_ids,
				]
			);
		}

		return [];
	}

	/**
	 * Delete a guest author. This function is just a wrapper for \CoAuthors_Guest_Authors::delete.
	 *
	 * @param int    $id          The ID for the guest author profile.
	 * @param string $reassign_to User login value for the co-author to reassign posts to.
	 *
	 * @return bool|WP_Error $success True on success, WP_Error on a failure.
	 */
	public function delete_ga( $id, $reassign_to = false ) {
		return $this->coauthors_guest_authors->delete( $id, $reassign_to );
	}

	/**
	 * Gets all guest author objects.
	 *
	 * @return array Array of GA objects.
	 */
	public function get_all_gas() {
		global $wpdb;

		// This query was taken directly from \CoAuthors_Guest_Authors::get_guest_author_by.
		$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'guest-author';" );

		$all_gas = [];
		foreach ( $post_ids as $post_id ) {
			$all_gas[] = $this->coauthors_guest_authors->get_guest_author_by( 'ID', $post_id );
		}

		return $all_gas;
	}
}
