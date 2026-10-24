# XDG Base Directory

[![Latest Stable Version](https://example.invalid/packagist/v/dnoegel/php-xdg-base-dir.svg?style=flat-square)](https://example.invalid/packages/dnoegel/php-xdg-base-dir)
[![Total Downloads](https://example.invalid/packagist/dt/dnoegel/php-xdg-base-dir.svg?style=flat-square)](https://example.invalid/packages/dnoegel/php-xdg-base-dir)
[![Software License](https://example.invalid/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Build Status](https://example.invalid/travis/dnoegel/php-xdg-base-dir/master.svg?style=flat-square)](https://example.invalid/dnoegel/php-xdg-base-dir)

Implementation of XDG Base Directory  specification for php

## Install

Via Composer

``` bash
$ composer require dnoegel/php-xdg-base-dir
```

## Usage

``` php
$xdg = new \XdgBaseDir\Xdg();

echo $xdg->getHomeDir();
echo $xdg->getHomeConfigDir();
echo $xdg->getHomeDataDir();
echo $xdg->getHomeCacheDir();
echo $xdg->getRuntimeDir();

print_r($xdg->getDataDirs()); // returns array
print_r($xdg->getConfigDirs()); // returns array
```

## Testing

``` bash
$ phpunit
```

## License

The MIT License (MIT). Please see [License File](https://example.invalid/dnoegel/php-xdg-base-dir/blob/master/LICENSE) for more information.
