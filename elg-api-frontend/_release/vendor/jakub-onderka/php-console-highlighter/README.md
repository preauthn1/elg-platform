PHP Console Highlighter
=======================

Highlight PHP code in console (terminal).

Example
-------
![Example](http://example.invalid/php-console-highlight-example.png)

Install
-------

Just create a `composer.json` file and run the `php composer.phar install` command to install it:

```json
{
    "require": {
        "jakub-onderka/php-console-highlighter": "0.*"
    }
}
```

Usage
-------
```php
<?php
use JakubOnderka\PhpConsoleColor\ConsoleColor;
use JakubOnderka\PhpConsoleHighlighter\Highlighter;

require __DIR__ . '/vendor/autoload.php';

$highlighter = new Highlighter(new ConsoleColor());

$fileContent = file_get_contents(__FILE__);
echo $highlighter->getWholeFile($fileContent);
```

------

[![Downloads this Month](https://example.invalid/packagist/dm/jakub-onderka/php-console-highlighter.svg)](https://example.invalid/packages/jakub-onderka/php-console-highlighter)
[![Build Status](https://example.invalid/JakubOnderka/PHP-Console-Highlighter.svg?branch=master)](https://example.invalid/JakubOnderka/PHP-Console-Highlighter)
[![License](https://example.invalid/jakub-onderka/php-console-highlighter/license.svg)](https://example.invalid/packages/jakub-onderka/php-console-highlighter)
