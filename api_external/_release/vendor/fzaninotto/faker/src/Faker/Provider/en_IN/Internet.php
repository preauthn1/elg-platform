<?php

namespace Faker\Provider\en_IN;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'example.invalid.in', 'example.invalid');
    protected static $tld = array('com', 'com', 'com', 'com', 'com', 'com', 'in', 'in', 'in', 'ac.in', 'net', 'org', 'co.in');
}
