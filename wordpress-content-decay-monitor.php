<?php
/**
 * Plugin Name: Content Decay Monitor
 * Plugin URI: https://github.com/kamranhajhossein/wordpress-content-decay-monitor
 * Description: Identify outdated WordPress content, prioritize updates, schedule reviews, and export actionable SEO reports.
 * Version: 1.1.1
 * Author: Kamran Hajhossein
 * Author URI: https://kamranh.com
 * Text Domain: wordpress-content-decay-monitor
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'WCDM_VERSION', '1.1.1' );
define( 'WCDM_FILE', __FILE__ );
define( 'WCDM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCDM_URL', plugin_dir_url( __FILE__ ) );

require_once WCDM_PATH . 'includes/class-wcdm-analyzer.php';
require_once WCDM_PATH . 'includes/class-wcdm-cron.php';
require_once WCDM_PATH . 'includes/class-wcdm-admin.php';

final class WCDM_Plugin {
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'boot' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wordpress-content-decay-monitor', false, dirname( plugin_basename( WCDM_FILE ) ) . '/languages' );
	}

	public function boot() {
		WCDM_Analyzer::instance();
		WCDM_Cron::instance();
		if ( is_admin() ) {
			WCDM_Admin::instance();
		}
	}

	public static function activate() {
		if ( false === get_option( 'wcdm_settings', false ) ) {
			add_option( 'wcdm_settings', WCDM_Analyzer::default_settings() );
		}
		WCDM_Cron::schedule();
		if ( ! wp_next_scheduled( 'wcdm_initial_scan' ) ) {
			wp_schedule_single_event( time() + 10, 'wcdm_initial_scan' );
		}
	}

	public static function deactivate() {
		WCDM_Cron::unschedule();
	}
}

register_activation_hook( __FILE__, array( 'WCDM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCDM_Plugin', 'deactivate' ) );

WCDM_Plugin::instance();
