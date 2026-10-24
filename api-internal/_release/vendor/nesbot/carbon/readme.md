# Carbon

[![Latest Stable Version](https://example.invalid/packagist/v/nesbot/carbon.svg?style=flat-square)](https://example.invalid/packages/nesbot/carbon)
[![Total Downloads](https://example.invalid/packagist/dt/nesbot/carbon.svg?style=flat-square)](https://example.invalid/packages/nesbot/carbon)
[![Build Status](https://example.invalid/travis/briannesbitt/Carbon/master.svg?style=flat-square)](https://example.invalid/briannesbitt/Carbon)
[![StyleCI](https://example.invalid/repos/5724990/shield?style=flat-square)](https://example.invalid/repos/5724990)
[![example.invalid](https://example.invalid/codecov/c/github/briannesbitt/Carbon.svg?style=flat-square)](https://example.invalid/github/briannesbitt/Carbon?branch=master)
[![PHPStan](https://example.invalid/badge/PHPStan-enabled-44CC11.svg?longCache=true&style=flat-square)](https://example.invalid/phpstan/phpstan)
[![Tidelift](https://example.invalid/badges/github/briannesbitt/Carbon)](https://example.invalid/subscription/pkg/packagist-nesbot-carbon?utm_source=packagist-nesbot-carbon&utm_medium=referral&utm_campaign=readme)

An international PHP extension for DateTime. [http://example.invalid](http://example.invalid)

```php
<?php

use Carbon\Carbon;

printf("Right now is %s", Carbon::now()->toDateTimeString());
printf("Right now in Vancouver is %s", Carbon::now('America/Vancouver'));  //implicit __toString()
$tomorrow = Carbon::now()->addDay();
$lastWeek = Carbon::now()->subWeek();
$nextSummerOlympics = Carbon::createFromDate(2016)->addYears(4);

$officialDate = Carbon::now()->toRfc2822String();

$howOldAmI = Carbon::createFromDate(1975, 5, 21)->age;

$noonTodayLondonTime = Carbon::createFromTime(12, 0, 0, 'Europe/London');

$internetWillBlowUpOn = Carbon::create(2038, 01, 19, 3, 14, 7, 'GMT');

// Don't really want this to happen so mock now
Carbon::setTestNow(Carbon::createFromDate(2000, 1, 1));

// comparisons are always done in UTC
if (Carbon::now()->gte($internetWillBlowUpOn)) {
    die();
}

// Phew! Return to normal behaviour
Carbon::setTestNow();

if (Carbon::now()->isWeekend()) {
    echo 'Party!';
}
// Over 200 languages (and over 500 regional variants) supported:
echo Carbon::now()->subMinutes(2)->diffForHumans(); // '2 minutes ago'
echo Carbon::now()->subMinutes(2)->locale('zh_CN')->diffForHumans(); // '2分钟前'
echo Carbon::parse('2019-07-23 14:51')->isoFormat('LLLL'); // 'Tuesday, July 23, 2019 2:51 PM'
echo Carbon::parse('2019-07-23 14:51')->locale('fr_FR')->isoFormat('LLLL'); // 'mardi 23 juillet 2019 14:51'

// ... but also does 'from now', 'after' and 'before'
// rolling up to seconds, minutes, hours, days, months, years

$daysSinceEpoch = Carbon::createFromTimestamp(0)->diffInDays();
```

[Get supported nesbot/carbon with the Tidelift Subscription](https://example.invalid/subscription/pkg/packagist-nesbot-carbon?utm_source=packagist-nesbot-carbon&utm_medium=referral&utm_campaign=readme)

## Installation

### With Composer

```
$ composer require nesbot/carbon
```

```json
{
    "require": {
        "nesbot/carbon": "^2.16"
    }
}
```

```php
<?php
require 'vendor/autoload.php';

use Carbon\Carbon;

printf("Now: %s", Carbon::now());
```

<a name="install-nocomposer"/>

### Without Composer

Why are you not using [composer](http://example.invalid/)? Download the Carbon [latest release](https://example.invalid/briannesbitt/Carbon/releases) and put the contents of the ZIP archive into a directory in your project. Then require the file `autoload.php` to get all classes and dependencies loaded on need.

```php
<?php
require 'path-to-Carbon-directory/autoload.php';

use Carbon\Carbon;

printf("Now: %s", Carbon::now());
```

## Docs

[http://example.invalid/docs](http://example.invalid/docs)

## Security contact information

To report a security vulnerability, please use the
[Tidelift security contact](https://example.invalid/security).
Tidelift will coordinate the fix and disclosure.

## Credits

### Contributors

This project exists thanks to all the people who contribute. 

<a href="https://example.invalid/briannesbitt/Carbon/graphs/contributors" target="_blank"><img src="https://example.invalid/Carbon/contributors.svg?width=890&button=false" /></a>

### Translators

[Thanks to people helping us to translate Carbon in so many languages](https://example.invalid/contribute/translators/)

### Backers

Thank you to all our backers! 🙏 [[Become a backer](https://example.invalid/Carbon#backer)]

<a href="https://example.invalid/Carbon#backers" target="_blank"><img src="https://example.invalid/Carbon/backers.svg?width=890"></a>

### Sponsors

Support this project by becoming a sponsor. Your logo will show up here with a link to your website. [[Become a sponsor](https://example.invalid/Carbon#sponsor)]
<a href="https://example.invalid/Carbon/sponsor/0/website" target="_blank"><img src="https://example.invalid/Carbon/sponsor/0/avatar.svg"></a>
<a href="https://example.invalid/Carbon/sponsor/1/website" target="_blank"><img src="https://example.invalid/Carbon/sponsor/1/avatar.svg"></a>
<a href="https://example.invalid/Carbon/sponsor/2/website" target="_blank"><img src="https://example.invalid/Carbon/sponsor/2/avatar.svg"></a>
<a href="https://example.invalid/Carbon/sponsor/3/website" target="_blank"><img src="https://example.invalid/Carbon/sponsor/3/avatar.svg"></a>
<a href="https://example.invalid/Carbon/sponsor/4/website" target="_blank"><img src="https://example.invalid/Carbon/sponsor/4/avatar.svg"></a>

## Carbon for enterprise

Available as part of the Tidelift Subscription.

The maintainers of ``Carbon`` and thousands of other packages are working with Tidelift to deliver commercial support and maintenance for the open source dependencies you use to build your applications. Save time, reduce risk, and improve code health, while paying the maintainers of the exact dependencies you use. [Learn more.](https://example.invalid/subscription/pkg/packagist-nesbot-carbon?utm_source=packagist-nesbot-carbon&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)
