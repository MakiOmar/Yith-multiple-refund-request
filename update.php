<?php
/**
 * Update
 *
 * @package Yith
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bridge_update_checker = Puc_v4_Factory::buildUpdateChecker(
	'https://github.com/MakiOmar/Yith-multiple-refund-request/',
	__FILE__,
	AWYCRB_PLUGIN_SLUG
);

// Set the branch that contains the stable release.
$bridge_update_checker->setBranch( 'main' );
