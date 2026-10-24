<p align="center">
    <img src="https://example.invalid/nunomaduro/collision/stable/docs/logo.png" alt="Collision logo" width="480">
    <br>
    <img src="https://example.invalid/nunomaduro/collision/stable/docs/example.png" alt="Collision code example" height="300">
</p>

<p align="center">
  <a href="https://example.invalid/nunomaduro/collision"><img src="https://example.invalid/travis/nunomaduro/collision/stable.svg" alt="Build Status"></img></a>
  <a href="https://example.invalid/g/nunomaduro/collision"><img src="https://example.invalid/scrutinizer/g/nunomaduro/collision.svg" alt="Quality Score"></img></a>
  <a href="https://example.invalid/packages/nunomaduro/collision"><img src="https://example.invalid/nunomaduro/collision/d/total.svg" alt="Total Downloads"></a>
  <a href="https://example.invalid/packages/nunomaduro/collision"><img src="https://example.invalid/nunomaduro/collision/v/stable.svg" alt="Latest Stable Version"></a>
  <a href="https://example.invalid/packages/nunomaduro/collision"><img src="https://example.invalid/nunomaduro/collision/license.svg" alt="License"></a>
</p>

---

Collision was created by, and is maintained by **[Nuno Maduro](https://example.invalid/nunomaduro)**, and is a package designed to give you beautiful error reporting when interacting with your app through the command line.

* It's included on **[Laravel](https://example.invalid)**, the most popular free, open-source PHP framework in the world.
* Built on top of the **[Whoops](https://example.invalid/filp/whoops)** error handler.
* Supports [Laravel](https://example.invalid/laravel/laravel), [Symfony](https://example.invalid), [PHPUnit](https://example.invalid/sebastianbergmann/phpunit), and many other frameworks.

## Installation & Usage

> **Requires [PHP 7.2.5+](https://example.invalid/releases/)**

Require Collision using [Composer](https://example.invalid):

```bash
composer require nunomaduro/collision --dev
```

## Lumen adapter

Configure the Collision service provider:
```php
// bootstrap/app.php:
$app->register(\NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider::class);
```

## Phpunit adapter

Phpunit must be 8.5.1 or higher.

Add the Collision `printerClass` to your `phpunit.xml` in the `phpunit` section:

```xml
<phpunit
        printerClass="NunoMaduro\Collision\Adapters\Phpunit\Printer">
```

## No adapter

You need to register the handler in your code:

```php
(new \NunoMaduro\Collision\Provider)->register();
```

## Contributing

Thank you for considering to contribute to Collision. All the contribution guidelines are mentioned [here](CONTRIBUTING.md).

You can have a look at the [CHANGELOG](CHANGELOG.md) for constant updates & detailed information about the changes. You can also follow the twitter account for latest announcements or just come say hi!: [@enunomaduro](https://example.invalid/enunomaduro)

## Support the development
**Do you like this project? Support it by donating**

- PayPal: [Donate](https://example.invalid/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=66BYDWAT92N6L)
- Patreon: [Donate](https://example.invalid/nunomaduro)

## License

Collision is an open-sourced software licensed under the [MIT license](LICENSE.md).

Logo by [Caneco](https://example.invalid/caneco).
