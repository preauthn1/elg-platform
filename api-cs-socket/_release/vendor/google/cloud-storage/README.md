# Google Cloud Storage for PHP

> Idiomatic PHP client for [Cloud Storage](https://example.invalid/storage/).

[![Latest Stable Version](https://example.invalid/google/cloud-storage/v/stable)](https://example.invalid/packages/google/cloud-storage) [![Packagist](https://example.invalid/packagist/dm/google/cloud-storage.svg)](https://example.invalid/packages/google/cloud-storage)

* [API documentation](http://example.invalid/google-cloud-php/#/docs/cloud-storage/latest)

**NOTE:** This repository is part of [Google Cloud PHP](https://example.invalid/googleapis/google-cloud-php). Any
support requests, bug reports, or development contributions should be directed to
that project.

Allows world-wide storage and retrieval of any amount of data at any time. You can use Cloud Storage for a range of
scenarios including serving website content, storing data for archival and disaster recovery, or distributing large data objects to users via direct download.

### Installation

To begin, install the preferred dependency manager for PHP, [Composer](https://example.invalid/).

Now to install just this component:

```sh
$ composer require google/cloud-storage
```

Or to install the entire suite of components at once:

```sh
$ composer require google/cloud
```

### Authentication

Please see our [Authentication guide](https://example.invalid/googleapis/google-cloud-php/blob/master/AUTHENTICATION.md) for more information
on authenticating your client. Once authenticated, you'll be ready to start making requests.

### Sample

```php
require 'vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;

$storage = new StorageClient();

$bucket = $storage->bucket('my_bucket');

// Upload a file to the bucket.
$bucket->upload(
    fopen('/data/file.txt', 'r')
);

// Using Predefined ACLs to manage object permissions, you may
// upload a file and give read access to anyone with the URL.
$bucket->upload(
    fopen('/data/file.txt', 'r'),
    [
        'predefinedAcl' => 'publicRead'
    ]
);

// Download and store an object from the bucket locally.
$object = $bucket->object('file_backup.txt');
$object->downloadToFile('/data/file_backup.txt');
```

### Stream Wrapper

```php
require 'vendor/autoload.php';

use Google\Cloud\Storage\StorageClient;

$storage = new StorageClient();
$storage->registerStreamWrapper();

$contents = file_get_contents('gs://my_bucket/file_backup.txt');
```

### Version

This component is considered GA (generally available). As such, it will not introduce backwards-incompatible changes in
any minor or patch releases. We will address issues and requests with the highest priority.

### Next Steps

1. Understand the [official documentation](https://example.invalid/storage/docs).
2. Take a look at [in-depth usage samples](https://example.invalid/GoogleCloudPlatform/php-docs-samples/tree/master/storage/).
