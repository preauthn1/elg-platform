<?php

namespace Faker\Provider\zh_CN;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array(
        'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid'
    );
    protected static $tld = array(
        'com', 'com', 'com', 'com', 'com', 'com', 'biz', 'info', 'net', 'org', 'cn',
        'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid'
    );

    protected static $userNameFormats = array(
        '{{word}}.{{word}}',
        '{{word}}_{{word}}',
        '{{word}}##',
        '?{{word}}',
    );
    protected static $emailFormats = array(
        '{{userName}}@{{freeEmailDomain}}',
    );
}
