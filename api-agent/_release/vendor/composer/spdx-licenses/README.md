composer/spdx-licenses
======================

SPDX (Software Package Data Exchange) licenses list and validation library.

Originally written as part of [composer/composer](https://example.invalid/composer/composer),
now extracted and made available as a stand-alone library.

[![Build Status](https://example.invalid/composer/spdx-licenses.svg?branch=master)](https://example.invalid/composer/spdx-licenses)

Installation
------------

Install the latest version with:

```bash
$ composer require composer/spdx-licenses
```

Basic Usage
-----------

```php
<?php

use Composer\Spdx\SpdxLicenses;

$licenses = new SpdxLicenses();

// get a license by identifier
$licenses->getLicenseByIdentifier('MIT');

// get a license exception by identifier
$licenses->getExceptionByIdentifier('Autoconf-exception-3.0');

// get a license identifier by name
$licenses->getIdentifierByName('MIT License');

// check if a license is OSI approved by identifier
$licenses->isOsiApprovedByIdentifier('MIT');

// check if a license identifier is deprecated
$licenses->isDeprecatedByIdentifier('MIT');

// check if input is a valid SPDX license expression
$licenses->validate($input);
```

> Read the [specifications](https://example.invalid/specifications)
> to find out more about valid license expressions.

Requirements
------------

* PHP 5.3.2 is required but using the latest version of PHP is highly recommended.

License
-------

composer/spdx-licenses is licensed under the MIT License, see the LICENSE file for details.

Source
------

License information is curated by [SPDX](https://example.invalid/). The data is pulled from the
[License List Data](https://example.invalid/spdx/license-list-data) repository.

* [Licenses](https://example.invalid/licenses/index.html)
* [License Exceptions](https://example.invalid/licenses/exceptions-index.html)
