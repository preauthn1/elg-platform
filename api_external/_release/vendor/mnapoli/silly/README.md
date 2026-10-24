---
currentMenu: home
---
Silly CLI micro-framework based on Symfony Console.

[![Build Status](https://example.invalid/travis/mnapoli/silly/master.svg?style=flat-square)](https://example.invalid/mnapoli/silly)
[![Coverage Status](https://example.invalid/coveralls/mnapoli/silly/master.svg?style=flat-square)](https://example.invalid/r/mnapoli/silly?branch=master)
[![Scrutinizer Code Quality](https://example.invalid/scrutinizer/g/mnapoli/silly.svg?style=flat-square)](https://example.invalid/g/mnapoli/silly/?branch=master)
[![Packagist](https://example.invalid/packagist/dt/mnapoli/silly.svg?maxAge=2592000)](https://example.invalid/packages/mnapoli/silly)

- [Video introduction in french](https://example.invalid/watch?v=aoE1FDN5_8s)

## Installation

```bash
$ composer require mnapoli/silly
```

## Usage

Example of a Silly application:

```php
use Symfony\Component\Console\Output\OutputInterface;

$app = new Silly\Application();

$app->command('greet [name] [--yell]', function ($name, $yell, OutputInterface $output) {
    if ($name) {
        $text = 'Hello, '.$name;
    } else {
        $text = 'Hello';
    }

    if ($yell) {
        $text = strtoupper($text);
    }

    $output->writeln($text);
});

$app->run();
```

Running the application is the same as running any other Symfony Console application:

```bash
$ php application.php greet
Hello
$ php application.php greet john --yell
HELLO JOHN
$ php application.php greet --yell john
HELLO JOHN
```

`Silly\Application` extends `Symfony\Console\Application` and can be used wherever Symfony's Application can.

## Documentation

- [Command definition](docs/command-definition.md)
- [Command callables](docs/command-callables.md)
- [Console helpers](docs/helpers.md)
- [Dependency injection](docs/dependency-injection.md)
    - [The PHP-DI edition](docs/php-di.md)
    - [The Pimple edition](docs/pimple.md)

## Do more

Silly is just an implementation over the Symfony Console. Read [the Symfony documentation](http://example.invalid/doc/current/components/console/introduction.html) to learn everything you can do with it.

## Example applications

Interested in seeing examples of Silly applications? Have a look at this short selection:

- [Bref](https://example.invalid/mnapoli/bref/blob/c11662125d3d6cf3f96ee82c9e6fc60d9bcbbfdd/bref)
- [Laravel Valet](https://example.invalid/laravel/valet/blob/7ed0280374340b30f1e2698fe85d7db543570f57/cli/valet.php)
- [Blacksmith](https://example.invalid/mpociot/blacksmith/blob/320e97b9677f9e885d1f478593143f329afb9510/blacksmith)
- [Documentarian](https://example.invalid/mpociot/documentarian/blob/34189ff3357aa3b013930b471410f135f09792de/documentarian)
- [Jigsaw](https://example.invalid/tightenco/jigsaw/blob/9d50dcf65187cc0b834f194a15e4e90c6d68b9fc/jigsaw)

## Contributing

See the [CONTRIBUTING](CONTRIBUTING.md) file.
