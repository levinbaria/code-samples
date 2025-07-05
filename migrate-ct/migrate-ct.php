<?php
/**
 * Plugin Name: Content Migration
 * Description: Plugin is for Migration.
 * Author: Levin Baria
 * Version: 1.0.0
 * Text Domain : migrate-ct
 *
 * @package migrate-ct
 */

if ( ! defined( 'CT_MIGRATION_PLUGIN_PATH' ) ) {
	define( 'CT_MIGRATION_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

$temp_dir = 'wp-content/temp/';
if ( defined( 'VIP_GO_APP_ENVIRONMENT' ) ) {
	if ( 'production' === VIP_GO_APP_ENVIRONMENT || 'develop' === VIP_GO_APP_ENVIRONMENT ) {
		$temp_dir = get_temp_dir();
	}
}

define( 'CT_TEMP_DIR_PATH', $temp_dir );

// Core functionality.
require_once __DIR__ . '/inc/class-ct-migrate.php';
require_once __DIR__ . '/inc/class-content-mappings.php';


// Admin UI only when in wp-admin.
if ( is_admin() ) {
	require_once __DIR__ . '/inc/class-migration-admin.php';
	new Migration_Admin();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	error_reporting( E_ALL );
	ini_set( 'display_errors', 1 );

	require_once __DIR__ . '/inc/class-migration-cli.php';
	WP_CLI::add_command( 'content-import', 'Migration_Cli' );
}
class_exists( 'WPCOM_VIP_CLI_Command' );
