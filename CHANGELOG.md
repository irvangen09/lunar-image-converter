# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Settings page under Media > Image Converter, with a server support check for WebP and AVIF.
- Conversion on upload: new JPEG and PNG images become WebP or AVIF, with all their sizes, and the original files are deleted. An image is left untouched when the result is not smaller, when it is too large, when the server cannot encode the format, or when anything fails along the way. Images uploaded earlier are not converted yet.
- Conversion status is stored per image in post meta and removed on uninstall.

### Changed

- Image processing in the browser (WordPress 7.1) is turned off while conversion is on, so the sizes of an upload are made on the server and converted together with the main file.

## [0.1.0] - 2026-11-12

### Added

- Plugin skeleton: requirements check, autoloader, bootstrap and uninstall cleanup.
- Settings with defaults and sanitization.
