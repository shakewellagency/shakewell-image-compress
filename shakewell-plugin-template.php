<?php
/**
 * Plugin Name:       Shakewell Plugin Template
 * Plugin URI:        https://github.com/shakewellagency/shakewell-plugin-template
 * Description:       Starting point for Shakewell WordPress plugins. Replace this description.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-plugin-template
 *
 * @package Shakewell\PluginTemplate
 */

namespace Shakewell\PluginTemplate;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Plugin.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
