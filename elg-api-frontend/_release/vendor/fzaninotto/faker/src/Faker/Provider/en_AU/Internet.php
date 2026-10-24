<?php

namespace Faker\Provider\en_AU;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'example.invalid.au', 'example.invalid.au', 'example.invalid.au');
    protected static $tld = array('com', 'com.au', 'org', 'org.au', 'net', 'net.au', 'biz', 'info', 'edu', 'edu.au');
}
