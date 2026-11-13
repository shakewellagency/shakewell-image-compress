<?php
/**
 * Bulk re-encoding of JPEGs already in the media library.
 *
 * @package Shakewell\ImageCompress
 */

namespace Shakewell\ImageCompress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Gives an existing heavy JPEG the treatment an upload gets: a "-scaled" copy at the target
 * quality becomes the main file and the upload stays on disk, untouched, as `original_image`.
 *
 * Nothing is ever deleted or overwritten except a file this run created itself. The attachment's
 * previous file and metadata are recorded first, every change is checked, any failure puts the
 * attachment back, and `revert` undoes a finished one. Only files on local disk are handled.
 */
final class Reencoder {

	/** Post meta holding what an attachment looked like before it was re-encoded. */
	const BACKUP_META = '_shakewell_image_compress_backup';

	/** Post meta marking an attachment later runs leave alone, and why. */
	const SKIP_META = '_shakewell_image_compress_skip';

	/** Post meta naming a "-scaled" file this plugin created and then stopped using (after a revert). */
	const ORPHAN_META = '_shakewell_image_compress_orphan';

	/** Median bytes per pixel at quality 82 after a resize, measured on camera photographs. A planning figure only. */
	const PROJECTED_BYTES_PER_PIXEL = 0.15;

	/**
	 * Whether a JPEG of this size and dimensions looks saved at near-maximum quality.
	 *
	 * @param int $bytes  File size.
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 */
	public static function is_heavy( int $bytes, int $width, int $height ): bool {
		if ( $bytes < 1 || $width < 1 || $height < 1 ) {
			return false;
		}
		return ( $bytes / ( $width * $height ) ) > Settings::get( 'bytes_per_pixel' );
	}

	/**
	 * Dimensions after shrinking to fit $max_edge on the long edge. Never enlarges.
	 *
	 * @param int $width    Width in pixels.
	 * @param int $height   Height in pixels.
	 * @param int $max_edge Longest side allowed.
	 * @return int[] Width and height.
	 */
	public static function dims( int $width, int $height, int $max_edge ): array {
		$long = max( $width, $height );
		if ( $max_edge < 1 || $long <= $max_edge ) {
			return array( $width, $height );
		}
		return array( max( 1, (int) round( $width * $max_edge / $long ) ), max( 1, (int) round( $height * $max_edge / $long ) ) );
	}

	/**
	 * Projected size after the re-encode: the model's figure, or the current size if that is smaller.
	 *
	 * @param int $bytes    Current size.
	 * @param int $width    Width in pixels.
	 * @param int $height   Height in pixels.
	 * @param int $max_edge Longest side allowed.
	 */
	public static function projected( int $bytes, int $width, int $height, int $max_edge ): int {
		list( $w, $h ) = self::dims( $width, $height, $max_edge );
		return min( $bytes, (int) round( $w * $h * self::PROJECTED_BYTES_PER_PIXEL ) );
	}

	/**
	 * Rows sorted heaviest first, ties by id, cut to $limit (0 = all).
	 *
	 * @param array[] $rows  Each with 'id' and 'bytes'.
	 * @param int     $limit Most rows to return.
	 * @return array[]
	 */
	public static function heaviest( array $rows, int $limit ): array {
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $b['bytes'], $a['id'] ) <=> array( $a['bytes'], $b['id'] );
			}
		);
		return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
	}

	/**
	 * Finds the attachments a run would re-encode. Read-only.
	 *
	 * @param int[]|null $ids   Attachment IDs, or null for every JPEG in the library.
	 * @param array      $opts  min_bytes, max_edge, limit.
	 * @return array{rows:array[],skipped:array<string,int>} Candidates, heaviest first, and skip counts by reason.
	 */
	public static function candidates( ?array $ids, array $opts ): array {
		$explicit = null !== $ids;
		$ids      = $explicit ? $ids : self::jpeg_ids();
		$rows     = array();
		$skipped  = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
			foreach ( $chunk as $id ) {
				$meta   = get_post_meta( $id, '_wp_attachment_metadata', true );
				$reason = self::skip_reason( $id, $meta, $explicit );
				$bytes  = 0;
				if ( null === $reason ) {
					clearstatcache();
					$bytes = (int) filesize( (string) get_attached_file( $id ) );
					if ( $bytes < 1 ) {
						$reason = 'unknown-size';
					} elseif ( $bytes < $opts['min_bytes'] && ! self::is_heavy( $bytes, (int) $meta['width'], (int) $meta['height'] ) ) {
						$reason = 'light';
					}
				}
				if ( null !== $reason ) {
					$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
					continue;
				}
				$rows[] = array(
					'id'              => $id,
					'file'            => (string) ( $meta['file'] ?? '' ),
					'size'            => (int) $meta['width'] . 'x' . (int) $meta['height'],
					'bytes'           => $bytes,
					'projected_bytes' => self::projected( $bytes, (int) $meta['width'], (int) $meta['height'], $opts['max_edge'] ),
				);
			}
			wp_cache_flush_runtime();
		}

		ksort( $skipped );
		return array(
			'rows'    => self::heaviest( $rows, $opts['limit'] ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Re-encodes one attachment.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $opts max_edge, min_savings (percent).
	 * @return array|WP_Error ['status' => 'done'|'kept'|'skipped', ...]
	 */
	public static function reencode( int $id, array $opts ) {
		$meta     = get_post_meta( $id, '_wp_attachment_metadata', true );
		$attached = (string) get_post_meta( $id, '_wp_attached_file', true );
		$file     = get_attached_file( $id );
		if ( ! is_array( $meta ) || '' === $attached || ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'file', 'no metadata, or the file is not on local disk' );
		}

		$scaled      = preg_replace( '/(\.[^.\/]+)$/', '-scaled$1', $file );
		$scaled_name = wp_basename( (string) $scaled );
		if ( ! $scaled || $scaled === $file ) {
			return new WP_Error( 'name', 'cannot name the scaled copy' );
		}

		// Never write over somebody else's file: the name must be free on disk and in WordPress.
		// A file this plugin made and a revert left behind may be written again.
		$own_leftover = get_post_meta( $id, self::ORPHAN_META, true ) === $scaled_name;
		if ( file_exists( $scaled ) && ! $own_leftover ) {
			return new WP_Error( 'collision', $scaled_name . ' already exists, not overwriting it' );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per attachment, not cacheable.
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", _wp_relative_upload_path( $scaled ) ) );
		if ( $owner ) {
			return new WP_Error( 'collision', sprintf( '%s is already the file of attachment %d', $scaled_name, $owner ) );
		}

		$before = (int) filesize( $file );
		if ( $before < 1 ) {
			return new WP_Error( 'empty', 'the file is empty on disk' );
		}
		$size = wp_getimagesize( $file );
		if ( ! $size || 'image/jpeg' !== ( $size['mime'] ?? '' ) ) {
			return new WP_Error( 'type', 'the file is not a JPEG' );
		}
		$meta_w = (int) ( $meta['width'] ?? 0 );
		$meta_h = (int) ( $meta['height'] ?? 0 );
		if ( $meta_w !== (int) $size[0] || $meta_h !== (int) $size[1] ) {
			return new WP_Error( 'dimensions', sprintf( 'file is %dx%d, metadata says %dx%d', $size[0], $size[1], $meta_w, $meta_h ) );
		}
		$unsafe = self::would_change_colour( $file, $size );
		if ( null !== $unsafe ) {
			update_post_meta( $id, self::SKIP_META, $unsafe );
			return array(
				'status' => 'skipped',
				'reason' => $unsafe,
			);
		}

		$out    = get_temp_dir() . sprintf( 'shakewell-image-compress-%d-%s.jpg', $id, wp_generate_password( 8, false ) );
		$result = self::encode( $file, $out, (int) $opts['max_edge'] );
		try {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$after = (int) filesize( $out );
			if ( $after < 1 ) {
				return new WP_Error( 'encode', 'the editor wrote an empty file' );
			}
			$pct = round( 100 * ( 1 - $after / $before ), 1 );
			if ( $pct < $opts['min_savings'] ) {
				update_post_meta( $id, self::SKIP_META, 'low-savings' );
				return array(
					'status' => 'kept',
					'before' => $before,
					'after'  => $after,
					'pct'    => $pct,
				);
			}
			return self::swap( $id, $meta, $attached, $file, $scaled, $out, $result, $before, $after, $pct );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- the temporary copy this run made.
			@unlink( $out );
		}
	}

	/**
	 * Undoes a finished (or interrupted) re-encode: the upload is the main file again.
	 *
	 * The "-scaled" file stays on disk, unused, and the attachment is marked so later runs leave it alone.
	 *
	 * @param int  $id    Attachment ID.
	 * @param bool $force Restore even if the attachment was edited or regenerated since.
	 * @return string|null What is wrong, or null when reverted.
	 */
	public static function revert( int $id, bool $force = false ): ?string {
		$backup = get_post_meta( $id, self::BACKUP_META, true );
		if ( ! is_array( $backup ) || empty( $backup['metadata'] ) || empty( $backup['attached_file'] ) ) {
			return 'no re-encode record, nothing to revert';
		}

		// Only undo what the re-encode wrote: a later edit or regenerate would be lost.
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		if ( ! $force && 'done' === ( $backup['state'] ?? '' )
			&& ( ! is_array( $meta )
				|| ( $meta['original_image'] ?? '' ) !== wp_basename( (string) $backup['attached_file'] )
				|| wp_basename( (string) ( $meta['file'] ?? '' ) ) !== ( $backup['scaled_file'] ?? '' ) ) ) {
			return 'changed since it was re-encoded, left as it is (--force restores the recorded version anyway)';
		}

		$problem = self::restore( $id, $backup );
		if ( null !== $problem ) {
			return 'restore did not take, record kept: ' . $problem;
		}
		delete_post_meta( $id, self::BACKUP_META );
		update_post_meta( $id, self::SKIP_META, 'reverted' );
		if ( ! empty( $backup['scaled_file'] ) ) {
			update_post_meta( $id, self::ORPHAN_META, $backup['scaled_file'] );
		}
		return null;
	}

	/**
	 * Writes the new file, records the old state, switches the attachment over and checks it.
	 *
	 * @param int    $id       Attachment ID.
	 * @param array  $meta     Metadata before.
	 * @param string $attached Relative attached file before.
	 * @param string $file     Original path.
	 * @param string $scaled   Path of the "-scaled" copy to create.
	 * @param string $out      Temporary encoded file.
	 * @param array  $saved    The editor's save result.
	 * @param int    $before   Original size.
	 * @param int    $after    New size.
	 * @param float  $pct      Percentage saved.
	 * @return array|WP_Error
	 */
	private static function swap( int $id, array $meta, string $attached, string $file, string $scaled, string $out, array $saved, int $before, int $after, float $pct ) {
		// Recorded before anything is written and marked pending, so a run killed part-way leaves something `revert` can restore.
		$backup = array(
			'state'         => 'pending',
			'attached_file' => $attached,
			'metadata'      => $meta,
			'scaled_file'   => wp_basename( $scaled ),
			'bytes_before'  => $before,
			'bytes_after'   => $after,
			'time'          => time(),
		);
		update_post_meta( $id, self::BACKUP_META, $backup );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- plain local copy into the uploads folder.
		if ( ! copy( $out, $scaled ) ) {
			delete_post_meta( $id, self::BACKUP_META );
			return new WP_Error( 'write', 'could not write ' . wp_basename( $scaled ) );
		}

		$new                   = $meta;
		$new['file']           = _wp_relative_upload_path( $scaled );
		$new['filesize']       = $after;
		$new['original_image'] = wp_basename( $file );
		$new['width']          = (int) $saved['width'];
		$new['height']         = (int) $saved['height'];
		$new['sizes']          = array();
		if ( isset( $new['image_meta']['orientation'] ) ) {
			// The pixels are turned already, as core records after maybe_exif_rotate().
			$new['image_meta']['orientation'] = 1;
		}

		try {
			update_attached_file( $id, $scaled );
			// The sub-sizes are made again from the new main file, as an upload does.
			$made = _wp_make_subsizes( wp_get_registered_image_subsizes(), $scaled, $new, $id );
			if ( is_wp_error( $made ) ) {
				$problem = 'sub-sizes: ' . $made->get_error_message();
			} else {
				wp_update_attachment_metadata( $id, $made );
				$problem = self::verify( $id, wp_basename( $scaled ), $file, $scaled, $before, $after );
			}
		} catch ( \Throwable $e ) {
			$problem = get_class( $e ) . ': ' . $e->getMessage();
		}

		if ( null !== $problem ) {
			$restored = self::restore( $id, $backup );
			if ( null !== $restored ) {
				update_post_meta( $id, self::BACKUP_META, array( 'state' => 'restore-failed' ) + $backup );
				return new WP_Error( 'restore', sprintf( '%s; restoring it also failed (%s), so run `wp shakewell-image-compress revert %d`', $problem, $restored, $id ) );
			}
			delete_post_meta( $id, self::BACKUP_META );
			// The copy was created by this run and is not used by anything. Sub-sizes already written are left, harmless.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- the copy this run created.
			@unlink( $scaled );
			return new WP_Error( 'verify', $problem );
		}

		update_post_meta( $id, self::BACKUP_META, array( 'state' => 'done' ) + $backup );
		delete_post_meta( $id, self::SKIP_META );
		delete_post_meta( $id, self::ORPHAN_META );

		return array(
			'status' => 'done',
			'before' => $before,
			'after'  => $after,
			'pct'    => $pct,
			'url'    => wp_get_attachment_url( $id ),
		);
	}

	/**
	 * Encodes the file: orientation applied to the pixels, long edge shrunk, target quality.
	 *
	 * @param string $file     Source path.
	 * @param string $out      Destination path.
	 * @param int    $max_edge Longest side allowed.
	 * @return array|WP_Error The editor's save result.
	 */
	private static function encode( string $file, string $out, int $max_edge ) {
		$editor = wp_get_image_editor( $file, array( 'mime_type' => 'image/jpeg' ) );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		// The EXIF that said "turn me" goes with the strip, so turn the pixels first.
		$rotated = $editor->maybe_exif_rotate();
		if ( is_wp_error( $rotated ) ) {
			return $rotated;
		}
		$now           = $editor->get_size();
		list( $w, $h ) = self::dims( (int) $now['width'], (int) $now['height'], $max_edge );
		if ( (int) $now['width'] !== $w || (int) $now['height'] !== $h ) {
			$resized = $editor->resize( $w, $h, false );
			if ( is_wp_error( $resized ) ) {
				return $resized;
			}
		}
		$editor->set_quality( (int) Settings::get( 'quality' ) );
		$saved = $editor->save( $out, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		if ( 'image/jpeg' !== ( $saved['mime-type'] ?? '' ) || $saved['path'] !== $out ) {
			return new WP_Error( 'format', 'the editor did not write a JPEG to ' . $out );
		}
		return $saved;
	}

	/**
	 * Why re-encoding would visibly change this image's colours: CMYK, or a profile other than sRGB.
	 *
	 * @param string $file Path.
	 * @param array  $size Result of wp_getimagesize().
	 */
	public static function would_change_colour( string $file, array $size ): ?string {
		if ( 4 === (int) ( $size['channels'] ?? 3 ) ) {
			return 'cmyk';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the first 256 KB of a local file.
		$head = (string) file_get_contents( $file, false, null, 0, 262144 );
		return ( false !== strpos( $head, 'ICC_PROFILE' ) && false === strpos( $head, 'sRGB' ) ) ? 'colour-profile' : null;
	}

	/**
	 * Deletes the previous sub-sizes of a re-encoded attachment along with it. Core only knows the current ones.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function delete_previous_sizes( $id ): void {
		$backup = get_post_meta( (int) $id, self::BACKUP_META, true );
		if ( ! is_array( $backup ) || empty( $backup['attached_file'] ) || empty( $backup['metadata']['sizes'] ) ) {
			return;
		}
		$dir = dirname( path_join( wp_get_upload_dir()['basedir'], $backup['attached_file'] ) );
		foreach ( $backup['metadata']['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				wp_delete_file( path_join( $dir, wp_basename( $size['file'] ) ) );
			}
		}
	}

	/**
	 * Puts back the attached file and metadata recorded before the re-encode.
	 *
	 * @param int   $id     Attachment ID.
	 * @param array $backup The record.
	 * @return string|null What is wrong, or null when the upload is the main file again.
	 */
	private static function restore( int $id, array $backup ): ?string {
		try {
			update_attached_file( $id, $backup['attached_file'] );
			wp_update_attachment_metadata( $id, $backup['metadata'] );
		} catch ( \Throwable $e ) {
			return get_class( $e ) . ': ' . $e->getMessage();
		}
		$file = get_attached_file( $id );
		if ( wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) !== wp_basename( (string) $backup['attached_file'] ) || ! $file || ! file_exists( $file ) ) {
			return 'the upload is not the main file again';
		}
		return null;
	}

	/**
	 * Checks the attachment now serves the new file and the upload is still there in full.
	 *
	 * @param int    $id          Attachment ID.
	 * @param string $scaled_name File name of the new main file.
	 * @param string $original    Path of the upload.
	 * @param string $scaled      Path of the new main file.
	 * @param int    $before      Upload size.
	 * @param int    $after       New file size.
	 * @return string|null What is wrong, or null.
	 */
	private static function verify( int $id, string $scaled_name, string $original, string $scaled, int $before, int $after ): ?string {
		clearstatcache();
		$meta = get_post_meta( $id, '_wp_attachment_metadata', true );
		if ( wp_basename( (string) wp_get_attachment_url( $id ) ) !== $scaled_name || wp_basename( (string) get_attached_file( $id ) ) !== $scaled_name ) {
			return 'the attachment does not point at the new file';
		}
		if ( (int) filesize( $scaled ) !== $after ) {
			return 'the new file is not ' . $after . ' bytes';
		}
		if ( ! is_array( $meta ) || ( $meta['original_image'] ?? '' ) !== wp_basename( $original ) || (int) filesize( $original ) !== $before ) {
			return 'the upload is no longer recorded or intact as the original';
		}
		return null;
	}

	/**
	 * Why an attachment is not a candidate, from its metadata alone.
	 *
	 * @param int   $id       Attachment ID.
	 * @param mixed $meta     Attachment metadata.
	 * @param bool  $explicit Named by the caller, so a mark from an earlier run is ignored.
	 */
	private static function skip_reason( int $id, $meta, bool $explicit ): ?string {
		if ( $explicit && ( 'attachment' !== get_post_type( $id ) || 'image/jpeg' !== get_post_mime_type( $id ) ) ) {
			return 'not-a-jpeg';
		}
		$backup = get_post_meta( $id, self::BACKUP_META, true );
		if ( $backup ) {
			return ( is_array( $backup ) && 'done' === ( $backup['state'] ?? '' ) ) ? 'already-reencoded' : 'interrupted';
		}
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return 'no-metadata';
		}
		if ( ! empty( $meta['original_image'] ) ) {
			return 'already-scaled';
		}
		if ( max( (int) $meta['width'], (int) $meta['height'] ) < Settings::get( 'min_edge' ) ) {
			return 'too-small';
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return 'not-local';
		}
		$marked = (string) get_post_meta( $id, self::SKIP_META, true );
		return ( '' !== $marked && ! $explicit ) ? 'marked-' . $marked : null;
	}

	/**
	 * Every JPEG attachment ID, newest first.
	 *
	 * @return int[]
	 */
	private static function jpeg_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one read of IDs for a bulk command.
		return array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/jpeg' ORDER BY ID DESC" ) );
	}
}
