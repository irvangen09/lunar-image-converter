# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-11-27

### Fixed

- Replacing an image address in a post no longer overwrites a change someone saved in the meantime; when the post changed, the old files are kept and a later Bulk run finishes the cleanup.
- An image whose conversion was interrupted right after it was switched to the new files is now found by Bulk Generate (Try again) and finished, instead of staying "Interrupted" out of reach.
- The data passed to the Bulk Generate script can no longer end the script tag early, whatever a translation contains.

### Changed

- The README and CONTRIBUTING list everything that stays stable, and CONTRIBUTING describes how a release is made. A new CI check compares a release tag with the version in the files.

## [1.0.0] - 2026-11-24

First public release.

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

- *Try again* in Convert existing images also finishes the cleanup of images that were converted but whose old files were kept. The map of the kept files is stored in the hidden post meta `_lunar_image_converter_kept` until then; the counts show these images as *Old files kept*.
- Each image is locked while Convert existing images converts it, so two tabs cannot convert the same image at once. A lock that was left behind expires after five minutes and is removed on uninstall. The answer to a bulk request has a new field, `busy`.
- The bulk page explains a server error (a time limit or a crash) with the HTTP status and the number of images done, and keeps the focus, the scroll position and the screen reader announcements calm during a run.

### Changed

- Conversion on upload now only runs for real uploads: the media library, the media window, Add Media File and the REST API. Images whose sizes are made again (regenerating thumbnails) or that arrive through an importer are left alone and are converted by Convert existing images, which also replaces their addresses.
- Old files are now deleted only when no content that the plugin cannot change still contains their address; otherwise they are kept and the image is marked converted with the reason `old_files_kept`.
- The converted file must be a valid image of the same size as the original, and the plugin only reads and deletes files inside the uploads folder.
- An error after the attachment was switched to the new file no longer leaves it half done: the switch is undone, or the image is recorded as converted with the old files kept.
- Content is searched with one text for all files of an image instead of one search per file.
- An unexpected error while converting an upload is now recorded as failed with the reason `error`; before, the upload was left without any status.
- The copies that the image editor keeps to undo an edit are deleted together with the original files, so "Restore original" is no longer offered for a converted image.
- Image processing in the browser (WordPress 7.1) is turned off while conversion is on, so the sizes of an upload are made on the server and converted together with the main file.
- A converted file keeps the name of the file it replaces, for example `photo-scaled.webp` and `photo-150x150.webp`. Before, WordPress added a number (`photo-scaled-1.webp`) to every name that ends in `-scaled`, `-rotated` or a size, even when nothing was in the way. A number is now added only when the name is really taken. Images that were converted earlier are not renamed.

### Fixed

- The *Quality* setting is now applied. When a JPEG or PNG is saved as WebP or AVIF, WordPress set the quality again to its own default of the new format (86 for WebP) and dropped the saved value, so every conversion came out at the same quality and size whatever the setting. The saved quality now reaches the encoder, for the main file and every size.

## [0.1.0] - 2026-11-12

### Added

- Plugin skeleton: requirements check, autoloader, bootstrap and uninstall cleanup.
- Settings with defaults and sanitization.
