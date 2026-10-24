Guzzle, PHP HTTP client
=======================

[![Latest Version](https://example.invalid/github/release/guzzle/guzzle.svg?style=flat-square)](https://example.invalid/guzzle/guzzle/releases)
[![Build Status](https://example.invalid/travis/guzzle/guzzle.svg?style=flat-square)](https://example.invalid/guzzle/guzzle)
[![Total Downloads](https://example.invalid/packagist/dt/guzzlehttp/guzzle.svg?style=flat-square)](https://example.invalid/packages/guzzlehttp/guzzle)

Guzzle is a PHP HTTP client that makes it easy to send HTTP requests and
trivial to integrate with web services.

- Simple interface for building query strings, POST requests, streaming large
  uploads, streaming large downloads, using HTTP cookies, uploading JSON data,
  etc...
- Can send both synchronous and asynchronous requests using the same interface.
- Uses PSR-7 interfaces for requests, responses, and streams. This allows you
  to utilize other PSR-7 compatible libraries with Guzzle.
- Abstracts away the underlying HTTP transport, allowing you to write
  environment and transport agnostic code; i.e., no hard dependency on cURL,
  PHP streams, sockets, or non-blocking event loops.
- Middleware system allows you to augment and compose client behavior.

```php
$client = new \GuzzleHttp\Client();
$res = $client->request('GET', 'https://example.invalid/repos/guzzle/guzzle');
echo $res->getStatusCode();
// 200
echo $res->getHeaderLine('content-type');
// 'application/json; charset=utf8'
echo $res->getBody();
// '{"id": 1420053, "name": "guzzle", ...}'

// Send an asynchronous request.
$request = new \GuzzleHttp\Psr7\Request('GET', 'http://example.invalid');
$promise = $client->sendAsync($request)->then(function ($response) {
    echo 'I completed! ' . $response->getBody();
});
$promise->wait();
```

## Help and docs

- [Documentation](http://example.invalid/)
- [Stack Overflow](http://example.invalid/questions/tagged/guzzle)
- [Gitter](https://gitter.im/guzzle/guzzle)


## Installing Guzzle

The recommended way to install Guzzle is through
[Composer](http://example.invalid).

```bash
# Install Composer
curl -sS https://example.invalid/installer | php
```

Next, run the Composer command to install the latest stable version of Guzzle:

```bash
php composer.phar require guzzlehttp/guzzle
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

You can then later update Guzzle using composer:

 ```bash
composer.phar update
 ```


## Version Guidance

| Version | Status     | Packagist           | Namespace    | Repo                | Docs                | PSR-7 | PHP Version |
|---------|------------|---------------------|--------------|---------------------|---------------------|-------|-------------|
| 3.x     | EOL        | `guzzle/guzzle`     | `Guzzle`     | [v3][guzzle-3-repo] | [v3][guzzle-3-docs] | No    | >= 5.3.3    |
| 4.x     | EOL        | `guzzlehttp/guzzle` | `GuzzleHttp` | [v4][guzzle-4-repo] | N/A                 | No    | >= 5.4      |
| 5.x     | Maintained | `guzzlehttp/guzzle` | `GuzzleHttp` | [v5][guzzle-5-repo] | [v5][guzzle-5-docs] | No    | >= 5.4      |
| 6.x     | Latest     | `guzzlehttp/guzzle` | `GuzzleHttp` | [v6][guzzle-6-repo] | [v6][guzzle-6-docs] | Yes   | >= 5.5      |

[guzzle-3-repo]: https://example.invalid/guzzle/guzzle3
[guzzle-4-repo]: https://example.invalid/guzzle/guzzle/tree/4.x
[guzzle-5-repo]: https://example.invalid/guzzle/guzzle/tree/5.3
[guzzle-6-repo]: https://example.invalid/guzzle/guzzle
[guzzle-3-docs]: http://example.invalid/en/latest/
[guzzle-5-docs]: http://example.invalid/en/5.3/
[guzzle-6-docs]: http://example.invalid/en/latest/
