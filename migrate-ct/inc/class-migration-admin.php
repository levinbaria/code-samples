<?php
/**
 * Class to Content Import from admin side
 *
 * @package migrate-ct
 */

/**
 * Migration_Admin class for handling admin-side migration functionality
 */
class Migration_Admin extends Ct_Migrate {


	/** Where to store the CSV of skipped and processed. */
	const REPORT_OPTION = 'ct_import_report';

	/**
	 * Mapping lookup service.
	 *
	 * @var Content_Mappings
	 */
	private $mappings;

	/**
	 * Construct method.
	 */
	public function __construct() {
		parent::__construct();
		$this->mappings = new Content_Mappings();
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'wp_ajax_ct_start_import', array( $this, 'ajax_start_import' ) );
		add_action( 'wp_ajax_ct_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_ct_import_status', array( $this, 'ajax_import_status' ) );
		add_action( 'wp_ajax_ct_download_report', array( $this, 'ajax_download_report' ) );
	}

	/**
	 * Register admin scripts and styles
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function admin_scripts( $hook ) {
		if ( 'tools_page_ct-content-import' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'ct-custom-batch',
			plugin_dir_url( __FILE__ ) . 'js/api-content-import.js',
			array( 'jquery' ),
			null,
			true
		);

		wp_localize_script(
			'ct-custom-batch',
			'ctBatch',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ct_batch_nonce' ),
				'batch_size' => self::BATCH_SIZE,
				'report_url' => esc_url( admin_url( 'admin-ajax.php?action=ct_download_report&nonce=' . wp_create_nonce( 'ct_batch_nonce' ) ) ),
			)
		);

		wp_enqueue_style(
			'ct-admin-css',
			plugin_dir_url( __FILE__ ) . 'css/migration-admin.css',
			array(),
			'1.0'
		);
	}

	/**
	 * Add admin menu page for migration
	 */
	public function add_admin_page() {
		add_submenu_page(
			'tools.php',
			__( 'DTN Content Import', 'migrate-ct' ),
			__( 'DTN Content Import', 'migrate-ct' ),
			'manage_options',
			'ct-content-import',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Inline the upload form and progress UI.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'migrate-ct' ) );
		}
		// Load any previous report.
		$report = get_option( self::REPORT_OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DTN Content Import', 'migrate-ct' ); ?></h1>
			<div class="ct-content-import__wrapper">
				<form id="ct-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'ct_import_nonce', 'security' ); ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="csv_file"><?php esc_html_e( 'CSV File', 'migrate-ct' ); ?></label></th>
								<td><input type="file" id="csv_file" name="csv_file" accept=".csv" required /></td>
							</tr>
						</table>
					<?php submit_button( __( 'Start Import', 'migrate-ct' ), 'primary', 'ct-import-submit' ); ?>
				</form>
				<div class="ct-import-progress__wrapper">
					<h2><?php esc_html_e( 'Progress', 'migrate-ct' ); ?></h2>
					<div id="ct-import-progress" aria-live="polite">
					<?php echo esc_html__( 'Not started', 'migrate-ct' ); ?>
					</div>
				</div>
				<?php if ( $report ) : ?>
					<div id="ct-import-report">
	
						<h2><?php esc_html_e( 'Last Import Report', 'migrate-ct' ); ?></h2>
						<p>
							<?php
							printf(
								esc_html__( 'Imported: %1$d, Skipped: %2$d, Errors: %3$d', 'migrate-ct' ),
								esc_html( $report['imported'] ),
								esc_html( $report['skipped'] ),
								esc_html( $report['errors'] )
							);
							?>
						</p>
						<p id="ct-download-wrapper">
							<a
								class="button"
								href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=ct_download_report&nonce=' . wp_create_nonce( 'ct_batch_nonce' ) ) ); ?>"
								target="_blank"
							>
									<?php esc_html_e( 'Download CSV report', 'migrate-ct' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
			</div>


		</div>
		<?php
	}

	/**
	 * Handle AJAX request to start import
	 *
	 * @throws \Exception When file processing fails.
	 */
	public function ajax_start_import() {
		check_ajax_referer( 'ct_import_nonce', 'security' );

		try {

			if ( empty( $_FILES['csv_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				throw new \Exception( __( 'No CSV uploaded', 'migrate-ct' ) );
			}

			$file  = array_map( 'sanitize_text_field', wp_unslash( $_FILES['csv_file'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$uuids = $this->parse_csv( $file );
			$total = count( $uuids );

			if ( 0 === $total ) {
				throw new \Exception( __( 'No valid UUIDs found in CSV', 'migrate-ct' ) );
			}

			set_transient( self::TRANSIENT_PREFIX . 'queue', $uuids, DAY_IN_SECONDS );
			update_option( self::TRANSIENT_PREFIX . 'total', $total );
			update_option( self::TRANSIENT_PREFIX . 'processed', 0 );
			update_option(
				self::REPORT_OPTION,
				array(
					'imported' => 0,
					'skipped'  => 0,
					'errors'   => 0,
					// we’ll build the CSV rows incrementally.
					'rows'     => array( array( 'UUID', 'Result', 'Info' ) ),
				)
			);

			wp_send_json_success(
				array(
					'total'      => $total,
					'batch_size' => self::BATCH_SIZE,
				)
			);

		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Process a batch of UUIDs via AJAX
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'ct_batch_nonce', 'nonce' );

		try {
			$queue     = get_transient( self::TRANSIENT_PREFIX . 'queue' );
			$processed = (int) get_option( self::TRANSIENT_PREFIX . 'processed', 0 );
			$total     = (int) get_option( self::TRANSIENT_PREFIX . 'total', 0 );
			$report    = (array) get_option( self::REPORT_OPTION, array() );
			if ( empty( $queue ) ) {
				$this->cleanup();
				wp_send_json_success( array( 'complete' => true ) );
			}

			$batch    = array_splice( $queue, 0, self::BATCH_SIZE );
			$token    = $this->get_api_token();
			$imported = $skipped = $errors = 0;

			foreach ( $batch as $uuid ) {
				try {
					$data = $this->fetch_news_data( $uuid, $token );
					if ( ! $this->should_import_post( $data ) ) {
						$reason = $this->get_skip_reason( $data );
						++$skipped;
						$report['rows'][] = array( $uuid, 'skipped', sanitize_text_field( $reason ) );
					} else {
						$post_id = $this->create_post( $data );
						++$imported;
						$report['rows'][] = array( $uuid, 'imported', (int) $post_id );
					}
				} catch ( \Exception $e ) {
					++$errors;
					$report['rows'][] = array( $uuid, 'error', sanitize_text_field( $e->getMessage() ) );
				}
			}
			$processed += count( $batch );

			set_transient( self::TRANSIENT_PREFIX . 'queue', $queue, DAY_IN_SECONDS );
			update_option( self::TRANSIENT_PREFIX . 'processed', $processed );

			$report['imported'] += $imported;
			$report['skipped']  += $skipped;
			$report['errors']   += $errors;
			update_option( self::REPORT_OPTION, $report );

			wp_send_json_success(
				array(
					'processed' => $processed,
					'remaining' => count( $queue ),
					'total'     => $total,
				)
			);

		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message'   => $e->getMessage(),
					'processed' => $processed,
					'remaining' => count( $queue ),
				)
			);
		}
	}

	/**
	 * Parse CSV file for UUIDs
	 *
	 * @param array $file File array from $_FILES.
	 * @return array Array of UUIDs.
	 * @throws Exception When file processing fails.
	 */
	private function parse_csv( $file ) {
		$uuids = array();

		// Initialize WP Filesystem
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$initialized = WP_Filesystem();
		if ( ! $initialized ) {
			throw new Exception( 'Failed to initialize WP Filesystem.' );
		}

		$file_path = $file['tmp_name'];
		if ( ! $wp_filesystem->exists( $file_path ) ) {
			throw new Exception( 'Uploaded file not found.' );
		}

		$contents = $wp_filesystem->get_contents( $file_path );
		if ( false === $contents ) {
			throw new Exception( 'Failed to read CSV file.' );
		}

		$lines = explode( "\n", $contents );
		// Remove header
		array_shift( $lines );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			$data = str_getcsv( $line );
			if ( isset( $data[0] ) ) {
				$uuids[] = sanitize_text_field( $data[0] );
			}
		}

		return array_values( array_unique( $uuids ) );
	}

	/**
	 * Get current import status & report via AJAX.
	 */
	public function ajax_import_status() {
		check_ajax_referer( 'ct_batch_nonce', 'nonce' );

		$processed = (int) get_option( self::TRANSIENT_PREFIX . 'processed', 0 );
		$total     = (int) get_option( self::TRANSIENT_PREFIX . 'total', 0 );
		$report    = (array) get_option( self::REPORT_OPTION, array() );

		wp_send_json_success(
			array(
				'processed' => $processed,
				'total'     => $total,
				'report'    => array(
					'imported' => $report['imported'] ?? 0,
					'skipped'  => $report['skipped'] ?? 0,
					'errors'   => $report['errors'] ?? 0,
				),
			)
		);
	}

	/**
	 * Download the full CSV report.
	 */
	public function ajax_download_report() {
		check_ajax_referer( 'ct_batch_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'migrate-ct' ), '', array( 'response' => 403 ) );
		}

		// Grab the report rows from the DB.
		$report = (array) get_option( self::REPORT_OPTION, array() );
		if ( empty( $report['rows'] ) ) {
			wp_die( esc_html__( 'No report available', 'migrate-ct' ), '', array( 'response' => 404 ) );
		}

		// Build CSV content in a string.
		$csv_lines = array();
		foreach ( $report['rows'] as $row ) {
			$sanitized_row = array_map(
				static function ( $field ) {
					$field = sanitize_text_field( (string) $field );
					$field = str_replace( '"', '""', $field );
					return "\"$field\"";
				},
				$row
			);

			$csv_lines[] = implode( ',', $sanitized_row );
		}

		$csv_content = implode( "\r\n", $csv_lines );

		// Send CSV headers.
		header( 'Content-Type: text/csv; charset=' . get_bloginfo( 'charset' ) );
		header( 'Content-Disposition: attachment; filename="ct-import-report.csv"' );
		header( 'Content-Length: ' . strlen( $csv_content ) );

		// Output and exit.
		echo $csv_content; // phpcs:ignore
		exit;
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
}