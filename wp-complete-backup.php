<?php
/**
 * Plugin Name: WP Complete Backup
 * Plugin URI: #
 * Description: Full site backup (database + media/files) plus per-page/post export with images and metadata. Download backups as ZIP straight from the admin dashboard.
 * Version: 1.0
 * Author: Dhananjay Gupta
 * License: GPL v2 or later
 * Text Domain: wp-complete-backup
 * Requires PHP: 7.4
 */

// Block direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPCB_VERSION', '2.1.0' );
define( 'WPCB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCB_BACKUP_DIR', WP_CONTENT_DIR . '/wpcb-backups' );
define( 'WPCB_BACKUP_URL', content_url( '/wpcb-backups' ) );

/**
 * Create backup directory + protect it on activation
 */
function wpcb_activate() {
	if ( ! file_exists( WPCB_BACKUP_DIR ) ) {
		wp_mkdir_p( WPCB_BACKUP_DIR );
	}

	// Prevent direct web access to backup files
	$htaccess = WPCB_BACKUP_DIR . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
	}

	$index = WPCB_BACKUP_DIR . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}

	// web.config for IIS servers
	$webconfig = WPCB_BACKUP_DIR . '/web.config';
	if ( ! file_exists( $webconfig ) ) {
		file_put_contents( $webconfig, "<configuration>\n<system.webServer>\n<security>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</security>\n</system.webServer>\n</configuration>" );
	}
}
register_activation_hook( __FILE__, 'wpcb_activate' );

/**
 * Includes
 */
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-database.php';
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-database-import.php';
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-files.php';
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-files-import.php';
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-search-replace.php';
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-content-export.php';
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-admin.php';
require_once WPCB_PLUGIN_DIR . 'includes/class-wpcb-ajax.php';

/**
 * Boot the plugin
 */
function wpcb_init() {
	new WPCB_Admin();
	new WPCB_Ajax();
}
add_action( 'plugins_loaded', 'wpcb_init' );


function wpcb_maybe_raise_upload_limits() {
	if ( isset( $_POST['action'] ) && $_POST['action'] === 'wpcb_upload_backup' ) {
		@ini_set( 'upload_max_filesize', '512M' );
		@ini_set( 'post_max_size', '512M' );
		@ini_set( 'max_execution_time', '0' );
		@ini_set( 'max_input_time', '0' );
		@ini_set( 'memory_limit', '512M' );
	}
}
add_action( 'init', 'wpcb_maybe_raise_upload_limits', 1 );


function wpcb_raise_limits() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 );
	}
	@ini_set( 'memory_limit', '512M' );
	@ini_set( 'upload_max_filesize', '512M' );
	@ini_set( 'post_max_size', '512M' );
	@ini_set( 'max_input_time', '0' );
}
