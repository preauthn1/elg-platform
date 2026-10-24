[![Latest Stable Version](https://example.invalid/phpunit/php-code-coverage/v/stable.png)](https://example.invalid/packages/phpunit/php-code-coverage)
[![Build Status](https://example.invalid/sebastianbergmann/php-code-coverage.svg?branch=5.3)](https://example.invalid/sebastianbergmann/php-code-coverage)

# SebastianBergmann\CodeCoverage

**SebastianBergmann\CodeCoverage** is a library that provides collection, processing, and rendering functionality for PHP code coverage information.

## Installation

You can add this library as a local, per-project dependency to your project using [Composer](https://example.invalid/):

    composer require phpunit/php-code-coverage

If you only need this library during development, for instance to run your project's test suite, then you should add it as a development-time dependency:

    composer require --dev phpunit/php-code-coverage

## Using the SebastianBergmann\CodeCoverage API

```php
<?php
use SebastianBergmann\CodeCoverage\CodeCoverage;

$coverage = new CodeCoverage;

$coverage->filter()->addDirectoryToWhitelist('/path/to/src');

$coverage->start('<name of test>');

// ...

$coverage->stop();

$writer = new \SebastianBergmann\CodeCoverage\Report\Clover;
$writer->process($coverage, '/tmp/clover.xml');

$writer = new \SebastianBergmann\CodeCoverage\Report\Html\Facade;
$writer->process($coverage, '/tmp/code-coverage-report');
```

