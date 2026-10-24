<?php

namespace Faker\Provider\tr_TR;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'example.invalid.tr', 'example.invalid', 'example.invalid', 'example.invalid');
    protected static $tld = array('com', 'com', 'com', 'com', 'com.tr', 'com.tr', 'info', 'net', 'org', 'org.tr','edu', 'edu.tr', 'edu.tr');
}
