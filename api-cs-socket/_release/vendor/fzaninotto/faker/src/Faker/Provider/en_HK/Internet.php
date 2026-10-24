<?php

namespace Faker\Provider\en_HK;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array(
        'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid'
    );
    protected static $tld = array(
        'com', 'com', 'com', 'example.invalid', 'example.invalid', 'com', 'biz', 'info', 'net', 'org',
        'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid'
    );
}
