<?php
/**
 * Plugin Name: WC Multi Return Bridge for YITH
 * Description: Allows customers to request multiple product returns per order integrated with YITH Advanced Refund System.
 * Version: 1.0.13
 * Author: Makiomar
 * Requires Plugins: WooCommerce, YITH Advanced Refund System
 * Text Domain: wc-multi-return
 * Domain Path: /languages/
 *
 * @package Yith
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Holds plugin's slug
 *
 * @const
 */
define( 'AWYCRB_PLUGIN_SLUG', plugin_basename( __FILE__ ) );

/**
 * Holds plugin PATH
 *
 * @const
 */
define( 'AWYCRB_DIR', wp_normalize_path( plugin_dir_path( __FILE__ ) ) );

define( 'AWYCRB_FILE', __FILE__ );
// Load plugin textdomain.
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'wc-multi-return', false, dirname( AWYCRB_PLUGIN_SLUG ) . '/languages/' );
	}
);

require AWYCRB_DIR . 'plugin-update-checker/plugin-update-checker.php';
require AWYCRB_DIR . 'update.php';
require AWYCRB_DIR . 'class-wc-multi-return-bridge.php';
