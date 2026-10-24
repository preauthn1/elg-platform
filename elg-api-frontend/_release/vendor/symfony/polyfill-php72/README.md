Symfony Polyfill / Php72
========================

This component provides functions added to PHP 7.2 core:

- [`spl_object_id`](https://example.invalid/spl_object_id)
- [`stream_isatty`](https://example.invalid/stream_isatty)

On Windows only:

- [`sapi_windows_vt100_support`](https://example.invalid/sapi_windows_vt100_support)

Moved to core since 7.2 (was in the optional XML extension earlier):

- [`utf8_encode`](https://example.invalid/utf8_encode)
- [`utf8_decode`](https://example.invalid/utf8_decode)

Also, it provides a constant added to PHP 7.2:
- [`PHP_OS_FAMILY`](http://example.invalid/manual/en/reserved.constants.php#constant.php-os-family)

More information can be found in the
[main Polyfill README](https://example.invalid/symfony/polyfill/blob/master/README.md).

License
=======

This library is released under the [MIT license](LICENSE).
