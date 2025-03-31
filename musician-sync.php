<?php
/**
 * Musician Sync Command.
 *
 * Syncs musician posts from a source site to a target site.
 *
 * @package YourPackageName
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Class Musician_Sync_Command
	 *
	 * Sync musician posts between multisite installations.
	 */
	class Musician_Sync_Command {

		/**
		 * Sync musician posts from a source site to a target site.
		 *
		 * ## OPTIONS
		 *
		 * --source_site=<site_id>
		 * : The source site ID from which to sync musician posts.
		 *
		 * --copy_site=<site_id>
		 * : The target site ID to which posts should be copied.
		 *
		 * ## EXAMPLES
		 *
		 *     wp musician sync --source_site=1 --copy_site=3
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @return void
		 */
		public function __invoke( $args, $assoc_args ) {
			$source_site_id = isset( $assoc_args['source_site'] ) ? intval( $assoc_args['source_site'] ) : 0;

			// Validate source site.
			if ( ! get_blog_details( $source_site_id ) ) {
				WP_CLI::error( 'Invalid source site. Use --source_site=<id> with a valid site ID.' );
			}

			// Validate target site.
			if ( ! isset( $assoc_args['copy_site'] ) ) {
				WP_CLI::error( 'Please provide a target site using --copy_site=<id>.' );
			}
			$target_site_id = intval( $assoc_args['copy_site'] );
			if ( ! get_blog_details( $target_site_id ) ) {
				WP_CLI::error( 'Invalid target site. Use --copy_site=<id> with a valid site ID.' );
			}

			// Ensure source and target are not the same.
			if ( $source_site_id === $target_site_id ) {
				WP_CLI::error( 'Source site and copy site cannot be the same.' );
			}

			// Get musician post IDs from the source site.
			switch_to_blog( $source_site_id );
			$source_posts = get_posts(
				array(
					'post_type'      => 'musician',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			restore_current_blog();

			// Sync each musician post to the target site.
			foreach ( $source_posts as $source_post_id ) {
				$this->sync_post( $source_post_id, $source_site_id, $target_site_id );
			}

			WP_CLI::success( 'Sync completed' );
		}

		/**
		 * Syncs an individual musician post from the source site to the target site.
		 *
		 * @param int $source_post_id The source post ID.
		 * @param int $source_site_id The source site ID.
		 * @param int $target_site_id The target site ID.
		 * @return void
		 */
		private function sync_post( $source_post_id, $source_site_id, $target_site_id ) {
			// Retrieve the source post.
			switch_to_blog( $source_site_id );
			$source_post = get_post( $source_post_id );
			restore_current_blog();

			if ( ! $source_post ) {
				return;
			}

			// Switch to the target site.
			switch_to_blog( $target_site_id );

			// Check if the target site has the musician CPT.
			if ( ! post_type_exists( 'musician' ) ) {
				restore_current_blog();
				return;
			}

			// Duplicate check based solely on the post title.
			if ( post_exists( $source_post->post_title ) ) {
				WP_CLI::line( "Post '{$source_post->post_title}' already exists in site {$target_site_id}" );
				restore_current_blog();
				return;
			}

			// Insert the new musician post.
			$new_post_id = wp_insert_post(
				array(
					'post_title'   => $source_post->post_title,
					'post_content' => $source_post->post_content,
					'post_type'    => 'musician',
					'post_status'  => 'publish',
				)
			);

			if ( is_wp_error( $new_post_id ) ) {
				WP_CLI::warning( "Failed to insert post '{$source_post->post_title}': " . $new_post_id->get_error_message() );
				restore_current_blog();
				return;
			}

			// Copy meta fields (including those added programmatically or via ACF).
			$this->copy_meta_fields( $source_post_id, $new_post_id, $source_site_id );

			// Copy taxonomy terms.
			$this->copy_taxonomies( $source_post_id, $new_post_id, $source_site_id );

			// Copy featured image.
			$this->copy_featured_image( $source_post_id, $new_post_id, $source_site_id );

			restore_current_blog();
			WP_CLI::line( "Synced post {$source_post_id} to site {$target_site_id} as {$new_post_id}" );
		}

		/**
		 * Copies meta fields from the source post to the new post.
		 *
		 * @param int $source_post_id The source post ID.
		 * @param int $new_post_id    The new post ID on the target site.
		 * @param int $source_site_id The source site ID.
		 * @return void
		 */
		private function copy_meta_fields( $source_post_id, $new_post_id, $source_site_id ) {
			switch_to_blog( $source_site_id );
			$meta_data = get_post_meta( $source_post_id );
			restore_current_blog();

			if ( ! empty( $meta_data ) && is_array( $meta_data ) ) {
				foreach ( $meta_data as $meta_key => $values ) {
					// Loop through each meta value (handles multiple values per key).
					foreach ( $values as $value ) {
						update_post_meta( $new_post_id, $meta_key, maybe_unserialize( $value ) );
					}
				}
			}
		}

		/**
		 * Copies taxonomy terms from the source post to the new post.
		 *
		 * @param int $source_post_id The source post ID.
		 * @param int $new_post_id    The new post ID on the target site.
		 * @param int $source_site_id The source site ID.
		 * @return void
		 */
		private function copy_taxonomies( $source_post_id, $new_post_id, $source_site_id ) {
			switch_to_blog( $source_site_id );
			$taxonomies = get_object_taxonomies( 'musician' );
			foreach ( $taxonomies as $taxonomy ) {
				$terms    = wp_get_post_terms( $source_post_id, $taxonomy );
				$term_ids = array();
				foreach ( $terms as $term ) {
					$term_id = $this->get_or_create_term( $term, $taxonomy );
					if ( $term_id ) {
						$term_ids[] = $term_id;
					}
				}
				wp_set_post_terms( $new_post_id, $term_ids, $taxonomy );
			}
			restore_current_blog();
		}

		/**
		 * Gets or creates a term on the target site by slug.
		 *
		 * @param WP_Term $term     The term object from the source site.
		 * @param string  $taxonomy The taxonomy name.
		 * @return int The term ID on the target site, or 0 on failure.
		 */
		private function get_or_create_term( $term, $taxonomy ) {
			$existing_term = get_term_by( 'slug', $term->slug, $taxonomy );
			if ( $existing_term ) {
				return $existing_term->term_id;
			}

			$new_term = wp_insert_term(
				$term->name,
				$taxonomy,
				array(
					'slug'        => $term->slug,
					'description' => $term->description,
				)
			);

			if ( is_wp_error( $new_term ) ) {
				WP_CLI::warning( "Failed to create term {$term->slug}: " . $new_term->get_error_message() );
				return 0;
			}

			return $new_term['term_id'];
		}

		/**
		 * Copies the featured image from the source post to the new post.
		 *
		 * @param int $source_post_id The source post ID.
		 * @param int $new_post_id    The new post ID on the target site.
		 * @param int $source_site_id The source site ID.
		 * @return void
		 */
		private function copy_featured_image( $source_post_id, $new_post_id, $source_site_id ) {
			switch_to_blog( $source_site_id );
			$thumbnail_id = get_post_thumbnail_id( $source_post_id );
			$image_url    = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';
			restore_current_blog();

			if ( ! $image_url ) {
				return;
			}

			// Check if image already exists by URL on the target site.
			$existing_id = $this->get_attachment_by_url( $image_url );
			if ( $existing_id ) {
				set_post_thumbnail( $new_post_id, $existing_id );
				return;
			}

			$file_array             = array();
			$file_array['name']     = basename( $image_url );
			$file_array['tmp_name'] = download_url( $image_url );

			if ( is_wp_error( $file_array['tmp_name'] ) ) {
				WP_CLI::warning( 'Failed to download image: ' . $file_array['tmp_name']->get_error_message() );
				return;
			}

			$attachment_id = media_handle_sideload( $file_array, $new_post_id );
			if ( is_wp_error( $attachment_id ) ) {
				// Delete the temporary file using wp_delete_file() instead of @unlink.
				wp_delete_file( $file_array['tmp_name'] );
				WP_CLI::warning( 'Failed to sideload image: ' . $attachment_id->get_error_message() );
				return;
			}

			set_post_thumbnail( $new_post_id, $attachment_id );
		}

		/**
		 * Retrieves an attachment ID by URL on the target site using core functionality.
		 *
		 * @param string $url The attachment URL.
		 * @return int Attachment post ID if found, otherwise 0.
		 */
		private function get_attachment_by_url( $url ) {
			// Use the built-in WordPress function that handles this.
			return wpcom_vip_attachment_url_to_postid( $url );
		}
	}

	WP_CLI::add_command( 'musician sync', 'Musician_Sync_Command' );
}
