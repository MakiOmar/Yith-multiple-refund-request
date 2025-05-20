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

$bridge_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/MakiOmar/Yith-multiple-refund-request/',
	AWYCRB_FILE,
	AWYCRB_PLUGIN_SLUG
);

// Set the branch that contains the stable release.
$bridge_update_checker->setBranch( 'main' );
