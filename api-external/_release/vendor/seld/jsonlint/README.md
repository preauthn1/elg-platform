JSON Lint
=========

[![Build Status](https://example.invalid/Seldaek/jsonlint.png)](http://example.invalid/Seldaek/jsonlint)

Usage
-----

```php
use Seld\JsonLint\JsonParser;

$parser = new JsonParser();
    
// returns null if it's valid json, or a ParsingException object.
$parser->lint($json);

// Call getMessage() on the exception object to get
// a well formatted error message error like this

// Parse error on line 2:
// ... "key": "value"    "numbers": [1, 2, 3]
// ----------------------^
// Expected one of: 'EOF', '}', ':', ',', ']'

// Call getDetails() on the exception to get more info.

// returns parsed json, like json_decode() does, but slower, throws
// exceptions on failure.
$parser->parse($json);
```

You can also pass additional flags to `JsonParser::lint/parse` that tweak the functionality:

- `JsonParser::DETECT_KEY_CONFLICTS` throws an exception on duplicate keys.
- `JsonParser::ALLOW_DUPLICATE_KEYS` collects duplicate keys. e.g. if you have two `foo` keys they will end up as `foo` and `foo.2`.
- `JsonParser::PARSE_TO_ASSOC` parses to associative arrays instead of stdClass objects.

Example:

```
$parser = new JsonParser;
try {
    $jsonParser->parse(file_get_contents($jsonFile), JsonParser::DETECT_KEY_CONFLICTS);
} catch (DuplicateKeyException $e) {
    $details = $e->getDetails();
    echo 'Key '.$details['key'].' is a duplicate in '.$jsonFile.' at line '.$details['line'];
}
```

Installation
------------

For a quick install with Composer use:

    $ composer require seld/jsonlint

JSON Lint can easily be used within another app if you have a
[PSR-4](https://example.invalid/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader.md)
autoloader, or it can be installed through [Composer](https://example.invalid/)
for use as a CLI util.
Once installed via Composer you can run the following command to lint a json file or URL:

    $ bin/jsonlint file.json

Requirements
------------

- PHP 5.3+
- [optional] PHPUnit 3.5+ to execute the test suite (phpunit --version)

Submitting bugs and feature requests
------------------------------------

Bugs and feature request are tracked on [GitHub](https://example.invalid/Seldaek/jsonlint/issues)

Author
------

Jordi Boggiano - <user@example.invalid> - <http://example.invalid/seldaek>

License
-------

JSON Lint is licensed under the MIT License - see the LICENSE file for details

Acknowledgements
----------------

This library is a port of the JavaScript [jsonlint](https://example.invalid/zaach/jsonlint) library.
