<?php
/**
 * Plugin Name:       Shakewell Image Compress
 * Plugin URI:        https://github.com/shakewellagency/shakewell-image-compress
 * Description:       Re-encodes JPEGs saved at near-maximum quality on upload, keeping the upload as the original, and re-encodes the heaviest existing JPEGs in bulk from WP-CLI.
 * Version:           0.2.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-image-compress
 *
 * @package Shakewell\ImageCompress
 */

namespace Shakewell\ImageCompress;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';
const FILE    = __FILE__;

foreach ( array( 'Settings', 'Reencoder', 'Plugin' ) as $shakewell_image_compress_class ) {
	require_once __DIR__ . '/src/' . $shakewell_image_compress_class . '.php';
}
unset( $shakewell_image_compress_class );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
