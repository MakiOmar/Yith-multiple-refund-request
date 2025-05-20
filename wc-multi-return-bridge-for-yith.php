<?php
/**
 * Plugin Name: WC Multi Return Bridge for YITH
 * Description: Allows customers to request multiple product returns per order integrated with YITH Advanced Refund System.
 * Version: 1.0.0
 * Author: Makiomar
 * Requires Plugins: WooCommerce, YITH Advanced Refund System
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

require ANOE_DIR . 'plugin-update-checker/plugin-update-checker.php';
require ANOE_DIR . 'update.php';
require ANOE_DIR . 'class-wc--multi-return-bridge.php';
