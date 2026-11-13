<?php
/**
 * Plugin bootstrap.
 *
 * @package Shakewell\PluginTemplate
 */

namespace Shakewell\PluginTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress. Keep this thin: register hooks here, put the work in small classes.
 *
 * Translations need no code: WordPress loads them for the plugin's slug automatically.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
	}
}
