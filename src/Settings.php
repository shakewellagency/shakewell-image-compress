<?php
/**
 * Settings: a constant or environment variable, then a filter.
 *
 * @package Shakewell\ImageCompress
 */

namespace Shakewell\ImageCompress;

defined( 'ABSPATH' ) || exit;

/**
 * Every setting is `SHAKEWELL_IMAGE_COMPRESS_<KEY>` as a PHP constant or an environment variable,
 * and can be changed last by the filter `shakewell_image_compress_<key>`.
 */
final class Settings {

	/**
	 * Setting key => default. The default's type decides how a constant or environment value is read.
	 */
	const DEFAULTS = array(
		// Compress JPEGs on upload.
		'enabled'               => true,
		// Bytes per pixel above which a JPEG counts as saved at near-maximum quality.
		'bytes_per_pixel'       => 0.6,
		// Images with a long edge under this many pixels are left alone.
		'min_edge'              => 100,
		// JPEG quality of the re-encoded copy. Unset: WordPress's own editor quality (82).
		'quality'               => 82,
		// Bulk only: files at least this many bytes count as heavy whatever their quality.
		'min_bytes'             => 512000,
		// Bulk only: longest side after the re-encode. Smaller images are never enlarged.
		'max_edge'              => 2560,
		// Bulk only: keep a re-encode only if it saves at least this percentage.
		'min_savings'           => 20,
		// WordPress 7.1 resizes uploads in the browser by default, which skips this plugin.
		'client_side_media_off' => true,
		// Comma-separated image editors to use, in order: imagick, gd. Empty: WordPress decides.
		'editors'               => '',
	);

	/**
	 * Whether the site set this key by constant, environment variable or filter.
	 *
	 * @param string $key Setting key.
	 */
	public static function is_set( string $key ): bool {
		return null !== self::raw( $key ) || (bool) has_filter( 'shakewell_image_compress_' . $key );
	}

	/**
	 * The setting's value, cast to the type of its default.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$default = self::DEFAULTS[ $key ];
		$value   = self::raw( $key );
		if ( null === $value ) {
			$value = $default;
		}
		$value = apply_filters( 'shakewell_image_compress_' . $key, $value );

		if ( is_bool( $default ) ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
		if ( is_int( $default ) ) {
			return (int) $value;
		}
		return is_float( $default ) ? (float) $value : (string) $value;
	}

	/**
	 * The constant or environment value, or null when neither is set.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private static function raw( string $key ) {
		$name = 'SHAKEWELL_IMAGE_COMPRESS_' . strtoupper( $key );
		if ( defined( $name ) ) {
			return constant( $name );
		}
		$env = getenv( $name );
		return ( false === $env || '' === $env ) ? null : $env;
	}
}
