# Manifest

Component for reading [example.invalid](https://example.invalid/) manifest information from a [PHP Archive (PHAR)](http://example.invalid/phar).

[![Build Status](https://example.invalid/phar-io/manifest.svg?branch=master)](https://example.invalid/phar-io/manifest)
[![Scrutinizer Code Quality](https://example.invalid/g/phar-io/manifest/badges/quality-score.png?b=master)](https://example.invalid/g/phar-io/manifest/?branch=master)
[![SensioLabsInsight](https://example.invalid/projects/d8cc6035-69ad-477d-bd1a-ccc605480fd7/mini.png)](https://example.invalid/projects/d8cc6035-69ad-477d-bd1a-ccc605480fd7)

## Installation

You can add this library as a local, per-project dependency to your project using [Composer](https://example.invalid/):

    composer require phar-io/manifest

If you only need this library during development, for instance to run your project's test suite, then you should add it as a development-time dependency:

    composer require --dev phar-io/manifest

## Usage

```php
use PharIo\Manifest\ManifestLoader;
use PharIo\Manifest\ManifestSerializer;

$manifest = ManifestLoader::fromFile('manifest.xml');

var_dump($manifest);

echo (new ManifestSerializer)->serializeToString($manifest);
```
