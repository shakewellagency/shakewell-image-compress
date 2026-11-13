=== Shakewell Image Compress ===
Contributors: shakewellagency
Tags: jpeg, images, compression, optimization, wp-cli
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Re-encodes JPEGs saved at near-maximum quality on upload, keeping the upload as the original, and re-encodes the heaviest existing JPEGs from WP-CLI.

== Description ==

Some JPEGs are uploaded at quality 99 or 100: a 640 by 416 tile can weigh 300 to 485 KB where 40 to 80 KB looks the same at the size it is shown. Shakewell Image Compress finds those files and gives them a lighter copy, and never throws the upload away.

**On upload.** WordPress already scales uploads that are too big: it saves a "-scaled" copy at the editor's quality, serves that as the full size and keeps the upload as the original image. This plugin sends a JPEG that looks saved at near-maximum quality (more than 0.6 bytes per pixel) down that same path. Nothing new is invented, and the upload stays on disk.

**In bulk.** `wp shakewell-image-compress reencode` lists the heaviest JPEGs already in the media library (dry run by default) and, with `--apply`, gives each the same treatment: orientation applied to the pixels, long edge shrunk to 2560 pixels, quality 82, a "-scaled" copy as the main file, sub-sizes made again, the upload kept untouched as the original. The attachment's previous file and metadata are recorded first, every change is checked, any failure puts the attachment back, and `wp shakewell-image-compress revert <id>` undoes a finished one. Images in CMYK or with a colour profile other than sRGB, and files not on local disk, are left alone.

It also switches off WordPress 7.1's client-side media processing, which would otherwise resize uploads in the browser and skip the step above.

= Settings =

Every setting is a PHP constant or an environment variable named `SHAKEWELL_IMAGE_COMPRESS_<KEY>`, and can be changed by the filter `shakewell_image_compress_<key>`.

* `ENABLED` (true): compress heavy JPEGs on upload.
* `BYTES_PER_PIXEL` (0.6): the quality threshold. Above this a JPEG counts as saved at near-maximum quality. Photographs at WordPress's default quality land around 0.2 to 0.4.
* `QUALITY` (82): JPEG quality of the re-encoded copy. If you set it, it applies to every JPEG WordPress writes; unset, WordPress's own quality is used for uploads and 82 for bulk runs.
* `MIN_EDGE` (100): images with a long edge under this many pixels are left alone.
* `MIN_BYTES` (512000): bulk only. Files at least this heavy count as heavy whatever their quality.
* `MAX_EDGE` (2560): bulk only. Longest side after the re-encode. Smaller images are never enlarged.
* `MIN_SAVINGS` (20): bulk only. A re-encode is kept only if it saves at least this percentage.
* `EDITORS` (empty): comma-separated image editors, in order: `imagick`, `gd`. Empty leaves the choice to WordPress.
* `CLIENT_SIDE_MEDIA_OFF` (true): keep uploads on the server so the first step can act on them.

= External services =

None. The plugin makes no remote requests.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen. New heavy JPEG uploads are compressed from then on.
3. To deal with the existing library, run `wp shakewell-image-compress reencode` and read the report, then `wp shakewell-image-compress reencode --apply --limit=1` before a larger run.

== Frequently Asked Questions ==

= Is the original upload ever deleted or overwritten? =

No. The lighter copy is written beside it as "-scaled", and the upload stays as the attachment's original image. The only file the plugin ever removes is a "-scaled" copy it has just created itself, when a check fails.

= Does it work with media stored in a bucket or on a CDN? =

Not yet. The bulk command only handles files on local disk and skips the rest.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release.
