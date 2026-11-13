# Shakewell Image Compress

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

Re-encodes JPEGs saved at near-maximum quality when they are uploaded, keeping the upload as the original, and re-encodes the heaviest JPEGs already in the media library from WP-CLI. Part of a family of small, free WordPress plugins from [Shakewell](https://shakewell.agency).

## What it does

**On upload.** WordPress already scales uploads that are too big: it saves a "-scaled" copy at the editor's quality, serves it as the full size and keeps the upload as `original_image`. A JPEG over 0.6 bytes per pixel is sent down that same path by lowering `big_image_size_threshold` to two pixels under its long edge (two is the least core acts on). Nothing new is invented and the upload is never discarded. It also turns off WordPress 7.1's client-side media processing, which would resize uploads in the browser and skip this.

**In bulk.**

```sh
wp shakewell-image-compress reencode                      # dry run: what would change, and a projected size
wp shakewell-image-compress reencode --format=csv > report.csv
wp shakewell-image-compress reencode --apply --limit=1    # try one first
wp shakewell-image-compress reencode --apply
wp shakewell-image-compress revert 123                    # undo one
```

A JPEG is a candidate if it looks saved at near-maximum quality or weighs at least `--min-kb` (500 by default), heaviest first. With `--apply` each gets a "-scaled" copy (orientation applied to the pixels, long edge shrunk to `--max-edge`, quality 82, EXIF, IPTC and XMP dropped) that becomes the main file; the upload stays untouched as the original. Safeguards:

- Dry run is the default; only `--apply` writes.
- The upload is never deleted or overwritten. The only file ever removed is a "-scaled" copy the run has just created, when a check fails.
- The attachment's previous file and metadata are recorded first, every change is checked (new file served, upload intact), and any failure puts the attachment back. `revert` undoes a finished one and also recovers an interrupted one.
- A re-encode that saves less than `--min-savings` (20%) is not kept.
- CMYK images, colour profiles other than sRGB, files not on local disk, and names already taken are left alone.
- Safe to stop and run again: finished or marked attachments are skipped.

## Settings

Each is a PHP constant or an environment variable named `SHAKEWELL_IMAGE_COMPRESS_<KEY>`, and can be changed last by the filter `shakewell_image_compress_<key>`.

| Key | Default | Meaning |
|---|---|---|
| `ENABLED` | `true` | Compress heavy JPEGs on upload. |
| `BYTES_PER_PIXEL` | `0.6` | Quality threshold: above this a JPEG counts as near-maximum quality. |
| `QUALITY` | `82` | JPEG quality of the copy. Set explicitly it applies to every JPEG WordPress writes. |
| `MIN_EDGE` | `100` | Images with a long edge under this are left alone. |
| `MIN_BYTES` | `512000` | Bulk only: files at least this heavy are candidates whatever their quality. |
| `MAX_EDGE` | `2560` | Bulk only: longest side after the re-encode. Never enlarges. |
| `MIN_SAVINGS` | `20` | Bulk only: keep a re-encode only if it saves at least this percentage. |
| `EDITORS` | empty | Comma-separated editors in order: `imagick`, `gd`. |
| `CLIENT_SIDE_MEDIA_OFF` | `true` | Keep uploads on the server. |

```php
define( 'SHAKEWELL_IMAGE_COMPRESS_QUALITY', 80 );
add_filter( 'shakewell_image_compress_bytes_per_pixel', fn() => 0.5 );
```

The bulk command only handles files on local disk. Media kept only in a bucket is skipped.

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8888 (admin / password), needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check, as the directory runs it
```

CI runs the same on every pull request, on the lowest supported WordPress and PHP and on the latest.

## Release

Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, add a changelog line, then tag `vX.Y.Z`. The release workflow checks the three match and attaches `shakewell-image-compress.zip` to a GitHub release.

## Install with Composer

Until a plugin is listed, install the zip from its GitHub release, pinned to a version with a checksum:

```json
"repositories": [
  {
    "type": "package",
    "package": {
      "name": "shakewellagency/shakewell-image-compress",
      "version": "0.1.0",
      "type": "wordpress-plugin",
      "dist": {
        "url": "https://github.com/shakewellagency/shakewell-image-compress/releases/download/v0.1.0/shakewell-image-compress.zip",
        "type": "zip",
        "shasum": "<sha1 of the zip>"
      },
      "require": { "php": ">=8.1", "composer/installers": "^2.3" }
    }
  }
],
"require": { "shakewellagency/shakewell-image-compress": "0.1.0" }
```

Get the checksum with `shasum -a 1 shakewell-image-compress.zip`.

Use the zip, not a `vcs` repository. A source install puts tests and repository files under the web root, while the zip leaves them out. The build needs no GitHub token and makes no GitHub API calls. Copy the plugin's PHP requirement into `require`, because Composer doesn't read the plugin's own `composer.json` here.

Once a plugin is listed, switch to `wpackagist-plugin/shakewell-image-compress` and drop this entry.

## Made by Shakewell

Shakewell Image Compress is one of the free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

See them all: [Shakewell plugins on GitHub](https://github.com/orgs/shakewellagency/repositories?q=shakewell-).
