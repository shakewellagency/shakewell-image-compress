<?php
/**
 * Plugin bootstrap and the upload hooks.
 *
 * @package Shakewell\ImageCompress
 */

namespace Shakewell\ImageCompress;

defined( 'ABSPATH' ) || exit;

/**
 * Sends heavy JPEG uploads down WordPress's own "-scaled" path, and adds the WP-CLI command.
 *
 * WordPress already scales uploads above `big_image_size_threshold`: it saves a "-scaled" copy at
 * the editor's quality, serves that as the full size and keeps the upload as `original_image`.
 * For a heavy JPEG the threshold is lowered to two pixels under its long edge, so the same path
 * runs and the upload is never discarded. Two is the least core acts on: it refuses a resize that
 * lands within one pixel of the original.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		add_filter( 'big_image_size_threshold', array( self::class, 'threshold' ), 10, 4 );
		add_action( 'delete_attachment', array( Reencoder::class, 'delete_previous_sizes' ) );
		add_filter( 'wp_editor_set_quality', array( self::class, 'quality' ), 10, 2 );
		add_filter( 'wp_image_editors', array( self::class, 'editors' ) );
		add_filter( 'wp_client_side_media_processing_enabled', array( self::class, 'client_side_media' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/Cli.php';
			\WP_CLI::add_command( 'shakewell-image-compress', Cli::class );
		}
	}

	/**
	 * Lowers the big-image threshold for a heavy JPEG so core makes its "-scaled" copy.
	 *
	 * @param int|false $threshold Core's threshold, or false when scaling is switched off.
	 * @param array     $imagesize Result of wp_getimagesize().
	 * @param string    $file      Path of the upload.
	 * @param int       $attachment_id Attachment ID (0 for a file not yet an attachment).
	 * @return int|false
	 */
	public static function threshold( $threshold, $imagesize = array(), $file = '', $attachment_id = 0 ) {
		unset( $attachment_id );

		// Scaling switched off on purpose (core's documented false): leave it off.
		if ( ! $threshold || ! Settings::get( 'enabled' ) ) {
			return $threshold;
		}

		$width     = (int) ( $imagesize[0] ?? 0 );
		$height    = (int) ( $imagesize[1] ?? 0 );
		$long_edge = max( $width, $height );

		// Over the normal threshold core scales it anyway, at the editor's quality.
		if ( $long_edge > $threshold ) {
			return $threshold;
		}

		// The type read from the file, not its extension: a PNG named .jpg would be saved as a JPEG
		// and lose its transparency.
		if ( 'image/jpeg' !== ( $imagesize['mime'] ?? '' ) || $long_edge < Settings::get( 'min_edge' ) ) {
			return $threshold;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file simply is not heavy.
		$bytes = (int) @filesize( (string) $file );
		if ( ! Reencoder::is_heavy( $bytes, $width, $height ) ) {
			return $threshold;
		}

		// Re-encoding drops the colour profile, so CMYK and non-sRGB images keep core's normal behaviour.
		return null === Reencoder::would_change_colour( (string) $file, (array) $imagesize ) ? $long_edge - 2 : $threshold;
	}

	/**
	 * Applies the target quality to JPEGs, only when the site set one.
	 *
	 * @param int    $quality Quality WordPress chose.
	 * @param string $mime    Output mime type.
	 */
	public static function quality( $quality, $mime = '' ): int {
		return ( 'image/jpeg' === $mime && Settings::is_set( 'quality' ) ) ? (int) Settings::get( 'quality' ) : (int) $quality;
	}

	/**
	 * Limits and orders the image editors, only when the site set a list.
	 *
	 * @param string[] $editors Editor class names.
	 * @return string[]
	 */
	public static function editors( $editors ) {
		if ( ! Settings::is_set( 'editors' ) ) {
			return $editors;
		}
		$classes = array(
			'imagick' => 'WP_Image_Editor_Imagick',
			'gd'      => 'WP_Image_Editor_GD',
		);
		$chosen  = array();
		foreach ( array_map( 'trim', explode( ',', strtolower( (string) Settings::get( 'editors' ) ) ) ) as $name ) {
			if ( isset( $classes[ $name ] ) ) {
				$chosen[] = $classes[ $name ];
			}
		}
		return $chosen ? $chosen : $editors;
	}

	/**
	 * Keeps uploads on the server, where the threshold above can act on them.
	 *
	 * With WordPress 7.1's client-side media processing on, the block editor resizes uploads in the
	 * browser and core turns the threshold off for them.
	 *
	 * @param bool $enabled Core's choice.
	 */
	public static function client_side_media( $enabled ): bool {
		return Settings::get( 'client_side_media_off' ) ? false : (bool) $enabled;
	}
}
