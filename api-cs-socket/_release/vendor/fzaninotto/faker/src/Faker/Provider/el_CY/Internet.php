<?php

namespace Faker\Provider\el_CY;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'example.invalid.cy', 'example.invalid.cy', 'example.invalid');
    protected static $tld = array('com.cy', 'com.cy', 'com.cy', 'com.cy', 'com.cy', 'com.cy', 'biz', 'info', 'net', 'org');
}
