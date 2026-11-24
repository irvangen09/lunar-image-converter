# Lunar Image Converter

A WordPress plugin that converts JPEG and PNG images to WebP or AVIF, replaces the originals, and updates the image addresses in your content.

> **Status: in development (0.1.0), not released yet.** It is built for one site and published openly on GitHub. It is not on WordPress.org and no user support is promised. See [Status and testing](#status-and-testing).

## What it does

- **Converts on upload.** A new JPEG or PNG that you upload in the media library, the media window of the editors, the Add Media File page or through the REST API becomes a WebP or AVIF file, together with every size WordPress makes for it. The original files are deleted. Images that arrive any other way (a site import, a plugin that regenerates thumbnails) are left alone, because their addresses may already be in your content; convert them with *Convert existing images*, which also replaces the addresses.
- **Converts the images you already have.** *Convert existing images* works through the media library one image at a time, with progress, a stop button and a retry for images that were skipped or failed.
- **Replaces addresses in your content.** When an existing image is converted, its old addresses are replaced in posts, pages, other public post types, reusable blocks, templates, template parts and navigation menus. If the address of the image is still found anywhere else (in the custom CSS of a theme, say), the old files are kept, so nothing points at a missing file.
- **Keeps what is not worth converting.** If the result is not smaller than the original, the original stays untouched.
- **Optionally fills empty alt text** from the file name.

The attachment ID never changes, so featured images, galleries and anything else that refers to an image by ID keep working.

## Read this before you use it

- **The original files are deleted and this cannot be undone.** That includes the resized copies and the large original that WordPress keeps when it scales a big image down (`original_image`).
- **"Restore original" in the image editor is no longer available** for a converted image. The backups the editor keeps for undoing edits are deleted too.
- **Back up your uploads folder and your database** before you convert existing images. The plugin asks you to confirm this every time you open the page.
- Only the content listed above is searched. **Custom fields, page builder data, serialized data, revisions, excerpts and images on a CDN are not changed.** An image used there can stop working after it is converted.
- **Only addresses on the host of your uploads address are replaced** (with `http` or `https`, with the address in full or starting at the site root). A post that uses another host name for the same image, such as the `www` form of a site that does not use it, an older domain or a staging address, is not changed, and its image stops working once the old file is gone.
- **Caches are not cleared.** After you convert images, clear your page cache and your CDN, or pages keep pointing at the old files. See *For developers* to do this automatically.
- Converting a large image needs memory. The plugin asks WordPress for the larger memory limit that it uses for images, but a host can refuse; an image that runs out of memory is left as it was or shown as interrupted.

## Requirements

- WordPress 7.0 or later
- PHP 8.1 or later
- A server that can encode the format you choose. WordPress uses Imagick or GD, whichever it finds. The settings page tests it with a small image, so it shows what your server really does, not only what it claims.
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
| Quality | 80 | 1 to 100. Higher means better quality and larger files. One number is used for the format you chose. Both formats are lossy, but the same number does not give the same look or size in WebP and AVIF: at the same number AVIF can come out larger than WebP. Compare a few of your own images before you pick a number. |
| Image types | JPEG and PNG on | Each can be turned off. GIF and other types are never converted. |
| Fill empty alt text from the file name | Off | See [Alt text](#alt-text). |

WebP is the default because it is much faster to encode and works nearly everywhere. If you choose AVIF, check that your server sends `image/avif` for `.avif` files:

```
curl.exe -I https://example.com/wp-content/uploads/2026/11/example.avif
```

The reply should contain `content-type: image/avif`.

The settings page runs two checks for you, under *Server support*:

- **Whether each format can be made.** The plugin encodes a small test image and reads the file it gets. "Works" means a real file came out. If the image library says it supports AVIF but the test fails, it says so: the library is built without a working AVIF encoder (common with Imagick on a local Windows setup or on shared hosting). Conversion to AVIF will fail there. Use WebP, or ask your host.
- **Whether the server sends AVIF files correctly.** The site fetches a small AVIF file from its own address and reads the `Content-Type`. If it is not `image/avif`, browsers do not show the images. "Could not check" does not mean the server is wrong: a firewall, a security plugin or a server that cannot reach its own address can block the test. Use the `curl.exe` command above instead.

The checks only inform. They never stop a conversion. The results are kept for 12 hours (10 minutes if the site could not reach itself); press *Check again* after you change something. The test file is fetched with a changing query, so a cache that still holds an old answer for your real images is not seen: clear it too.

### Sending AVIF with the right type

If the page says AVIF is sent as another type, add the type `image/avif` for the extension `avif`:

- **cPanel:** *MIME Types*, then type `image/avif` and extension `avif`, and add it. The type must be `image/avif`; `image/webp` or `application/octet-stream` is wrong.
- **Apache and LiteSpeed:** add this to `.htaccess`, outside the `# BEGIN WordPress` and `# END WordPress` lines, which WordPress rewrites:

  ```
  <IfModule mod_mime.c>
  	AddType image/avif .avif
  </IfModule>
  ```

- **nginx:** add `image/avif avif;` to `mime.types`, or add this to the server block:

  ```
  location ~* \.avif$ {
  	default_type image/avif;
  }
  ```

Then clear every cache between your server and the visitor: your caching plugin (for example LiteSpeed Cache: purge all) and Cloudflare or another CDN (purge the cache or the URLs). A CDN keeps the old `Content-Type` of a file it already has. Press *Check again* and test one of your own images with the command above.

## How conversion works

- Conversion runs inside the upload request. A failed conversion does not make the upload fail: the image stays as it was. A server that runs out of time or memory in the middle of a conversion can still end the request; the image is then shown as interrupted and can be tried again.
- An image is skipped, and left untouched, when:
  - the converted file is not smaller than the original;
  - it is larger than 25 megapixels (WebP) or 8 megapixels (AVIF);
  - fewer than 10 seconds of the request's time limit remain;
  - the server cannot encode the format, or WordPress does not allow that file type;
  - the converted file is not a valid image of the same size as the original, or a file lies outside the uploads folder.
- All sizes of an image are converted together. If one file fails, none is replaced.
- A converted file keeps the name of the file it replaces (`photo-scaled.jpg` becomes `photo-scaled.webp`, `photo-150x150.jpg` becomes `photo-150x150.webp`). A number is added only when that name is already taken, and an existing file is never overwritten.
- The Quality setting is applied to every file of an image, the main file and each size.
- Each image gets a status (converted, skipped or failed) and a reason, stored in hidden post meta. Only converted images are never converted again; skipped and failed ones can be tried again.
- **You can see what happened to each image.** In the media library, the list view has an *Image Converter* column, the attachment details and the edit screen show the status, and an image that should have been converted but was not shows why (for example, "Skipped (this server cannot encode the chosen format)"). An image that is only not smaller than the original is shown in the same way, but is not reported again.
- **You are told when an upload was not converted.** The next admin page you open shows a notice that lists the uploaded images that were not converted and why. It works for every way of uploading, because the server remembers the result for the user who uploaded. It does not appear for images that are simply not smaller as WebP or AVIF, and it is shown once.
- **You are also warned at the moment of upload.** In the media library, in the media window of the post editors, in the block editor and on the Add Media File page, a warning with the reason appears right after the file is uploaded. It is shown for the same cases as the notice, so the notice on the next page can repeat it. The older browser uploader (without JavaScript) has no immediate warning; the notice covers it.
- **While JPEG or PNG conversion is on, the plugin turns off WordPress 7.1's image processing in the browser**, so that all sizes are made on the server and converted together with the main file. If both types are off, WordPress is left alone.

## Convert existing images

On the same page, tick the backup confirmation and press *Convert images*.

- One image is processed per request, in ID order. Keep the tab open: if you close it, the work stops, and the images converted so far stay converted.
- *Stop* ends the run after the current image. *Try again* picks up skipped, failed and interrupted images, and converted images whose old files were kept.
- If the old addresses cannot be replaced everywhere (for example because of a database error, or because the address is also used in content that the plugin does not change), the old files are kept and the image is still marked converted, with the reason "old files kept", so nothing points at a missing file. *Try again* finishes the cleanup of such an image once the addresses can be replaced; it does not convert the image a second time.
- Each image is locked while it is converted. If you open the page in two tabs, or two people run it, an image that the other one is working on is skipped. A lock that was left behind by a request that died expires after five minutes.
- If the server stops answering (a time limit or a crash), the page says so, with the status code, and how many images were done. The image that was being converted is shown as interrupted; use *Try again* to continue.
- Clear your page cache and CDN when the run is done.
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

Use it to purge a cache or a CDN. A listener that throws an exception is ignored, so it cannot undo a finished conversion. The plugin does not purge any cache itself, because every caching plugin does it differently.

For LiteSpeed Cache, press *Purge All* (Toolbox) once after a run of *Convert existing images*. To purge after every converted image instead, use its public action. This empties the whole cache each time, so it is slow during a long run:

```php
add_action( 'lunar_image_converter_converted', static function () {
	do_action( 'litespeed_purge_all' );
} );
```

LiteSpeed Cache's own image optimization only rewrites addresses that end in `.jpg`, `.jpeg`, `.png` or `.gif`, and only when it has made a `.webp` or `.avif` copy of that file. Files that this plugin made end in `.webp` or `.avif`, so it should leave them alone. That comes from reading its code; it was not tested here. Do not run both for the same images.

**Filter `lunar_image_converter_max_megapixels`** changes the largest image that is converted.

```php
add_filter( 'lunar_image_converter_max_megapixels', function ( float $limit, string $format ) {
	return 'avif' === $format ? 4.0 : $limit; // $format is 'webp' or 'avif'.
}, 10, 2 );
```

The built-in limits are 25 (WebP) and 8 (AVIF).

The option name, the post meta keys, these two hooks, the AJAX actions and the status values will not change after the first public release. The rows that lock an image while it is converted are internal and may change.

## Removing the plugin

Deleting the plugin removes its settings, the hidden status meta, the saved upload notices, the saved server test results and any lock that was left behind. Images that were converted stay converted; the plugin does not turn them back.

## Status and testing

- Automated tests cover the conversion logic, content replacement, the bulk controller, alt text and the quality setting, and run in GitHub Actions on PHP 8.1 and 8.4.
- The plugin was run against a real WordPress 7.1.4 in a development environment: upload and bulk conversion, scaled images, edited images, content replacement, alt text, and the settings page in a browser.
- The maintainer also tested it by hand on a real WordPress site on a hosting account, with a few hundred images: conversion on upload, regenerating thumbnails (nothing is converted), *Convert existing images* with progress, stop and resume, two tabs running at once, file names without added numbers, the Quality setting with WebP and AVIF, AVIF with the delivery check on the settings page, and the Indonesian settings page and media library column.
- Not tested yet: AVIF for images above the size limit and the speed of AVIF on a large library, the Indonesian translation of the bulk page and the upload warnings, uploads from Chrome with WordPress 7.1's in-browser processing, the page cache of LiteSpeed Cache with this plugin (its image optimization is described under *For developers*), multisite, importing a site, and the speed of content replacement on very large sites. The bulk page was checked in a browser with a test server, not yet with screen readers.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
