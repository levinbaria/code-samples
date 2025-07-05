<?php
/**
 * CLI to clear the meta fields from the DB.
 *
 * @package dynata-2025
 */

declare( strict_types = 1 );

namespace Code_Sample\Includes;

use WP_CLI;
use WP_Query;
use WP_Filesystem_Base;
use Exception;
use Code_Sample\Includes\Traits\Singleton;

/**
 * Class for Meta Fields Clear.
 */
class Clear_Meta_Cli {
	use Singleton;

	/**
	 * WordPress Filesystem instance
	 *
	 * @var WP_Filesystem_Base
	 */
	private $filesystem;

	/**
	 * CSV file path for batch writing
	 *
	 * @var string
	 */
	private $csv_file_path = '';

	/**
	 * Total CSV records count
	 *
	 * @var int
	 */
	private $total_csv_records = 0;

	/**
	 * Construct method.
	 */
	protected function __construct() {
		$this->setup_hooks();
		$this->init_filesystem();
	}

	/**
	 * Initialize WordPress Filesystem
	 *
	 * @return void
	 */
	private function init_filesystem(): void {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		// Initialize the filesystem.
		if ( ! WP_Filesystem() ) {
			WP_CLI::error( 'Could not initialize WordPress filesystem.' );
		}

		$this->filesystem = $wp_filesystem;
	}

	/**
	 * To register action/filter.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function setup_hooks() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'meta-clear', array( $this, 'handle_clear_meta_command' ) );
		}
	}

	/**
	 * Handle the CLI command: wp meta-clear [--meta-keys=<keys|file>] [--post_types=] [--batch=] [--dry-run] [--csv-export]
	 *
	 * ## USAGE
	 *
	 *   # Delete 'subtitle' and its internal ACF key from pages
	 *   $ wp meta-clear --meta-keys=subtitle,field_6012dd8cba92f
	 *
	 *   # Dry-run only (show what would be deleted)
	 *   $ wp meta-clear --meta-keys=old_key,new_key --dry-run
	 *
	 *   # Target posts and pages, smaller batches with CSV export
	 *   $ wp meta-clear --meta-keys=tracking_code --post_types=post,page --batch=50 --csv-export
	 *
	 *   # Export to custom CSV location
	 *   $ wp meta-clear --meta-keys=tracking_code --csv-export
	 *
	 * ## OPTIONS
	 *
	 * [--meta-keys=<keys|file>]
	 * : Either a comma-separated list of meta keys **or** a path to a CSV whose first row lists your keys.
	 *
	 * [--post_types=<types>]
	 * : Comma-separated list of post types to target. Default: all public post type.
	 *
	 * [--batch=<n>]
	 * : Number of posts per batch. Default: 100.
	 *
	 * [--dry-run]
	 * : If set, logs what would be deleted but does not delete anything.
	 *
	 * [--csv-export]
	 * : When present, a CSV will be generated (in `/wp-content/uploads/YYYY/MM/`) and registered
	 *   in the Media Library—**only** if at least one meta row was actually deleted.
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional args (meta_keys).
	 * @param array $assoc_args Associative args (--post_types, --batch, --dry-run) --csv-export.
	 */
	public function handle_clear_meta_command( array $args, array $assoc_args ): void {

		$meta_arg = $assoc_args['meta-keys'] ?? '';
		if ( $this->filesystem->exists( $meta_arg ) && is_file( $meta_arg ) && is_readable( $meta_arg ) ) {
			$content = $this->filesystem->get_contents( $meta_arg );
			if ( false === $content ) {
				WP_CLI::error( 'Failed to read CSV file' );
			}

			$meta_val = array();
			$lines    = array_filter( preg_split( '/\r\n|\r|\n/', $content ), 'strlen' );
			if ( empty( $lines ) ) {
				WP_CLI::error( "CSV at {$meta_arg} is empty." );
			}
			// Skip header row.
			array_shift( $lines );
			foreach ( $lines as $line ) {
				$cols = str_getcsv( $line );
				if ( ! empty( $cols[0] ) ) {
					$val = trim( $cols[0] );
					if ( $val !== '' ) {
						$meta_val[] = sanitize_key( $val );
					}
				}
			}

			$meta_keys = array_values( array_unique(  $meta_val ) );
		} else {
			// Comma‑list mode
			$meta_keys = array_unique( array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $meta_arg ) ) ) ) );
		}
		if ( empty( $meta_keys ) ) {
			WP_CLI::error( 'You must specify at least one meta key to delete.' );
			return;
		}
		$post_types      = ! empty( $assoc_args['post_types'] )
			? array_map( 'trim', explode( ',', (string) $assoc_args['post_types'] ) )
			: get_post_types( array( 'public' => true ) );
		$available_types = get_post_types( array( 'public' => true ) );
		$post_types      = array_filter( $post_types, fn( $pt ) => in_array( $pt, $available_types, true ) );

		if ( empty( $post_types ) ) {
			WP_CLI::error( 'No valid post types provided.' );
		}
		$batch   = isset( $assoc_args['batch'] ) ? max( 1, intval( $assoc_args['batch'] ) ) : 100;
		$dry_run = isset( $assoc_args['dry-run'] );
		// Reset CSV record counter at the start.
		$this->total_csv_records = 0;

		if ( isset( $assoc_args['csv-export'] ) ) {
			$uploads             = wp_upload_dir();
			$filename            = 'meta-cleanup-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';
			$this->csv_file_path = trailingslashit( $uploads['basedir'] ) . $filename;

			$dir = dirname( $this->csv_file_path );
			if ( ! $this->filesystem->is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$header = array( 'Post ID', 'Post Title', 'Post Type', 'Meta Key', 'Meta Value', 'Meta ID', 'Deletion Date' );
			$line   = "\xEF\xBB\xBF" . $this->array_to_csv_line( $header ) . "\n";
			$this->filesystem->put_contents( $this->csv_file_path, $line );

		}

		WP_CLI::log(
			( $dry_run ? '[DRY-RUN] ' : '' ) .
			'Deleting meta keys: ' . implode( ', ', $meta_keys ) .
			' from post types: ' . implode( ', ', $post_types ) .
			" in batches of {$batch}."
		);

		// Get total posts count for progress bar
		$count_query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'fields'         => 'ids',
			)
		);
		$total_posts = $count_query->found_posts;

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		$paged         = 1;
		$total_deleted = 0;
		$progress      = WP_CLI\Utils\make_progress_bar( 'Processing records', $total_posts );

		do {
			$query = new WP_Query(
				array(
					'post_type'      => $post_types,
					'posts_per_page' => $batch,
					'paged'          => $paged,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			if ( empty( $query->posts ) ) {
				break;
			}
			// Collect batch data for CSV export.
			$batch_csv_data = array();
			foreach ( $query->posts as $post_id ) {
				$post_title = get_the_title( $post_id );
				$post_type  = get_post_type( $post_id );

				foreach ( $meta_keys as $meta_key ) {
					$meta_key = sanitize_key( $meta_key );
					// Get all meta values with their meta IDs.
					$meta_rows = $this->get_meta_with_ids( $post_id, $meta_key );

					if ( ! empty( $meta_rows ) ) {
						$count = count( $meta_rows );

						foreach ( $meta_rows as $meta_row ) {
							$formatted_value = $this->format_meta_value( $meta_row['meta_value'] );

							if ( ! $dry_run && isset( $this->csv_file_path ) ) {
								$batch_csv_data[] = array(
									$post_id,
									$post_title,
									$post_type,
									$meta_key,
									$formatted_value,
									$meta_row['meta_id'],
									gmdate( 'Y-m-d H:i:s' ),
								);
							}
						}

						if ( ! $dry_run ) {
							delete_post_meta( $post_id, $meta_key );
						}
						$total_deleted += $count;
						WP_CLI::log(
							sprintf(
								'Post #%d (%s): %s %d rows for meta "%s"',
								$post_id,
								$post_title,
								$dry_run ? 'Would delete' : 'Deleted',
								$count,
								$meta_key
							)
						);
					}
				}
				$progress->tick();

			}

			// Append CSV for this post’s rows.
			if ( ! $dry_run && ! empty( $this->csv_file_path ) && ! empty( $batch_csv_data ) ) {
				$this->append_csv_export_batch( $this->csv_file_path, $batch_csv_data );
				$this->total_csv_records += count( $batch_csv_data );
				unset( $batch_csv_data );
			}
			// Memory cleanup.
			wp_cache_flush();
			if ( function_exists( 'wp_clear_object_cache' ) ) {
				wp_clear_object_cache();
			}
			gc_collect_cycles();
			sleep( 2 );
			++$paged;
		} while ( true );

		if ( $progress ) {
			$progress->finish();
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		WP_CLI::success(
			sprintf(
				'%s Total meta rows %s = %d%s',
				$dry_run ? 'DRY-RUN complete: ' : 'Done:',
				$dry_run ? 'that would be deleted' : 'deleted',
				$total_deleted,
				( isset( $this->csv_file_path ) && ! $dry_run && 0 !== $this->total_csv_records ) ? ". CSV exported: {$this->total_csv_records} records at: {$this->csv_file_path}" : ''
			)
		);

		if ( $this->csv_file_path && ! $dry_run ) {
			if ( 0 === $this->total_csv_records && $this->filesystem->exists( $this->csv_file_path ) ) {
				$this->filesystem->delete( $this->csv_file_path );
			} else {
				$this->attach_to_media_library( $this->csv_file_path );
			}
		}
	}

	/**
	 * Get post meta with meta IDs
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return array Array of meta data with meta_id and meta_value.
	 */
	private function get_meta_with_ids( int $post_id, string $meta_key ): array {
		global $wpdb;
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$post_id,
				$meta_key
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Format meta value for CSV export
	 *
	 * @param mixed $value Meta value.
	 * @return string Formatted value.
	 */
	private function format_meta_value( $value ): string {
		if ( is_serialized( $value ) ) {
			$unserialized = maybe_unserialize( $value );
			if ( is_array( $unserialized ) || is_object( $unserialized ) ) {
				return wp_json_encode( $unserialized );
			}
		}
		return (string) $value;
	}

	/**
	 * Append batch data to CSV file
	 *
	 * @param array $batch_data Array of CSV rows for this batch.
	 * @return void
	 */
	private function append_csv_export_batch( string $file_path, array $rows ): void {
		try {
			if ( empty( $rows ) ) {
				return;
			}

			// Convert batch data to CSV string.
			$csv_content = '';
			foreach ( $rows as $row ) {
				$csv_content .= $this->array_to_csv_line( $row ) . "\n";
			}

			// Get existing content and append new data.
			$existing_content = $this->filesystem->get_contents( $file_path );
			if ( false === $existing_content ) {
				WP_CLI::warning( "Could not read existing CSV file: {$file_path}" );
				return;
			}

			$updated_content = $existing_content . $csv_content;

			// Write updated content back to file.
			if ( ! $this->filesystem->put_contents( $file_path, $updated_content, FS_CHMOD_FILE ) ) {
				WP_CLI::warning( "Could not append to CSV file: {$file_path}" );
				return;
			}
		} catch ( Exception $e ) {
			WP_CLI::warning( 'Error appending to CSV: ' . $e->getMessage() );
		}
	}

	/**
	 * Convert array to CSV line
	 *
	 * @param array $data Array data to convert.
	 * @return string CSV formatted line.
	 */
	private function array_to_csv_line( array $data ): string {
		$csv_line = '';
		$first    = true;

		foreach ( $data as $field ) {
			if ( ! $first ) {
				$csv_line .= ',';
			}
			// Escape quotes and wrap in quotes if needed.
			$field = (string) $field;
			if ( strpos( $field, '"' ) !== false ) {
				$field = str_replace( '"', '""', $field );
			}

			// Wrap in quotes if contains comma, quote, or newline.
			if ( strpos( $field, ',' ) !== false ||
				strpos( $field, '"' ) !== false ||
				strpos( $field, "\n" ) !== false ||
				strpos( $field, "\r" ) !== false ) {
				$field = '"' . $field . '"';
			}
			$csv_line .= $field;
			$first     = false;
		}
		return $csv_line;
	}

	private function attach_to_media_library( string $file_path ): void {
		// get the fileurl
		$uploads  = wp_upload_dir();
		$relative = str_replace( trailingslashit( $uploads['basedir'] ), '', $file_path );
		$file_url = trailingslashit( $uploads['baseurl'] ) . $relative;

		// insert attachment
		$wp_filetype = wp_check_filetype( $file_path );
		$attachment  = array(
			'guid'           => $file_url,
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => basename( $file_path ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$id          = wp_insert_attachment( $attachment, $file_path );
		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( 'Could not register CSV in Media Library.' );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file_path ) );
		WP_CLI::log( "CSV added to Media Library as attachment #{$id}." );
	}
}
