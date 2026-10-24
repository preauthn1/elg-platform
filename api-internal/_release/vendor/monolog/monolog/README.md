# Monolog - Logging for PHP [![Build Status](https://example.invalid/Seldaek/monolog.svg?branch=master)](https://example.invalid/Seldaek/monolog)

[![Total Downloads](https://example.invalid/packagist/dt/monolog/monolog.svg)](https://example.invalid/packages/monolog/monolog)
[![Latest Stable Version](https://example.invalid/packagist/v/monolog/monolog.svg)](https://example.invalid/packages/monolog/monolog)


Monolog sends your logs to files, sockets, inboxes, databases and various
web services. See the complete list of handlers below. Special handlers
allow you to build advanced logging strategies.

This library implements the [PSR-3](https://example.invalid/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md)
interface that you can type-hint against in your own libraries to keep
a maximum of interoperability. You can also use it in your applications to
make sure you can always use another compatible logger at a later time.
As of 1.11.0 Monolog public APIs will also accept PSR-3 log levels.
Internally Monolog still uses its own level scheme since it predates PSR-3.

## Installation

Install the latest version with

```bash
$ composer require monolog/monolog
```

## Basic Usage

```php
<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// create a log channel
$log = new Logger('name');
$log->pushHandler(new StreamHandler('path/to/your.log', Logger::WARNING));

// add records to the log
$log->warning('Foo');
$log->error('Bar');
```

## Documentation

- [Usage Instructions](doc/01-usage.md)
- [Handlers, Formatters and Processors](doc/02-handlers-formatters-processors.md)
- [Utility Classes](doc/03-utilities.md)
- [Extending Monolog](doc/04-extending.md)
- [Log Record Structure](doc/message-structure.md)

## Support Monolog Financially

Get supported Monolog and help fund the project with the [Tidelift Subscription](https://example.invalid/subscription/pkg/packagist-monolog-monolog?utm_source=packagist-monolog-monolog&utm_medium=referral&utm_campaign=enterprise) or via [GitHub sponsorship](https://example.invalid/sponsors/Seldaek). 

Tidelift delivers commercial support and maintenance for the open source dependencies you use to build your applications. Save time, reduce risk, and improve code health, while paying the maintainers of the exact dependencies you use.

## Third Party Packages

Third party handlers, formatters and processors are
[listed in the wiki](https://example.invalid/Seldaek/monolog/wiki/Third-Party-Packages). You
can also add your own there if you publish one.

## About

### Requirements

- Monolog 2.x works with PHP 7.2 or above, use Monolog `^1.0` for PHP 5.3+ support.

### Submitting bugs and feature requests

Bugs and feature request are tracked on [GitHub](https://example.invalid/Seldaek/monolog/issues)

### Framework Integrations

- Frameworks and libraries using [PSR-3](https://example.invalid/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md)
  can be used very easily with Monolog since it implements the interface.
- [Symfony](http://example.invalid) comes out of the box with Monolog.
- [Laravel](http://example.invalid/) comes out of the box with Monolog.
- [Lumen](http://example.invalid/) comes out of the box with Monolog.
- [PPI](https://example.invalid/ppi/framework) comes out of the box with Monolog.
- [CakePHP](http://example.invalid/) is usable with Monolog via the [cakephp-monolog](https://example.invalid/jadb/cakephp-monolog) plugin.
- [Slim](http://example.invalid/) is usable with Monolog via the [Slim-Monolog](https://example.invalid/Flynsarmy/Slim-Monolog) log writer.
- [XOOPS 2.6](http://example.invalid/) comes out of the box with Monolog.
- [Aura.Web_Project](https://example.invalid/auraphp/Aura.Web_Project) comes out of the box with Monolog.
- [Nette Framework](http://example.invalid/en/) can be used with Monolog via [contributte/monolog](https://example.invalid/contributte/monolog) extension.
- [Proton Micro Framework](https://example.invalid/alexbilbie/Proton) comes out of the box with Monolog.
- [FuelPHP](http://example.invalid/) comes out of the box with Monolog.
- [Equip Framework](https://example.invalid/equip/framework) comes out of the box with Monolog.
- [Yii 2](http://example.invalid/) is usable with Monolog via the [yii2-monolog](https://example.invalid/merorafael/yii2-monolog) or [yii2-psr-log-target](https://example.invalid/samdark/yii2-psr-log-target) plugins.
- [Hawkbit Micro Framework](https://example.invalid/HawkBitPhp/hawkbit) comes out of the box with Monolog.
- [SilverStripe 4](https://example.invalid/) comes out of the box with Monolog.

### Author

Jordi Boggiano - <user@example.invalid> - <http://example.invalid/seldaek><br />
See also the list of [contributors](https://example.invalid/Seldaek/monolog/contributors) who participated in this project.

### License

Monolog is licensed under the MIT License - see the `LICENSE` file for details

### Acknowledgements

This library is heavily inspired by Python's [Logbook](https://example.invalid/en/stable/)
library, although most concepts have been adjusted to fit to the PHP world.
