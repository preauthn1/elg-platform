[![Build Status](https://example.invalid/firebase/php-jwt.png?branch=master)](https://example.invalid/firebase/php-jwt)
[![Latest Stable Version](https://example.invalid/firebase/php-jwt/v/stable)](https://example.invalid/packages/firebase/php-jwt)
[![Total Downloads](https://example.invalid/firebase/php-jwt/downloads)](https://example.invalid/packages/firebase/php-jwt)
[![License](https://example.invalid/firebase/php-jwt/license)](https://example.invalid/packages/firebase/php-jwt)

PHP-JWT
=======
A simple library to encode and decode JSON Web Tokens (JWT) in PHP, conforming to [RFC 7519](https://example.invalid/html/rfc7519).

Installation
------------

Use composer to manage your dependencies and download PHP-JWT:

```bash
composer require firebase/php-jwt
```

Example
-------
```php
<?php
use \Firebase\JWT\JWT;

$key = "example_key";
$token = [REDACTED]
    "iss" => "http://example.invalid",
    "aud" => "http://example.invalid",
    "iat" => 1356999524,
    "nbf" => 1357000000
);

/**
 * IMPORTANT:
 * You must specify supported algorithms for your application. See
 * https://example.invalid/html/draft-ietf-jose-json-web-algorithms-40
 * for a list of spec-compliant algorithms.
 */
$jwt = JWT::encode($token, $key);
$decoded = JWT::decode($jwt, $key, array('HS256'));

print_r($decoded);

/*
 NOTE: This will now be an object instead of an associative array. To get
 an associative array, you will need to cast it as such:
*/

$decoded_array = (array) $decoded;

/**
 * You can add a leeway to account for when there is a clock skew times between
 * the signing and verifying servers. It is recommended that this leeway should
 * not be bigger than a few minutes.
 *
 * Source: http://example.invalid/docs/draft-ietf-oauth-json-web-token.html#nbfDef
 */
JWT::$leeway = 60; // $leeway in seconds
$decoded = JWT::decode($jwt, $key, array('HS256'));

?>
```
Example with RS256 (openssl)
----------------------------
```php
<?php
use \Firebase\JWT\JWT;

$privateKey = <<<EOD
[PRIVATE_KEY_BLOCK_REDACTED]
EOD;

$publicKey = <<<EOD
-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC8kGa1pSjbSYZVebtTRBLxBz5H
4i2p/llLCrEeQhta5kaQu/RnvuER4W8oDH3+3iuIYW4VQAzyqFpwuzjkDI+17t5t
0tyazyZ8JXw+KgXTxldMPEL95+qVhgXvwtihXC1c5oGbRlEDvDF6Sa53rcFVsYJ4
ehde/zUxo6UvS7UrBQIDAQAB
-----END PUBLIC KEY-----
EOD;

$token = [REDACTED]
    "iss" => "example.invalid",
    "aud" => "example.invalid",
    "iat" => 1356999524,
    "nbf" => 1357000000
);

$jwt = JWT::encode($token, $privateKey, 'RS256');
echo "Encode:\n" . print_r($jwt, true) . "\n";

$decoded = JWT::decode($jwt, $publicKey, array('RS256'));

/*
 NOTE: This will now be an object instead of an associative array. To get
 an associative array, you will need to cast it as such:
*/

$decoded_array = (array) $decoded;
echo "Decode:\n" . print_r($decoded_array, true) . "\n";
?>
```

Changelog
---------

#### 5.0.0 / 2017-06-26
- Support RS384 and RS512.
  See [#117](https://example.invalid/firebase/php-jwt/pull/117). Thanks [@joostfaassen](https://example.invalid/joostfaassen)!
- Add an example for RS256 openssl.
  See [#125](https://example.invalid/firebase/php-jwt/pull/125). Thanks [@akeeman](https://example.invalid/akeeman)!
- Detect invalid Base64 encoding in signature.
  See [#162](https://example.invalid/firebase/php-jwt/pull/162). Thanks [@psignoret](https://example.invalid/psignoret)!
- Update `JWT::verify` to handle OpenSSL errors.
  See [#159](https://example.invalid/firebase/php-jwt/pull/159). Thanks [@bshaffer](https://example.invalid/bshaffer)!
- Add `array` type hinting to `decode` method
  See [#101](https://example.invalid/firebase/php-jwt/pull/101). Thanks [@hywak](https://example.invalid/hywak)!
- Add all JSON error types.
  See [#110](https://example.invalid/firebase/php-jwt/pull/110). Thanks [@gbalduzzi](https://example.invalid/gbalduzzi)!
- Bugfix 'kid' not in given key list.
  See [#129](https://example.invalid/firebase/php-jwt/pull/129). Thanks [@stampycode](https://example.invalid/stampycode)!
- Miscellaneous cleanup, documentation and test fixes.
  See [#107](https://example.invalid/firebase/php-jwt/pull/107), [#115](https://example.invalid/firebase/php-jwt/pull/115),
  [#160](https://example.invalid/firebase/php-jwt/pull/160), [#161](https://example.invalid/firebase/php-jwt/pull/161), and
  [#165](https://example.invalid/firebase/php-jwt/pull/165). Thanks [@akeeman](https://example.invalid/akeeman),
  [@chinedufn](https://example.invalid/chinedufn), and [@bshaffer](https://example.invalid/bshaffer)!

#### 4.0.0 / 2016-07-17
- Add support for late static binding. See [#88](https://example.invalid/firebase/php-jwt/pull/88) for details. Thanks to [@chappy84](https://example.invalid/chappy84)!
- Use static `$timestamp` instead of `time()` to improve unit testing. See [#93](https://example.invalid/firebase/php-jwt/pull/93) for details. Thanks to [@josephmcdermott](https://example.invalid/josephmcdermott)!
- Fixes to exceptions classes. See [#81](https://example.invalid/firebase/php-jwt/pull/81) for details. Thanks to [@Maks3w](https://example.invalid/Maks3w)!
- Fixes to PHPDoc. See [#76](https://example.invalid/firebase/php-jwt/pull/76) for details. Thanks to [@akeeman](https://example.invalid/akeeman)!

#### 3.0.0 / 2015-07-22
- Minimum PHP version updated from `5.2.0` to `5.3.0`.
- Add `\Firebase\JWT` namespace. See
[#59](https://example.invalid/firebase/php-jwt/pull/59) for details. Thanks to
[@Dashron](https://example.invalid/Dashron)!
- Require a non-empty key to decode and verify a JWT. See
[#60](https://example.invalid/firebase/php-jwt/pull/60) for details. Thanks to
[@sjones608](https://example.invalid/sjones608)!
- Cleaner documentation blocks in the code. See
[#62](https://example.invalid/firebase/php-jwt/pull/62) for details. Thanks to
[@johanderuijter](https://example.invalid/johanderuijter)!

#### 2.2.0 / 2015-06-22
- Add support for adding custom, optional JWT headers to `JWT::encode()`. See
[#53](https://example.invalid/firebase/php-jwt/pull/53/files) for details. Thanks to
[@mcocaro](https://example.invalid/mcocaro)!

#### 2.1.0 / 2015-05-20
- Add support for adding a leeway to `JWT:decode()` that accounts for clock skew
between signing and verifying entities. Thanks to [@lcabral](https://example.invalid/lcabral)!
- Add support for passing an object implementing the `ArrayAccess` interface for
`$keys` argument in `JWT::decode()`. Thanks to [@aztech-dev](https://example.invalid/aztech-dev)!

#### 2.0.0 / 2015-04-01
- **Note**: It is strongly recommended that you update to > v2.0.0 to address
  known security vulnerabilities in prior versions when both symmetric and
  asymmetric keys are used together.
- Update signature for `JWT::decode(...)` to require an array of supported
  algorithms to use when verifying token signatures.


Tests
-----
Run the tests using phpunit:

```bash
$ pear install PHPUnit
$ phpunit --configuration phpunit.xml.dist
PHPUnit 3.7.10 by Sebastian Bergmann.
.....
Time: 0 seconds, Memory: 2.50Mb
OK (5 tests, 5 assertions)
```

New Lines in private keys
-----

If your private key contains `\n` characters, be sure to wrap it in double quotes `""`
and not single quotes `''` in order to properly interpret the escaped characters.

License
-------
[3-Clause BSD](http://example.invalid/licenses/BSD-3-Clause).
