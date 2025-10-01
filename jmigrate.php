<?php
/**
 * Plugin Name: JMigrate
 * Description: Simple migration utility (Tier A).
 * Version: 0.1.0
 * Author: Josh
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'JMIGRATE_PLUGIN_VERSION' ) ) {
    define( 'JMIGRATE_PLUGIN_VERSION', '0.1.0' );
}

if ( ! defined( 'JMIGRATE_PLUGIN_DIR' ) ) {
    define( 'JMIGRATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'JMIGRATE_PLUGIN_URL' ) ) {
    define( 'JMIGRATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}


if ( ! function_exists( 'jmigrate_get_archive_directory' ) ) {
    /**
     * Returns the absolute path to the JMigrate uploads directory.
     *
     * @return string
     */
    function jmigrate_get_archive_directory() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return '';
        }

        return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'jmigrate';
    }
}

if ( ! function_exists( 'jmigrate_ensure_archive_directory' ) ) {
    /**
     * Ensures the JMigrate uploads directory exists.
     *
     * @return string The directory path or empty string on failure.
     */
    function jmigrate_ensure_archive_directory() {
        $directory = jmigrate_get_archive_directory();
        if ( '' === $directory ) {
            return '';
        }

        if ( ! wp_mkdir_p( $directory ) ) {
            return '';
        }

        return $directory;
    }
}

if ( ! function_exists( 'jmigrate_activate_plugin' ) ) {
    /**
     * Runs on plugin activation.
     */
    function jmigrate_activate_plugin() {
        jmigrate_ensure_archive_directory();
    }
}

register_activation_hook( __FILE__, 'jmigrate_activate_plugin' );
add_action( 'init', 'jmigrate_ensure_archive_directory' );
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-export-exception.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-import-exception.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-logger-interface.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-cli-logger.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-array-logger.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-exporter.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-job-manager.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-job-logger.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-importer.php';
require_once JMIGRATE_PLUGIN_DIR . 'includes/class-jmigrate-cli-command.php';

if ( is_admin() ) {
    require_once JMIGRATE_PLUGIN_DIR . 'includes/admin/class-jmigrate-admin-page.php';
    new JMigrate_Admin_Page();
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'JMigrate_CLI_Command' ) ) {
    \WP_CLI::add_command( 'jmigrate', 'JMigrate_CLI_Command' );
}
