<?php
/**
 * Class Migration_Cli
 *
 * Provides the `wp ct-content-import` WP-CLI command for bulk importing DTN content.
 *
 * @package migrate-ct
 */

/**
 * Migration CLI class extending the VIP_CLI_Command and Ct_Migrate class.
 */
class Migration_Cli extends \WPCOM_VIP_CLI_Command {

	/** Default batch size for processing. */
	const DEFAULT_BATCH_SIZE = 10;

	/**
	 * Seconds to pause between batches to reduce server load.
	 *
	 * @var int
	 */
	protected $sleep_time = 2;

	/**
	 * Core import logic instance.
	 *
	 * @var Ct_Migrate
	 */
	private $core;

	/**
	 * WP_Filesystem variable.
	 *
	 * @var WP_Filesystem_Direct
	 */
	private $filesystem;

	/**
	 * Mapping lookup service.
	 *
	 * @var Content_Mappings
	 */
	private $mappings;

	/**
	 * Constructor to initialize core logic and VIP CLI helpers.
	 */
	public function __construct() {
		parent::__construct();
		$this->core = new Ct_Migrate();
		$this->init_filesystem();
		$this->mappings = new Content_Mappings();
	}

	/**
	 * Initialize and authenticate the WP Filesystem API.
	 *
	 * Forces the 'direct' method, loads the necessary
	 * file.php includes, and sets $this->filesystem.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		add_filter(
			'filesystem_method',
			function () {
				return 'direct';
			}
		);

		WP_Filesystem();
		$this->filesystem = $wp_filesystem;
	}

	/**
	 * Example command.
	 *
	 * ## OPTIONS
	 *
	 * --csv=<path>
	 * : Absolute path to the CSV file containing one UUID per line.
	 *
	 * [--batch-size=<n>]
	 * : Number of UUIDs to process per batch. Default is 10.
	 *
	 * [--dry-run]
	 * : Simulate the import without writing any posts.
	 *
	 * [--yes]
	 * : Answer yes to any confirmation prompts.
	 *
	 * [--skipped-id-file=<path>]
	 * : Absolute path to the file where skipped UUIDs will be logged.
	 *
	 * [--email=<email>]
	 * : Email address to send the import report to.
	 *
	 * ## EXAMPLES
	 *
	 *     # Live import with default batch size
	 *     wp ct-content-import --csv=/path/to/uuids.csv
	 *
	 *     # Dry-run, batch of 20
	 *     wp ct-content-import --csv=/path/to/uuids.csv --batch-size=20 --dry-run
	 *
	 *     # Skip confirmation
	 *     wp ct-content-import --csv=/path/to/uuids.csv --yes
	 *
	 *     # Skipped csv with email
	 *     wp ct-content-import --skipped-id-file=/path/to/skipped-uuids.csv --email=email_id
	 *
	 * @param array $args       Store all the positional arguments.
	 * @param array $assoc_args Store all the associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {

		define( 'WP_IMPORTING', true );

		// Validate required --csv.
		if ( empty( $assoc_args['csv'] ) || ! is_string( $assoc_args['csv'] ) ) {
			WP_CLI::error( 'You must provide a CSV path using --csv=<path>' );
		}

		$csv_path = $assoc_args['csv'];
		// Validate CSV file path.
		if ( ! $this->filesystem->exists( $csv_path ) ) {
			WP_CLI::error( "Cannot read CSV file at: {$csv_path}" );
		}

		$batch_size = isset( $assoc_args['batch-size'] )
			? absint( $assoc_args['batch-size'] )
			: self::DEFAULT_BATCH_SIZE;

		$dry_run      = ! empty( $assoc_args['dry-run'] );
		$skipped_file = $assoc_args['skipped-id-file'] ?? '';
		$email        = sanitize_email( $assoc_args['email'] ?? '' );

		if ( $skipped_file ) {
			if ( ! is_string( $skipped_file ) ) {
				WP_CLI::error( 'Invalid path for skipped IDs file' );
			}
			$this->init_skipped_file( $skipped_file );
		}

		// Confirm before proceeding if not dry-run.
		if ( ! $dry_run ) {
			WP_CLI::confirm( "You are about to import content from: {$csv_path}. Continue?" );
		}

		$uuids = $this->parse_cli_csv( $csv_path );
		if ( empty( $uuids ) ) {
			WP_CLI::warning( 'No UUIDs found in CSV. Exiting.' );
			return;
		}
		$total         = count( $uuids );
		$total_batches = (int) ceil( $total / $batch_size );

		// Initialize counters.
		$processed_count = 0;
		$batch_count     = 0;
		$skipped_count   = 0;
		$imported_count  = 0;

		// Optimize performance during batch insert.
		$this->start_bulk_operation();
		$this->vip_inmemory_cleanup();

		$progress = WP_CLI\Utils\make_progress_bar( 'Importing Content', $total );
		$skipped  = array();
		$errors   = array();

		foreach ( array_chunk( $uuids, $batch_size ) as $batch ) {
			++$batch_count;
			$batch_start = microtime( true );

			WP_CLI::log(
				sprintf(
					'=== Starting batch %d of %d (UUIDs %d–%d) ===',
					$batch_count,
					$total_batches,
					( $processed_count + 1 ),
					min( $processed_count + $batch_size, $total )
				)
			);
			foreach ( $batch as $uuid ) {
				++$processed_count;
				$safe_uuid = esc_html( $uuid );
				WP_CLI::log(
					sprintf(
						'[%d/%d] Processing UUID: %s',
						$processed_count,
						$total,
						$safe_uuid
					)
				);
				if ( $dry_run ) {
					WP_CLI::line( "[DRY-RUN] Would import: {$safe_uuid}" );
				} else {
					try {
						$data = $this->core->fetch_news_data( $uuid, $this->core->get_api_token() );
						if ( ! $this->core->should_import_post( $data ) ) {
							$reason = $this->get_skip_reason( $data );
							WP_CLI::log(
								WP_CLI::colorize(
									"%Y Skipped UUID {$safe_uuid} — {$reason}%n"
								)
							);
							$skipped[] = array(
								'uuid'   => $uuid,
								'reason' => $reason,
							);
							++$skipped_count;
						} else {
							$post_id = $this->core->create_post( $data );
							WP_CLI::log(
								WP_CLI::colorize(
									"%G Imported UUID {$safe_uuid} → Post ID {$post_id}%n"
								)
							);
							++$imported_count;
						}
					} catch ( \Exception $e ) {
						$errors[] = array(
							'uuid'    => $uuid,
							'message' => $e->getMessage(),
						);
						WP_CLI::warning(
							sprintf(
								'Error on UUID %s: %s',
								$safe_uuid,
								sanitize_text_field( $e->getMessage() )
							)
						);
					}
				}
				$progress->tick();
				unset( $data, $reason, $post_id, $safe_uuid );
			}

			// Batch complete: timing & memory.
			$batch_time = microtime( true ) - $batch_start;
			$mem_usage  = size_format( memory_get_usage( false ) );
			$peak_mem   = size_format( memory_get_peak_usage( false ) );

			WP_CLI::log(
				sprintf(
					'--- Finished batch %d/%d in %.2f s — Memory: %s (peak %s) ---',
					$batch_count,
					$total_batches,
					$batch_time,
					$mem_usage,
					$peak_mem
				)
			);

			// Flush skipped entries to file after each batch.
			if ( $skipped_file && ! empty( $skipped ) ) {
				$this->write_skipped_batch( $skipped_file, $skipped );
				$skipped = array();
			}

			gc_collect_cycles();
			sleep( $this->sleep_time );
		}
		$progress->finish();
		$this->end_bulk_operation();
		$this->show_report( $total, $imported_count, $skipped_count, $errors );
		WP_CLI::success( $dry_run ? 'Dry-run complete.' : 'Import complete.' );
		// Handle email notification.
		if ( $email && $skipped_file ) {
			$this->send_email_report( $email, $skipped_file, $imported_count, $skipped_count, count( $errors ) );
		}
	}

	/**
	 * Determines the reason a post should be skipped based on routing metadata.
	 *
	 * @param array $data Post metadata.
	 * @return string Reason for skipping.
	 */
	private function get_skip_reason( $data ) {
		foreach ( $data['routing'] as $route ) {
			$handle = $route['handle'];

			// Check for known valid patterns.
			$valid_patterns = array(
				'/api-content/ct/en/ct_news/washington_insider',
				'/api-content/ct/en/ct_news/market_impact_weather',
				'/api-content/ct/en/ct_news/breaking_news',
			);

			if ( in_array( $handle, $valid_patterns, true ) ) {
				return 'Matched valid pattern: ' . $handle;
			}

			if ( ! empty( $handle ) && is_string( $handle )
					&& preg_match( '#/ct_news/ct_news_page_(6|7|8|9|10|11|12|13|14|15|16|17|18|19|20|21)$#', $handle )
			) {
				return 'Matched page pattern: ' . $handle;
			}

			// New: AG Policy composite blogs
			if ( ! empty( $handle ) && is_string( $handle )
			&& ( preg_match(
				'#^/api-content/ct/en/blog_folder/ct_ag_policy_blog_composite/agpolicy_blog_\d{2}$#', $handle ) 
				|| preg_match( '#^/api-content/ct/en/blog_folder/ct_an_urbans_rural/an_urbans_rural_view(?:\d{1,2})?$#', $handle )
			)
			) {
				return true;
			}

			if ( isset( $this->mappings->market_mappings[ $handle ] ) ) {
				return sprintf(
					'Market commentary: %s',
					$this->mappings->market_mappings[ $handle ]
				);
			}

			if ( isset( $this->mappings->strategy_handles[ $handle ] ) ) {
				return sprintf(
					'Market Strategies: %s',
					$this->mappings->strategy_handles[ $handle ]
				);
			}

			if ( '/api-content/ct/en/special/ct_fertilizer_index' === $handle
				|| '/api-content/ct/en/columns/fertilizer_weekly' === $handle
			) {
				return sprintf( 'Fuel & fertilizer: %s', $handle );
			}

			if ( ! empty( $route['pageMembers'] ) && is_array( $route['pageMembers'] ) ) {
				foreach ( $route['pageMembers'] as $member ) {
					if ( isset( $this->mappings->fuel_fertilizer_mappings[ $member ] ) ) {
						$sub = sanitize_title( $this->mappings->fuel_fertilizer_mappings[ $member ] );
						return sprintf( 'Fuel & fertilizer: %s', $sub );
					}

					if ( isset( $this->mappings->strategy_page_members[ $member ] ) ) {
						$sub = sanitize_title( $this->mappings->strategy_page_members[ $member ] );
						return sprintf( 'Market Strategies: %s', $sub );
					}
				}
			}

			// Build the alternation from your magazine sections.
			$sections = implode(
				'|',
				array_map(
					fn( $s ) => preg_quote( $s, '#' ),
					$this->mappings->magazine_sections
				)
			);

			$pattern = "#^/api-content/PFMag/($sections)/[^/]+_page_\\d{2}$#";

			if ( is_string( $handle ) && preg_match( $pattern, $handle ) ) {
				// Extract the section slug.
				if ( preg_match( '#/api-content/PFMag/([^/]+)/#', $handle, $m ) && ! empty( $m[1] ) ) {
					$parent = str_replace( '_', ' ', $m[1] );
				} else {
					$parent = 'magazine';
				}

				// Sanitize and strip tags from the title.
				$article = isset( $data['head']['title'] )
					? wp_strip_all_tags( $data['head']['title'] )
					: '';

				return sprintf(
					'Magazine import: parent="%s", article="%s"',
					sanitize_title( $parent ),
					sanitize_title( $article )
				);
			}

			if ( ! empty( $handle ) && is_string( $handle )
				&& preg_match(
					'#^/api-content/ct/en/columns/(vintage_iron|cash_market)$#',
					$handle,
					$m
				)
			) {
				$map         = array(
					'vintage_iron' => 'russ-vintage-iron',
					'cash_market'  => 'cash-market-moves',
				);
				$reason_slug = $map[ $m[1] ];
				return sprintf(
					'Columns import: parent="columns", sub="%s"',
					$reason_slug
				);
			}
		}
		return 'No matching routing handles found';
	}

	/**
	 * Parses a CSV of UUIDs.
	 *
	 * @param string $file_path Absolute file path to CSV.
	 * @return array List of sanitized UUIDs.
	 */
	private function parse_cli_csv( $file_path ) {
		$content = $this->filesystem->get_contents( $file_path );

		if ( false === $content ) {
			WP_CLI::error( 'Failed to read CSV file' );
		}

		$uuids = array();
		$lines = array_map( 'str_getcsv', explode( "\n", $content ) );

		// Skip header row.
		array_shift( $lines );

		foreach ( $lines as $line ) {
			if ( ! empty( $line[0] ) ) {
				$uuids[] = sanitize_text_field( $line[0] );
			}
		}

		return array_values( array_unique( $uuids ) );
	}

	/**
	 * Outputs a final summary report.
	 *
	 * @param int   $total   Total UUIDs from CSV.
	 * @param int   $imported_count Imported UUIDs count.
	 * @param int   $skipped_count Skipped UUIDs count.
	 * @param array $errors  UUIDs that failed with errors.
	 */
	private function show_report( $total, $imported_count, $skipped_count, $errors ) {
		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'Total'    => $total,
					'Imported' => $imported_count,
					'Skipped'  => $skipped_count,
					'Errors'   => count( $errors ),
				),
			),
			array( 'Total', 'Imported', 'Skipped', 'Errors' )
		);

		// Error details.
		if ( ! empty( $errors ) ) {
			WP_CLI::log( "\n" . WP_CLI::colorize( '%RErrors:%n' ) );
			foreach ( $errors as $error ) {
				WP_CLI::log(
					sprintf(
						'• %s - %s%s%s',
						$error['uuid'],
						WP_CLI::colorize( '%R' ),
						$error['message'],
						WP_CLI::colorize( '%n' )
					)
				);
			}
		}
	}

	/**
	 * Create (or overwrite) the “skipped IDs” CSV with header row.
	 *
	 * @param string $file_path Absolute path where the CSV should be created.
	 * @return void
	 */
	private function init_skipped_file( $file_path ) {
		if ( $this->filesystem->exists( $file_path ) ) {
			WP_CLI::confirm( 'Skipped IDs file already exists. Overwrite?' );
		}

		$this->filesystem->put_contents(
			$file_path,
			"UUID,Reason\n",
			FS_CHMOD_FILE
		);
	}

	/**
	 * Append a batch of skipped UUIDs to the skipped-IDs CSV.
	 *
	 * @param string   $file_path Absolute path to skipped-IDs CSV.
	 * @param string[] $skipped   Array of skipped entries to write.
	 * @return void
	 */
	private function write_skipped_batch( string $file_path, array $skipped ): void {
		$existing = $this->filesystem->get_contents( $file_path );
		if ( false === $existing ) {
			WP_CLI::warning( 'Could not read skipped file for append' );
			return;
		}

		$new_lines = '';
		foreach ( $skipped as $entry ) {
			$uuid       = str_replace( '"', '""', sanitize_text_field( $entry['uuid'] ) );
			$reason     = str_replace( '"', '""', sanitize_text_field( $entry['reason'] ) );
			$new_lines .= "\"{$uuid}\",\"{$reason}\"\n";
		}

		if ( ! $this->filesystem->put_contents( $file_path, $existing . $new_lines, FS_CHMOD_FILE ) ) {
			WP_CLI::warning( 'Failed to append to skipped file' );
		}
	}

	/**
	 * Validate that a file path resides under an allowed directory.
	 *
	 * @param string $path File path to validate.
	 * @return bool True if under an allowed folder, false otherwise.
	 */
	private function is_valid_file_path( $path ) {
		$allowed_dirs = array(
			WP_CONTENT_DIR . '/uploads',
			get_temp_dir(),
		);

		foreach ( $allowed_dirs as $dir ) {
			if ( 0 === strpos( $this->filesystem->find_folder( $path ), $this->filesystem->find_folder( $dir ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Send an email report of skipped IDs to the given address.
	 *
	 * @param string $email          Recipient email address.
	 * @param string $file_path      Absolute path to skipped-IDs CSV.
	 * @param int    $imported_count Number of UUIDs successfully imported.
	 * @param int    $skipped_count  Number of UUIDs skipped.
	 * @param int    $error_count    Number of UUIDs that errored.
	 * @return void
	 */
	private function send_email_report( $email, $file_path, $imported_count, $skipped_count, $error_count ) {
		// Validate email before proceeding.
		if ( ! is_email( $email ) ) {
			WP_CLI::warning( "Invalid email address: $email" );
			return;
		}

		// Verify file existence and content.
		if ( ! $this->filesystem->exists( $file_path ) ) {
			WP_CLI::warning( "Skipped file not found: $file_path" );
			return;
		}

		$file_size = $this->filesystem->size( $file_path );
		if ( is_wp_error( $file_size ) ) {
			WP_CLI::warning( 'Could not determine skipped-file size: ' . $file_size->get_error_message() );
			return;
		}
		if ( $file_size <= 0 ) {
			WP_CLI::log( 'No skipped entries to report' );
			return;
		}

		// Construct message with diagnostic info.
		$message = sprintf(
			"DTN Import Report\n\nSite: %s\nDate: %s\nImported: %d\nSkipped:  %d\nErrors: %d\n",
			site_url(),
			gmdate( 'Y-m-d H:i:s' ),
			$imported_count,
			$skipped_count,
			$error_count
		);

		// Send with proper headers.
		$result = wp_mail(
			$email,
			sprintf( '[DTN Import] Report for %s', site_url() ),
			$message,
			array(
				'Content-Type: text/plain; charset=UTF-8',
				sprintf( 'From: %s', get_option( 'admin_email' ) ),
			),
			array( $file_path )
		);

		// Add diagnostic logging.
		if ( $result ) {
			WP_CLI::log( sprintf( 'Report emailed to %s', $email ) );
		} else {
			WP_CLI::warning( 'Email delivery failed. Check server mail logs.' );
		}
	}
}
