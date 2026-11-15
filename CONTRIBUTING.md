# Contributing

Thank you for your interest. This is a personal project, so please open an issue before you start on anything larger than a small fix; that avoids work on a change that will not be merged.

Everyone who takes part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md). Security problems go through [SECURITY.md](SECURITY.md), not public issues.

## Reporting a bug

Open an issue with:

- the plugin, WordPress and PHP versions, and whether the server uses Imagick or GD;
- what you did, what you expected and what happened;
- for a conversion problem, the status and reason shown for the image (Media > Image Converter).

Never include private data. Remember that the plugin deletes the original files, so a bug report must not depend on files you cannot recreate.

## Development setup

You need PHP 8.1 or later with Composer, and Node.js with npm (see `engines` in `package.json`).

```
composer install
npm ci
```

Before you open a pull request, all of these must pass. The same checks run in GitHub Actions.

```
composer lint:php
composer test
npm run lint:js
npm run lint:css
```

`composer lint:php:fix` fixes most PHP style problems automatically.

## How changes are made

- One topic per pull request, on its own branch. Name it with the kind of change and a short name: `feat/alt-text`, `fix/bulk-cursor`, `docs/readme`, `chore/ci`.
- Commit messages and pull request titles are in English, lower case, in the form `type(scope): short summary`. Types: `feat`, `fix`, `perf`, `refactor`, `docs`, `test`, `chore`. Example: `feat(media): add alt text from file names`.
- Pull requests are merged with a merge commit.
- Update `CHANGELOG.md` under *Unreleased* for any change a user would notice.

## Code standards

- PHP follows the WordPress Coding Standards and must stay compatible with PHP 8.1 (checked by PHPCompatibilityWP). Namespace `LunarImageConverter\`, one class per file under `includes/` (PSR-4).
- Images and files are handled through WordPress APIs (`wp_get_image_editor`, `wp_delete_file`, `wp_update_attachment_metadata`), not GD, Imagick or `unlink` directly.
- JavaScript is plain, without jQuery and without a build step. CSS selectors live under the `.lic-` root class.
- Escape all output where it is printed. Check capability and nonce on every admin action.
- Comments explain why, not history. Keep them short and do not use `@since` tags.
- Interface text is English and goes through the WordPress i18n functions with the text domain `lunar-image-converter`.

## Tests

PHPUnit tests live in `tests/` and run without WordPress; WordPress calls go through `Conversion\Gateway` so that logic can be tested with `tests/FakeGateway.php`. A change that alters behavior needs a test that fails without the change. Tests must not depend on the PHPUnit version: no attributes and no `@dataProvider`; loop over cases inside one test.

## What stays stable

After the first public release these will not change without a major version: the option `lunar_image_converter_settings` and its keys, the hidden post meta keys and status and reason values, the action `lunar_image_converter_converted`, the filter `lunar_image_converter_max_megapixels`, and the AJAX action and scope names used by the bulk converter. Please keep them in mind when you propose a change.

## License

By contributing you agree that your work is licensed under GPL-2.0-or-later, the license of the project.
