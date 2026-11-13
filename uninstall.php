<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * @package Shakewell\PluginTemplate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Delete this plugin's options, custom tables and scheduled events here.
// Never delete content editors created unless the plugin's settings say to.
