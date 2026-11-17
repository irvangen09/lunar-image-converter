# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Settings page under Media > Image Converter, with a server support check for WebP and AVIF.
- Conversion on upload: new JPEG and PNG images become WebP or AVIF, with all their sizes, and the original files are deleted. An image is left untouched when the result is not smaller, when it is too large, when the server cannot encode the format, or when anything fails along the way.
- Conversion status is stored per image in post meta and removed on uninstall.
- Convert existing images from Media > Image Converter, one image per request, with progress, a stop button and a retry for skipped and failed images. Each image that is converted this way is replaced in the content of posts, pages, reusable blocks, templates and navigation menus, in absolute, scheme-relative, root-relative, escaped and percent-encoded forms. Revisions, custom fields and page builder data are not changed.
- The `lunar_image_converter_converted` action runs after an image was converted, with the attachment ID and a map of old file URLs to new file URLs.
- Indonesian (id_ID) translation of the settings page, the bulk converter and the plugin description, with a translation template (`languages/lunar-image-converter.pot`). Tests check that the template matches the code and that translations keep their placeholders.
- Optional alt text from the file name (setting "Fill empty alt text from the file name", off by default): new uploads of any image type and images processed by Convert existing images get an alt text made from the original file name when they have none. Existing alt text is never changed, and names that are only numbers, dates, camera codes or hashes are skipped.
- Images that were being converted when a request ran out of time are shown as interrupted and can be retried.
- The conversion status of an image is shown in the media library: an *Image Converter* column in the list view, a line in the attachment details and in the Save box of the edit screen. An image that could be converted but was not shows "Not converted yet".
- A notice on the next admin page tells the user who uploaded images that were not converted, with the reason for each. Images that are only not smaller than the original are not reported. The notices are saved per user and removed on uninstall.
- A new reason, `format_unsupported`, is recorded when the saved output format cannot be encoded by the server. Such an image can be retried from Convert existing images once the format works.
- A warning at the moment of upload, without leaving the page, when an image was not converted: in the media library (grid), in the media window of the editors, in the block editor and on the Add Media File page. It is shown for the same cases as the notice, and says the output format and the reason. The result is sent in the answer the server already gives to an upload, so uploading makes no extra request. The answer to a REST upload has a new read-only field `lunar_image_converter` (only in the edit context).
- The *Server support* list on the settings page now tests the server instead of repeating what the image library claims: a small test image is encoded in each format and the file is checked. When the library says it supports a format but the test fails, the page says that conversion will probably fail. For AVIF, the site also fetches a small test file from its own address and checks that the server sends `image/avif`; a wrong type is shown in red and "could not check" in grey. The checks only inform and never block a conversion. Results are kept in a transient for 12 hours (10 minutes if the site could not reach itself), refreshed with a *Check again* button, and removed on uninstall.
- README: how to send AVIF with the right content type on cPanel, Apache, LiteSpeed and nginx, and how to clear caches afterwards.

### Changed

- An unexpected error while converting an upload is now recorded as failed with the reason `error`; before, the upload was left without any status.
- The copies that the image editor keeps to undo an edit are deleted together with the original files, so "Restore original" is no longer offered for a converted image.
- Image processing in the browser (WordPress 7.1) is turned off while conversion is on, so the sizes of an upload are made on the server and converted together with the main file.

## [0.1.0] - 2026-11-12

### Added

- Plugin skeleton: requirements check, autoloader, bootstrap and uninstall cleanup.
- Settings with defaults and sanitization.
