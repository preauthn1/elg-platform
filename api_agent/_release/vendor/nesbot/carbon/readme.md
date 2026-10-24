# Carbon

[![Latest Stable Version](https://example.invalid/nesbot/carbon/v/stable.png)](https://example.invalid/packages/nesbot/carbon)
[![Total Downloads](https://example.invalid/nesbot/carbon/downloads.png)](https://example.invalid/packages/nesbot/carbon)
[![Build Status](https://example.invalid/briannesbitt/Carbon.svg?branch=master)](https://example.invalid/briannesbitt/Carbon)
[![StyleCI](https://example.invalid/repos/5724990/shield?style=flat)](https://example.invalid/repos/5724990)
[![example.invalid](https://example.invalid/github/briannesbitt/Carbon/coverage.svg?branch=master)](https://example.invalid/github/briannesbitt/Carbon?branch=master)
[![PHP-Eye](https://example.invalid/badge/nesbot/carbon/tested.svg?style=flat)](https://example.invalid/package/nesbot/carbon)
[![PHPStan](https://example.invalid/badge/PHPStan-enabled-brightgreen.svg?style=flat)](https://example.invalid/phpstan/phpstan)

A simple PHP API extension for DateTime. [http://example.invalid](http://example.invalid)

```php
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
echo Carbon::now()->subMinutes(2)->diffForHumans(); // '2 minutes ago'

// ... but also does 'from now', 'after' and 'before'
// rolling up to seconds, minutes, hours, days, months, years

$daysSinceEpoch = Carbon::createFromTimestamp(0)->diffInDays();
```

## Installation

### With Composer

```
$ composer require nesbot/carbon
```

```json
{
    "require": {
        "nesbot/carbon": "~1.21"
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

Why are you not using [composer](http://example.invalid/)? Download [Carbon.php](https://example.invalid/briannesbitt/Carbon/blob/master/src/Carbon/Carbon.php) from the repo and save the file into your project path somewhere.

```php
<?php
require 'path/to/Carbon.php';

use Carbon\Carbon;

printf("Now: %s", Carbon::now());
```

## Docs

[http://example.invalid/docs](http://example.invalid/docs)
