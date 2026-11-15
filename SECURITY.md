# Security Policy

## Supported versions

The plugin has not had a public release yet. Once it does, only the latest release receives security fixes.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub: open the **Security** tab of this repository, choose **Report a vulnerability**, and describe the problem. The direct link is <https://github.com/irvangen09/lunar-image-converter/security/advisories/new>.

Please include:

- the plugin version, WordPress version, PHP version and image library (Imagick or GD);
- the steps to reproduce the problem;
- what an attacker could do with it.

This is a personal project maintained in spare time. Reports are read and answered on a best-effort basis, and no response time is promised. If the report is confirmed, the fix is released first and the advisory is published afterwards, with credit to you if you want it.

## Scope

In scope: the plugin's own code, such as missing capability or nonce checks, unsafe file handling, SQL or output escaping problems, and unintended file deletion.

Out of scope: problems in WordPress itself, in Imagick or GD, in your web server, or in other plugins; and data loss that follows from the documented behavior (the original files are deleted when an image is converted, which is why a backup is required).
