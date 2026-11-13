<?php
/**
 * Smoke test: the plugin loads inside WordPress.
 *
 * @package Shakewell\PluginTemplate
 */

namespace Shakewell\PluginTemplate\Tests;

use WP_UnitTestCase;

/**
 * Replace with the plugin's real tests. One test per behaviour, named for what it proves.
 */
final class PluginTest extends WP_UnitTestCase {

	public function test_plugin_boots_on_plugins_loaded(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( \Shakewell\PluginTemplate\Plugin::class, 'boot' ) ) );
	}
}
