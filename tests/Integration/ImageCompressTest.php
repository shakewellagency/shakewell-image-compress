<?php
/**
 * Settings, the upload hooks and the bulk re-encode.
 *
 * @package Shakewell\ImageCompress
 */

namespace Shakewell\ImageCompress\Tests;

use Shakewell\ImageCompress\Plugin;
use Shakewell\ImageCompress\Reencoder;
use Shakewell\ImageCompress\Settings;
use WP_UnitTestCase;

/**
 * One test per behaviour, named for what it proves.
 */
final class ImageCompressTest extends WP_UnitTestCase {

	/**
	 * Writes a JPEG. Noise at quality 100 is heavy; a flat colour is light.
	 *
	 * @param string $name  File name.
	 * @param bool   $noisy Heavy when true.
	 * @param int    $w     Width.
	 * @param int    $h     Height.
	 */
	private function jpeg( string $name, bool $noisy = true, int $w = 640, int $h = 416 ): string {
		$path = get_temp_dir() . $name;
		$im   = imagecreatetruecolor( $w, $h );
		if ( $noisy ) {
			mt_srand( 7 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- repeatable fixture.
			for ( $x = 0; $x < $w; $x += 2 ) {
				for ( $y = 0; $y < $h; $y += 2 ) {
					imagefilledrectangle( $im, $x, $y, $x + 1, $y + 1, imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
				}
			}
		}
		imagejpeg( $im, $path, 100 );
		return $path;
	}

	/**
	 * Adds an upload without the upload hook acting, as a library that predates the plugin has it.
	 *
	 * @param string $file Path of a fixture.
	 */
	private function legacy_attachment( string $file ): int {
		add_filter( 'shakewell_image_compress_enabled', '__return_false' );
		$id = self::factory()->attachment->create_upload_object( $file );
		remove_filter( 'shakewell_image_compress_enabled', '__return_false' );
		return $id;
	}

	private function opts( array $over = array() ): array {
		return $over + array(
			'min_bytes'   => 512000,
			'max_edge'    => 2560,
			'min_savings' => 20,
			'limit'       => 0,
		);
	}

	public function tear_down(): void {
		putenv( 'SHAKEWELL_IMAGE_COMPRESS_QUALITY' );
		parent::tear_down();
	}

	public function test_plugin_boots_on_plugins_loaded(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ) );
	}

	public function test_settings_default_environment_and_filter_in_that_order(): void {
		$this->assertSame( 82, Settings::get( 'quality' ) );
		$this->assertFalse( Settings::is_set( 'quality' ) );

		putenv( 'SHAKEWELL_IMAGE_COMPRESS_QUALITY=70' );
		$this->assertSame( 70, Settings::get( 'quality' ) );
		$this->assertTrue( Settings::is_set( 'quality' ) );

		add_filter( 'shakewell_image_compress_quality', static fn() => 60 );
		$this->assertSame( 60, Settings::get( 'quality' ) );
	}

	public function test_settings_read_booleans_and_floats_by_the_type_of_the_default(): void {
		putenv( 'SHAKEWELL_IMAGE_COMPRESS_ENABLED=false' );
		putenv( 'SHAKEWELL_IMAGE_COMPRESS_BYTES_PER_PIXEL=0.45' );
		$this->assertFalse( Settings::get( 'enabled' ) );
		$this->assertSame( 0.45, Settings::get( 'bytes_per_pixel' ) );
		putenv( 'SHAKEWELL_IMAGE_COMPRESS_ENABLED' );
		putenv( 'SHAKEWELL_IMAGE_COMPRESS_BYTES_PER_PIXEL' );
	}

	public function test_threshold_drops_to_two_pixels_under_the_long_edge_for_a_heavy_jpeg(): void {
		$file = $this->jpeg( 'heavy.jpg' );
		$this->assertSame( 638, Plugin::threshold( 2560, wp_getimagesize( $file ), $file, 0 ) );
	}

	public function test_threshold_is_left_alone_for_light_files_other_types_tiny_images_and_disabled_scaling(): void {
		$light = $this->jpeg( 'light.jpg', false );
		$this->assertSame( 2560, Plugin::threshold( 2560, wp_getimagesize( $light ), $light, 0 ) );

		$heavy = $this->jpeg( 'heavy2.jpg' );
		$png   = get_temp_dir() . 'not-a-jpeg.jpg';
		imagepng( imagecreatefromjpeg( $heavy ), $png );
		$this->assertSame( 2560, Plugin::threshold( 2560, wp_getimagesize( $png ), $png, 0 ), 'type is read from the file, not the extension' );

		$tiny = $this->jpeg( 'tiny.jpg', true, 64, 48 );
		$this->assertSame( 2560, Plugin::threshold( 2560, wp_getimagesize( $tiny ), $tiny, 0 ) );

		$this->assertFalse( Plugin::threshold( false, wp_getimagesize( $heavy ), $heavy, 0 ), 'scaling switched off stays off' );

		add_filter( 'shakewell_image_compress_enabled', '__return_false' );
		$this->assertSame( 2560, Plugin::threshold( 2560, wp_getimagesize( $heavy ), $heavy, 0 ) );
	}

	public function test_a_heavy_upload_is_scaled_by_core_and_keeps_the_upload_as_the_original(): void {
		$id   = self::factory()->attachment->create_upload_object( $this->jpeg( 'upload.jpg' ) );
		$meta = wp_get_attachment_metadata( $id );

		$this->assertSame( 'upload.jpg', $meta['original_image'] );
		$this->assertStringContainsString( '-scaled', $meta['file'] );
		$this->assertFileExists( wp_get_original_image_path( $id ) );
	}

	public function test_a_light_upload_is_not_scaled(): void {
		$id = self::factory()->attachment->create_upload_object( $this->jpeg( 'flat.jpg', false ) );
		$this->assertArrayNotHasKey( 'original_image', wp_get_attachment_metadata( $id ) );
	}

	public function test_quality_editors_and_client_side_media_follow_the_settings(): void {
		$this->assertSame( 82, Plugin::quality( 82, 'image/jpeg' ), 'unset: WordPress keeps its choice' );
		add_filter( 'shakewell_image_compress_quality', static fn() => 70 );
		$this->assertSame( 70, Plugin::quality( 82, 'image/jpeg' ) );
		$this->assertSame( 82, Plugin::quality( 82, 'image/png' ) );

		$this->assertSame( array( 'A', 'B' ), Plugin::editors( array( 'A', 'B' ) ) );
		add_filter( 'shakewell_image_compress_editors', static fn() => 'gd, imagick, nonsense' );
		$this->assertSame( array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ), Plugin::editors( array( 'A' ) ) );

		$this->assertFalse( Plugin::client_side_media( true ) );
		add_filter( 'shakewell_image_compress_client_side_media_off', '__return_false' );
		$this->assertTrue( Plugin::client_side_media( true ) );
	}

	public function test_dimensions_projection_and_ordering(): void {
		$this->assertSame( array( 2560, 1067 ), Reencoder::dims( 5472, 2280, 2560 ) );
		$this->assertSame( array( 1920, 2560 ), Reencoder::dims( 8581, 11441, 2560 ) );
		$this->assertSame( array( 1440, 608 ), Reencoder::dims( 1440, 608, 2560 ) );
		$this->assertSame( 409728, Reencoder::projected( 3355028, 5472, 2280, 2560 ) );
		$this->assertSame( 100000, Reencoder::projected( 100000, 2560, 1664, 2560 ), 'never above the current size' );

		$rows = array(
			array(
				'id'    => 1,
				'bytes' => 600000,
			),
			array(
				'id'    => 2,
				'bytes' => 900000,
			),
			array(
				'id'    => 3,
				'bytes' => 600000,
			),
		);
		$this->assertSame( array( 2, 1, 3 ), array_column( Reencoder::heaviest( $rows, 0 ), 'id' ) );
		$this->assertSame( array( 2, 1 ), array_column( Reencoder::heaviest( $rows, 2 ), 'id' ) );
	}

	public function test_a_dry_run_lists_the_heavy_attachment_and_writes_nothing(): void {
		$heavy = $this->legacy_attachment( $this->jpeg( 'dry-heavy.jpg' ) );
		$light = $this->legacy_attachment( $this->jpeg( 'dry-light.jpg', false ) );
		$meta  = wp_get_attachment_metadata( $heavy );

		$found = Reencoder::candidates( null, $this->opts( array( 'min_bytes' => PHP_INT_MAX ) ) );

		$this->assertSame( array( $heavy ), array_column( $found['rows'], 'id' ) );
		$this->assertArrayHasKey( 'light', $found['skipped'] );
		$this->assertSame( $meta, wp_get_attachment_metadata( $heavy ) );
		$this->assertNotContains( $light, array_column( $found['rows'], 'id' ) );
	}

	public function test_the_size_threshold_picks_light_quality_files_that_are_still_heavy_by_weight(): void {
		$id    = $this->legacy_attachment( $this->jpeg( 'weight.jpg', false, 640, 416 ) );
		$bytes = (int) filesize( get_attached_file( $id ) );

		$none = Reencoder::candidates( array( $id ), $this->opts( array( 'min_bytes' => $bytes + 1 ) ) );
		$some = Reencoder::candidates( array( $id ), $this->opts( array( 'min_bytes' => $bytes ) ) );

		$this->assertSame( array(), $none['rows'] );
		$this->assertSame( array( $id ), array_column( $some['rows'], 'id' ) );
	}

	public function test_reencode_makes_a_scaled_copy_main_and_leaves_the_upload_byte_identical(): void {
		$id       = $this->legacy_attachment( $this->jpeg( 'bulk.jpg' ) );
		$original = get_attached_file( $id );
		$hash     = md5_file( $original );
		$before   = filesize( $original );

		$result = Reencoder::reencode( $id, $this->opts() );

		$this->assertIsArray( $result );
		$this->assertSame( 'done', $result['status'] );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 'bulk.jpg', $meta['original_image'] );
		$this->assertStringEndsWith( 'bulk-scaled.jpg', get_attached_file( $id ) );
		$this->assertFileExists( get_attached_file( $id ) );
		$this->assertLessThan( $before, filesize( get_attached_file( $id ) ) );
		$this->assertSame( $hash, md5_file( $original ), 'the upload is untouched' );
		$this->assertNotEmpty( $meta['sizes'], 'sub-sizes are made again' );
		$this->assertSame( 'done', get_post_meta( $id, Reencoder::BACKUP_META, true )['state'] );

		$again = Reencoder::candidates( array( $id ), $this->opts() );
		$this->assertSame( array( 'already-reencoded' => 1 ), $again['skipped'] );
	}

	public function test_reencode_shrinks_the_long_edge_to_the_maximum(): void {
		$id = $this->legacy_attachment( $this->jpeg( 'wide.jpg', true, 800, 400 ) );

		Reencoder::reencode( $id, $this->opts( array( 'max_edge' => 400 ) ) );

		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( array( 400, 200 ), array( $meta['width'], $meta['height'] ) );
	}

	public function test_revert_restores_the_recorded_file_and_metadata_and_keeps_the_scaled_file(): void {
		$id     = $this->legacy_attachment( $this->jpeg( 'back.jpg' ) );
		$before = get_post_meta( $id, '_wp_attachment_metadata', true );
		$path   = get_attached_file( $id );
		Reencoder::reencode( $id, $this->opts() );
		$scaled = get_attached_file( $id );

		$this->assertNull( Reencoder::revert( $id ) );

		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $before, get_post_meta( $id, '_wp_attachment_metadata', true ) );
		$this->assertFileExists( $scaled );
		$this->assertSame( 'reverted', get_post_meta( $id, Reencoder::SKIP_META, true ) );
		$this->assertSame( 'back-scaled.jpg', get_post_meta( $id, Reencoder::ORPHAN_META, true ) );

		$this->assertSame( 'done', Reencoder::reencode( $id, $this->opts() )['status'], 'its own leftover may be written again' );
	}

	public function test_revert_refuses_an_attachment_changed_since_unless_forced(): void {
		$id = $this->legacy_attachment( $this->jpeg( 'edited.jpg' ) );
		Reencoder::reencode( $id, $this->opts() );
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		unset( $meta['original_image'] );
		update_post_meta( $id, '_wp_attachment_metadata', $meta );

		$this->assertStringContainsString( 'changed since', (string) Reencoder::revert( $id ) );
		$this->assertNull( Reencoder::revert( $id, true ) );
		$this->assertStringContainsString( 'nothing to revert', (string) Reencoder::revert( $id ) );
	}

	public function test_a_failed_check_puts_everything_back_and_removes_only_its_own_copy(): void {
		$id     = $this->legacy_attachment( $this->jpeg( 'fail.jpg' ) );
		$before = get_post_meta( $id, '_wp_attachment_metadata', true );
		$path   = get_attached_file( $id );
		$hash   = md5_file( $path );

		$drop = static function ( $data ) {
			unset( $data['original_image'] );
			return $data;
		};
		add_filter( 'wp_update_attachment_metadata', $drop );
		$result = Reencoder::reencode( $id, $this->opts() );
		remove_filter( 'wp_update_attachment_metadata', $drop );

		$this->assertWPError( $result );
		$this->assertSame( 'verify', $result->get_error_code() );
		$this->assertSame( $path, get_attached_file( $id ) );
		$this->assertSame( $before, get_post_meta( $id, '_wp_attachment_metadata', true ) );
		$this->assertSame( $hash, md5_file( $path ) );
		$this->assertFileDoesNotExist( dirname( $path ) . '/fail-scaled.jpg' );
		$this->assertSame( '', get_post_meta( $id, Reencoder::BACKUP_META, true ) );
	}

	public function test_a_file_already_at_the_scaled_name_is_never_overwritten(): void {
		$id     = $this->legacy_attachment( $this->jpeg( 'clash.jpg' ) );
		$scaled = dirname( get_attached_file( $id ) ) . '/clash-scaled.jpg';
		file_put_contents( $scaled, 'someone else' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fixture.

		$result = Reencoder::reencode( $id, $this->opts() );

		$this->assertWPError( $result );
		$this->assertSame( 'collision', $result->get_error_code() );
		$this->assertSame( 'someone else', file_get_contents( $scaled ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixture.
	}

	public function test_a_saving_below_the_minimum_keeps_the_attachment_and_marks_it(): void {
		$id   = $this->legacy_attachment( $this->jpeg( 'small-gain.jpg' ) );
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );

		$result = Reencoder::reencode( $id, $this->opts( array( 'min_savings' => 99 ) ) );

		$this->assertSame( 'kept', $result['status'] );
		$this->assertSame( $meta, get_post_meta( $id, '_wp_attachment_metadata', true ) );
		$this->assertSame( 'low-savings', get_post_meta( $id, Reencoder::SKIP_META, true ) );
		$this->assertSame( array( 'marked-low-savings' => 1 ), Reencoder::candidates( null, $this->opts() )['skipped'] );
	}

	public function test_an_attachment_with_no_local_file_is_skipped_not_touched(): void {
		$id = $this->legacy_attachment( $this->jpeg( 'gone.jpg' ) );
		unlink( get_attached_file( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fixture.

		$this->assertSame( array( 'not-local' => 1 ), Reencoder::candidates( array( $id ), $this->opts() )['skipped'] );
		$this->assertWPError( Reencoder::reencode( $id, $this->opts() ) );
	}
}
