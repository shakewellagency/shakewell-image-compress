<?php
/**
 * The bulk re-encode on attachments whose files exist only in storage, with Shakewell Media Offload
 * serving and uploading them and S3 faked at pre_http_request.
 *
 * @package Shakewell\ImageCompress
 */

namespace Shakewell\ImageCompress\Tests;

use Shakewell\ImageCompress\Reencoder;
use WP_UnitTestCase;

/**
 * Each test starts from an attachment offloaded and then removed from local disk.
 */
final class OffloadedMediaTest extends WP_UnitTestCase {

	/**
	 * The fake bucket: object key => body.
	 *
	 * @var array<string,string>
	 */
	private $objects = array();

	/**
	 * Refuse uploads of "-scaled" files.
	 *
	 * @var bool
	 */
	private $refuse_scaled = false;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\Shakewell\MediaOffload\Plugin' ) ) {
			$this->markTestSkipped( 'Shakewell Media Offload is not installed in the test environment.' );
		}
		$this->objects       = array();
		$this->refuse_scaled = false;
		foreach ( array(
			'SHAKEWELL_MEDIA_OFFLOAD_ENABLED'         => 'true',
			'SHAKEWELL_MEDIA_OFFLOAD_BUCKET'          => 'example-bucket',
			'SHAKEWELL_MEDIA_OFFLOAD_REGION'          => 'ap-southeast-2',
			'SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN' => 'media.example.com',
			'AWS_ACCESS_KEY_ID'                       => 'AKIDENV',
			'AWS_SECRET_ACCESS_KEY'                   => 'SECRETENV',
		) as $name => $value ) {
			putenv( "$name=$value" );
		}
		\Shakewell\MediaOffload\Credentials::reset();
		\Shakewell\MediaOffload\Plugin::reset();
		add_filter( 'pre_http_request', array( $this, 'fake_s3' ), 10, 3 );
		\Shakewell\MediaOffload\Plugin::boot();
	}

	public function tear_down(): void {
		foreach ( array( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED', 'SHAKEWELL_MEDIA_OFFLOAD_BUCKET', 'SHAKEWELL_MEDIA_OFFLOAD_REGION', 'SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY' ) as $name ) {
			putenv( $name );
		}
		\Shakewell\MediaOffload\Credentials::reset();
		\Shakewell\MediaOffload\Plugin::reset();
		\Shakewell\MediaOffload\Uploader::reset();
		parent::tear_down();
	}

	/**
	 * A tiny S3 and CDN: PUT and DELETE on the bucket host, GET and HEAD on either host.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 */
	public function fake_s3( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( false === strpos( $host, 'amazonaws.com' ) && 'media.example.com' !== $host ) {
			return $pre;
		}
		$key    = rawurldecode( ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		$method = $args['method'];
		$reply  = static function ( int $code, string $body = '', array $headers = array() ) {
			return array(
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'body'     => $body,
				'headers'  => $headers,
				'cookies'  => array(),
			);
		};

		if ( 'PUT' === $method ) {
			if ( $this->refuse_scaled && false !== strpos( $key, '-scaled' ) ) {
				return $reply( 500 );
			}
			$this->objects[ $key ] = (string) $args['body'];
			return $reply( 200 );
		}
		if ( 'DELETE' === $method ) {
			unset( $this->objects[ $key ] );
			return $reply( 204 );
		}
		if ( ! isset( $this->objects[ $key ] ) ) {
			return $reply( 403 );
		}
		$body    = $this->objects[ $key ];
		$headers = array( 'content-length' => strlen( $body ) );
		if ( 'HEAD' === $method ) {
			return $reply( 200, '', $headers );
		}
		if ( ! empty( $args['stream'] ) ) {
			file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- what core's HTTP layer does for a stream.
			return $reply( 200, '', $headers ) + array( 'filename' => $args['filename'] );
		}
		return $reply( 200, $body, $headers );
	}

	private function opts( array $over = array() ): array {
		return $over + array(
			'min_bytes'   => 512000,
			'max_edge'    => 2560,
			'min_savings' => 20,
			'limit'       => 0,
		);
	}

	/**
	 * An uploaded heavy JPEG, offloaded by the upload hook and then removed from local disk, as a
	 * library that has lived only in the bucket has it.
	 *
	 * @param string $name File name.
	 * @return int Attachment ID.
	 */
	private function bucket_only_attachment( string $name ): int {
		$path = get_temp_dir() . uniqid( '', true ) . '-' . $name;
		$im   = imagecreatetruecolor( 640, 416 );
		mt_srand( 11 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- repeatable fixture.
		for ( $x = 0; $x < 640; $x += 2 ) {
			for ( $y = 0; $y < 416; $y += 2 ) {
				imagefilledrectangle( $im, $x, $y, $x + 1, $y + 1, imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
			}
		}
		imagejpeg( $im, $path, 100 );

		add_filter( 'shakewell_image_compress_enabled', '__return_false' );
		$id = self::factory()->attachment->create_upload_object( $path );
		remove_filter( 'shakewell_image_compress_enabled', '__return_false' );

		$dir = dirname( get_attached_file( $id ) );
		foreach ( array_merge( array( wp_basename( get_attached_file( $id ) ) ), array_column( wp_get_attachment_metadata( $id )['sizes'], 'file' ) ) as $file ) {
			unlink( "$dir/$file" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fixture.
		}
		return $id;
	}

	private function key_of( int $id ): string {
		return 'wp-content/uploads/' . get_post_meta( $id, '_wp_attached_file', true );
	}

	public function test_the_fixture_is_offloaded_and_not_on_disk(): void {
		$id = $this->bucket_only_attachment( 'fixture.jpg' );

		$this->assertFileDoesNotExist( get_attached_file( $id ) );
		$this->assertArrayHasKey( $this->key_of( $id ), $this->objects );
		$this->assertStringStartsWith( 'https://media.example.com/', wp_get_attachment_url( $id ) );
	}

	public function test_an_offloaded_attachment_is_a_candidate_with_its_size_from_the_metadata(): void {
		$id = $this->bucket_only_attachment( 'listed.jpg' );

		$found = Reencoder::candidates( array( $id ), $this->opts() );

		$this->assertSame( array( $id ), array_column( $found['rows'], 'id' ) );
		$this->assertSame( strlen( $this->objects[ $this->key_of( $id ) ] ), $found['rows'][0]['bytes'] );
	}

	public function test_reencode_fetches_the_original_uploads_the_new_files_and_leaves_the_original_object_alone(): void {
		$id       = $this->bucket_only_attachment( 'bucket.jpg' );
		$key      = $this->key_of( $id );
		$original = $this->objects[ $key ];
		$old_url  = wp_get_attachment_url( $id );
		$old_size = array_column( wp_get_attachment_metadata( $id )['sizes'], 'file' )[0];

		$result = Reencoder::reencode( $id, $this->opts() );

		$this->assertIsArray( $result );
		$this->assertSame( 'done', $result['status'] );
		$scaled_key = preg_replace( '/\.jpg$/', '-scaled.jpg', $key );
		$this->assertArrayHasKey( $scaled_key, $this->objects );
		$this->assertLessThan( strlen( $original ), strlen( $this->objects[ $scaled_key ] ) );
		$this->assertSame( $original, $this->objects[ $key ], 'the original object is untouched' );
		$this->assertArrayHasKey( dirname( $key ) . '/' . $old_size, $this->objects, 'previous sub-sizes stay in the bucket' );
		$this->assertSame( 'https://media.example.com/' . $scaled_key, wp_get_attachment_url( $id ) );
		$this->assertSame( wp_basename( $key ), wp_get_attachment_metadata( $id )['original_image'] );
		$this->assertFileDoesNotExist( get_attached_file( $id ), 'no local copy is kept' );
		$this->assertFileDoesNotExist( dirname( get_attached_file( $id ) ) . '/' . wp_basename( $scaled_key ) );

		$record = get_post_meta( $id, Reencoder::BACKUP_META, true );
		$this->assertSame( 'done', $record['state'] );
		$this->assertTrue( $record['offloaded'] );
		$this->assertContains( $old_url, $record['previous_urls'] );
	}

	public function test_revert_serves_the_original_object_again_and_keeps_the_new_ones(): void {
		$id   = $this->bucket_only_attachment( 'undo.jpg' );
		$key  = $this->key_of( $id );
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		Reencoder::reencode( $id, $this->opts() );

		$this->assertNull( Reencoder::revert( $id ) );

		$this->assertSame( 'https://media.example.com/' . $key, wp_get_attachment_url( $id ) );
		$this->assertSame( $meta, get_post_meta( $id, '_wp_attachment_metadata', true ) );
		$this->assertArrayHasKey( preg_replace( '/\.jpg$/', '-scaled.jpg', $key ), $this->objects, 'nothing is deleted from the bucket' );
		$this->assertSame( 'reverted', get_post_meta( $id, Reencoder::SKIP_META, true ) );
	}

	public function test_revert_fails_and_keeps_the_record_when_the_original_object_is_gone(): void {
		$id  = $this->bucket_only_attachment( 'lost.jpg' );
		$key = $this->key_of( $id );
		Reencoder::reencode( $id, $this->opts() );
		unset( $this->objects[ $key ] );

		$this->assertStringContainsString( 'the original answers 403', (string) Reencoder::revert( $id ) );
		$this->assertSame( 'done', get_post_meta( $id, Reencoder::BACKUP_META, true )['state'] );
	}

	public function test_a_refused_upload_rolls_everything_back(): void {
		$id     = $this->bucket_only_attachment( 'refused.jpg' );
		$key    = $this->key_of( $id );
		$before = get_post_meta( $id, '_wp_attachment_metadata', true );
		$orig   = $this->objects[ $key ];
		$this->refuse_scaled = true;

		$result = Reencoder::reencode( $id, $this->opts() );

		$this->assertWPError( $result );
		$this->assertSame( 'verify', $result->get_error_code() );
		$this->assertSame( $before, get_post_meta( $id, '_wp_attachment_metadata', true ) );
		$this->assertSame( 'https://media.example.com/' . $key, wp_get_attachment_url( $id ) );
		$this->assertSame( $orig, $this->objects[ $key ] );
		$this->assertSame( '', get_post_meta( $id, Reencoder::BACKUP_META, true ) );
		$this->assertFileDoesNotExist( dirname( get_attached_file( $id ) ) . '/' . wp_basename( preg_replace( '/\.jpg$/', '-scaled.jpg', $key ) ) );
		$this->assertNotSame( '', get_post_meta( $id, Reencoder::ORPHAN_META, true ), 'a later run may write the same name again' );
	}

	public function test_a_failed_download_changes_nothing(): void {
		$id     = $this->bucket_only_attachment( 'nodownload.jpg' );
		$before = get_post_meta( $id, '_wp_attachment_metadata', true );
		unset( $this->objects[ $this->key_of( $id ) ] );

		$result = Reencoder::reencode( $id, $this->opts() );

		$this->assertWPError( $result );
		$this->assertSame( 'download', $result->get_error_code() );
		$this->assertSame( $before, get_post_meta( $id, '_wp_attachment_metadata', true ) );
		$this->assertSame( '', get_post_meta( $id, Reencoder::BACKUP_META, true ) );
	}

	public function test_an_object_already_at_the_scaled_key_is_never_overwritten(): void {
		$id                                                           = $this->bucket_only_attachment( 'taken.jpg' );
		$this->objects[ preg_replace( '/\.jpg$/', '-scaled.jpg', $this->key_of( $id ) ) ] = 'someone else';

		$result = Reencoder::reencode( $id, $this->opts() );

		$this->assertWPError( $result );
		$this->assertSame( 'collision', $result->get_error_code() );
		$this->assertSame( 'someone else', $this->objects[ preg_replace( '/\.jpg$/', '-scaled.jpg', $this->key_of( $id ) ) ] );
	}

	public function test_an_attachment_in_neither_place_is_skipped(): void {
		$id = $this->bucket_only_attachment( 'nowhere.jpg' );
		$this->objects = array();
		remove_all_filters( 'wp_get_attachment_url' );

		$this->assertSame( array( 'not-local' => 1 ), Reencoder::candidates( array( $id ), $this->opts() )['skipped'] );
	}
}
