# Lunar Image Converter

A WordPress plugin that converts JPEG and PNG images to WebP or AVIF, replaces the originals, and updates the image addresses in your content.

> **Status: in development (0.1.0), not released yet.** It is built for one site and published openly on GitHub. It is not on WordPress.org and no user support is promised. See [Status and testing](#status-and-testing).

## What it does

- **Converts on upload.** A new JPEG or PNG becomes a WebP or AVIF file, together with every size WordPress makes for it. The original files are deleted.
- **Converts the images you already have.** *Convert existing images* works through the media library one image at a time, with progress, a stop button and a retry for images that were skipped or failed.
- **Replaces addresses in your content.** When an existing image is converted, its old addresses are replaced in posts, pages, other public post types, reusable blocks, templates, template parts and navigation menus.
- **Keeps what is not worth converting.** If the result is not smaller than the original, the original stays untouched.
- **Optionally fills empty alt text** from the file name.

The attachment ID never changes, so featured images, galleries and anything else that refers to an image by ID keep working.

## Read this before you use it

- **The original files are deleted and this cannot be undone.** That includes the resized copies and the large original that WordPress keeps when it scales a big image down (`original_image`).
- **"Restore original" in the image editor is no longer available** for a converted image. The backups the editor keeps for undoing edits are deleted too.
- **Back up your uploads folder and your database** before you convert existing images. The plugin asks you to confirm this every time you open the page.
- Only the content listed above is searched. **Custom fields, page builder data, serialized data, revisions, excerpts and images on a CDN are not changed.** An image used there can stop working after it is converted.

## Requirements

- WordPress 7.0 or later
- PHP 8.1 or later
- A server that can encode the format you choose. WordPress uses Imagick or GD, whichever it finds. The settings page shows what your server supports.
  - WebP needs Imagick or GD with WebP support.
  - AVIF needs Imagick built with AVIF support, or GD built with AVIF support on PHP 8.1 or later.
- Your web server must send the right `Content-Type` for the format you choose (`image/webp` or `image/avif`).

## Installation

There is no release yet. Until the first one is published on the [GitHub Releases](https://github.com/irvangen09/lunar-image-converter/releases) page, build the zip yourself (it needs Node.js and npm):

```
npm ci
npm run plugin-zip
```

Then upload `lunar-image-converter.zip` under Plugins > Add New Plugin > Upload Plugin and activate it. After activation, open **Media > Image Converter**.

Updates are manual: install the newer release zip over the old one. Automatic updates are not provided.

## Settings

Under **Media > Image Converter** (administrators only):

| Setting | Default | Notes |
|---|---|---|
| Output format | WebP | AVIF is optional and slower to encode. |
| Quality | 80 | 1 to 100. Higher means better quality and larger files. |
| Image types | JPEG and PNG on | Each can be turned off. GIF and other types are never converted. |
| Fill empty alt text from the file name | Off | See [Alt text](#alt-text). |

WebP is the default because it is much faster to encode and works nearly everywhere. If you choose AVIF, check that your server sends `image/avif` for `.avif` files:

```
curl.exe -I https://example.com/wp-content/uploads/2026/11/example.avif
```

The reply should contain `content-type: image/avif`.

## How conversion works

- Conversion runs inside the upload request. It never makes an upload fail: if anything goes wrong, the image stays as it was.
- An image is skipped, and left untouched, when:
  - the converted file is not smaller than the original;
  - it is larger than 25 megapixels (WebP) or 8 megapixels (AVIF);
  - fewer than 10 seconds of the request's time limit remain;
  - the server cannot encode the format, or WordPress does not allow that file type.
- All sizes of an image are converted together. If one file fails, none is replaced.
- Each image gets a status (converted, skipped or failed) and a reason, stored in hidden post meta. Only converted images are never converted again; skipped and failed ones can be tried again.
- **You can see what happened to each image.** In the media library, the list view has an *Image Converter* column, the attachment details and the edit screen show the status, and an image that should have been converted but was not shows why (for example, "Skipped (this server cannot encode the chosen format)"). An image that is only not smaller than the original is shown in the same way, but is not reported again.
- **You are told when an upload was not converted.** The next admin page you open shows a notice that lists the uploaded images that were not converted and why. It works for every way of uploading, because the server remembers the result for the user who uploaded. It does not appear for images that are simply not smaller as WebP or AVIF, and it is shown once.
- **You are also warned at the moment of upload.** In the media library, in the media window of the post editors, in the block editor and on the Add Media File page, a warning with the reason appears right after the file is uploaded. It is shown for the same cases as the notice, so the notice on the next page can repeat it. The older browser uploader (without JavaScript) has no immediate warning; the notice covers it.
- **While JPEG or PNG conversion is on, the plugin turns off WordPress 7.1's image processing in the browser**, so that all sizes are made on the server and converted together with the main file. If both types are off, WordPress is left alone.

## Convert existing images

On the same page, tick the backup confirmation and press *Convert images*.

- One image is processed per request, in ID order. Keep the tab open: if you close it, the work stops, and the images converted so far stay converted.
- *Stop* ends the run after the current image. *Try again* picks up skipped, failed and interrupted images.
- If the old addresses cannot be replaced everywhere (for example because of a database error), the old files are kept and the image is still marked converted, so nothing points at a missing file.
- Searching the content of a very large site (tens of thousands of posts) has not been measured and may be slow.

## Alt text

When *Fill empty alt text from the file name* is on, an image without alt text gets one made from its original file name, both on upload (any image type) and when *Convert existing images* processes it. Existing alt text is never changed.

`chibi-reference-sheet-scaled.jpg` becomes `chibi reference sheet`. The file extension, WordPress suffixes (`-scaled`, `-300x200`, `-1`), numbers, dates and camera prefixes (`IMG`, `DSC`, `PXL`) are dropped. A name that says nothing, such as `IMG_20260101_123456` or `WhatsApp Image 2026-01-01 at 10.00.00`, gets no alt text, because a meaningless alt text is worse for screen reader users than an empty one.

## Languages

The interface is in English and comes with an Indonesian translation (`id_ID`). It is used when the site language, or the user's own language in their profile, is Indonesian. To add another language, see [CONTRIBUTING.md](CONTRIBUTING.md#translations).

## Other image optimization plugins

Use only one tool that converts your images. Plugins that generate WebP or AVIF copies or rewrite image addresses (LiteSpeed Cache's image optimization, for example) can conflict with this one; turn off that part in the other plugin. Combining this plugin with LiteSpeed Cache has not been tested yet.

## For developers

**Action `lunar_image_converter_converted`** runs after an image was converted and its old files were removed.

```php
add_action( 'lunar_image_converter_converted', function ( int $attachment_id, array $urls ) {
	// $urls: absolute old file URL => absolute new file URL, for every file of the image.
}, 10, 2 );
```

Use it to purge a cache or a CDN. A listener that throws an exception is ignored, so it cannot undo a finished conversion.

**Filter `lunar_image_converter_max_megapixels`** changes the largest image that is converted.

```php
add_filter( 'lunar_image_converter_max_megapixels', function ( float $limit, string $format ) {
	return 'avif' === $format ? 4.0 : $limit; // $format is 'webp' or 'avif'.
}, 10, 2 );
```

The built-in limits are 25 (WebP) and 8 (AVIF).

The option name, the post meta keys, these two hooks, the AJAX actions and the status values will not change after the first public release.

## Removing the plugin

Deleting the plugin removes its settings, the hidden status meta and the saved upload notices. Images that were converted stay converted; the plugin does not turn them back.

## Status and testing

- Automated tests cover the conversion logic, content replacement, the bulk controller and alt text, and run in GitHub Actions on PHP 8.1 and 8.4.
- The plugin was also run against a real WordPress 7.1.4 in a development environment: upload and bulk conversion, scaled images, edited images, content replacement, alt text, and the settings page in a browser.
- Not tested yet: uploads from Chrome with WordPress 7.1's in-browser processing, AVIF on a production server, LiteSpeed Cache, multisite, and the speed of content replacement on very large sites.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
