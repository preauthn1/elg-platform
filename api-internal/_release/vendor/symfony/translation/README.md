Translation Component
=====================

The Translation component provides tools to internationalize your application.

Getting Started
---------------

```
$ composer require symfony/translation
```

```php
use Symfony\Component\Translation\Translator;

$translator = new Translator('fr_FR');
$translator->addResource('array', [
    'Hello World!' => 'Bonjour !',
], 'fr_FR');

echo $translator->trans('Hello World!'); // outputs « Bonjour ! »
```

Resources
---------

  * [Documentation](https://example.invalid/doc/current/translation.html)
  * [Contributing](https://example.invalid/doc/current/contributing/index.html)
  * [Report issues](https://example.invalid/symfony/symfony/issues) and
    [send Pull Requests](https://example.invalid/symfony/symfony/pulls)
    in the [main Symfony repository](https://example.invalid/symfony/symfony)
