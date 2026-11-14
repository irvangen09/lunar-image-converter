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
- Images that were being converted when a request ran out of time are shown as interrupted and can be retried.

### Changed

- The copies that the image editor keeps to undo an edit are deleted together with the original files, so "Restore original" is no longer offered for a converted image.
- Image processing in the browser (WordPress 7.1) is turned off while conversion is on, so the sizes of an upload are made on the server and converted together with the main file.

## [0.1.0] - 2026-11-12

### Added

- Plugin skeleton: requirements check, autoloader, bootstrap and uninstall cleanup.
- Settings with defaults and sanitization.
