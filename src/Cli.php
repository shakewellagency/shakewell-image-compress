<?php
/**
 * WP-CLI: bulk re-encoding of existing JPEGs.
 *
 * @package Shakewell\ImageCompress
 */

namespace Shakewell\ImageCompress;

defined( 'ABSPATH' ) || exit;

/**
 * Re-encode the heaviest JPEGs already in the media library, and undo it.
 */
final class Cli {

	/**
	 * Re-encodes heavy JPEG attachments. Dry run unless --apply.
	 *
	 * A JPEG is heavy if it looks saved at near-maximum quality (bytes per pixel over the threshold) or weighs at least --min-kb. The heaviest come first. On a dry run nothing is written and nothing is read except file sizes (and, with --stat or --ids, the size of media kept only in storage, by a request to it), and the projected size is a model, not an encode.
	 *
	 * With --apply, each image gets a "-scaled" copy: orientation applied to the pixels, long edge shrunk to --max-edge, target quality, EXIF/IPTC/XMP dropped. The copy becomes the main file and the upload stays on disk, untouched, as the original image. Sub-sizes are made again, the result is checked, and any failure puts the attachment back as it was. `revert` undoes a finished one. CMYK images and other colour profiles than sRGB are left alone, as are files that are neither on local disk nor served from storage.
	 *
	 * Media kept only in storage by Shakewell Media Offload is handled too: the original is downloaded from its address to a temporary file, the new files are written locally for Shakewell Media Offload to upload when the metadata is updated, the result is checked by asking storage for both objects, and the local copies are removed again. Nothing is deleted from storage: the original object and the previous sub-sizes stay, and `revert` points the attachment back at them.
	 *
	 * Safe to stop and run again: attachments already re-encoded, or marked to be left alone, are skipped. Try --limit=1 before a large run.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write. Without it nothing is changed.
	 *
	 * [--min-kb=<kb>]
	 * : Files at least this heavy count whatever their quality. Default: the MIN_BYTES setting (500 KB).
	 *
	 * [--max-edge=<px>]
	 * : Longest side after the re-encode. Smaller images are never enlarged. Default: the MAX_EDGE setting (2560).
	 *
	 * [--min-savings=<pct>]
	 * : Keep a re-encode only if it saves at least this percentage. Default: the MIN_SAVINGS setting (20).
	 *
	 * [--limit=<n>]
	 * : Take only the heaviest n. Default: all.
	 *
	 * [--stat]
	 * : For media kept only in storage whose metadata records no file size, ask storage for it (one request each, so slow on a large library). Attachments named in --ids are always asked.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs instead of the whole library. Tried even if an earlier run marked them to be left alone.
	 *
	 * [--format=<format>]
	 * : Report format on a dry run: table, csv, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shakewell-image-compress reencode --format=csv > report.csv
	 *     wp shakewell-image-compress reencode --apply --limit=1
	 *
	 * @param string[] $args  Positional arguments (none).
	 * @param array    $assoc Named arguments.
	 */
	public function reencode( $args, $assoc ) {
		unset( $args );

		$opts = array(
			'min_bytes'   => 1024 * ( isset( $assoc['min-kb'] ) ? max( 1, (int) $assoc['min-kb'] ) : (int) ( Settings::get( 'min_bytes' ) / 1024 ) ),
			'max_edge'    => isset( $assoc['max-edge'] ) ? max( 256, (int) $assoc['max-edge'] ) : max( 256, (int) Settings::get( 'max_edge' ) ),
			'min_savings' => isset( $assoc['min-savings'] ) ? (float) $assoc['min-savings'] : (float) Settings::get( 'min_savings' ),
			'limit'       => isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0,
			'stat'        => ! empty( $assoc['stat'] ),
		);
		if ( $opts['min_savings'] < 0 || $opts['min_savings'] >= 100 ) {
			\WP_CLI::error( '--min-savings must be a percentage from 0 to 99.' );
		}
		$apply = ! empty( $assoc['apply'] );
		if ( $apply && ! function_exists( 'exif_read_data' ) ) {
			// Without it an EXIF-rotated image cannot be recognised and would lose its rotation.
			\WP_CLI::error( 'The PHP exif extension is not loaded.' );
		}

		$ids = null;
		if ( isset( $assoc['ids'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) ) ) );
			if ( ! $ids ) {
				\WP_CLI::error( '--ids needs at least one attachment ID.' );
			}
		}

		$found = Reencoder::candidates( $ids, $opts );
		$rows  = $found['rows'];
		$skips = $found['skipped'] ? implode( ', ', array_map( static fn( $k, $v ) => "$k $v", array_keys( $found['skipped'] ), $found['skipped'] ) ) : 'none';

		if ( ! $apply ) {
			$before = array_sum( array_column( $rows, 'bytes' ) );
			$after  = array_sum( array_column( $rows, 'projected_bytes' ) );
			\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'file', 'size', 'bytes', 'projected_bytes' ) );
			fwrite( STDERR, sprintf( "Dry run, nothing written. Candidates: %d, %s MB now, about %s MB projected (saving %s MB). Skipped: %s.\n", count( $rows ), self::mb( $before ), self::mb( $after ), self::mb( $before - $after ), $skips ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a CLI report on stderr.
			return;
		}

		$done   = 0;
		$failed = 0;
		$left   = 0;
		$total  = array( 0, 0 );
		foreach ( $rows as $row ) {
			$result = Reencoder::reencode( $row['id'], $opts );
			if ( is_wp_error( $result ) ) {
				++$failed;
				\WP_CLI::warning( sprintf( '%d: failed, left as it was: %s', $row['id'], $result->get_error_message() ) );
			} elseif ( 'done' !== $result['status'] ) {
				++$left;
				\WP_CLI::log( sprintf( '%d: left alone (%s)', $row['id'], $result['reason'] ?? 'low-savings' ) );
			} else {
				++$done;
				$total[0] += $result['before'];
				$total[1] += $result['after'];
				\WP_CLI::log( sprintf( '%d: %d KB -> %d KB  %s', $row['id'], $result['before'] / 1024, $result['after'] / 1024, $result['url'] ) );
			}
		}
		\WP_CLI::log( sprintf( 'Re-encoded: %d (%s MB -> %s MB). Left alone: %d. Failed: %d. Skipped before starting: %s.', $done, self::mb( $total[0] ), self::mb( $total[1] ), $left, $failed, $skips ) );
		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Undoes a re-encode: the original upload is the main file again.
	 *
	 * Restores the attachment's file and metadata from the record the re-encode kept. Also the way to recover an attachment an interrupted run left part-way. The "-scaled" file stays on disk, unused, and later runs leave the attachment alone.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment IDs to revert.
	 *
	 * [--force]
	 * : Restore the recorded version even if the attachment was edited or regenerated since it was re-encoded.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shakewell-image-compress revert 123
	 *
	 * @param string[] $args  Attachment IDs.
	 * @param array    $assoc Named arguments.
	 */
	public function revert( $args, $assoc ) {
		$failed = 0;
		foreach ( array_map( 'intval', $args ) as $id ) {
			$problem = Reencoder::revert( $id, ! empty( $assoc['force'] ) );
			if ( null !== $problem ) {
				++$failed;
				\WP_CLI::warning( sprintf( '%d: %s.', $id, $problem ) );
				continue;
			}
			\WP_CLI::log( sprintf( '%d: reverted, serving %s', $id, wp_get_attachment_url( $id ) ) );
		}
		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Megabytes with one decimal.
	 *
	 * @param int $bytes Size.
	 */
	private static function mb( int $bytes ): string {
		return number_format( $bytes / 1048576, 1 );
	}
}
