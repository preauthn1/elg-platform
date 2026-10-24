<?php

namespace Faker\Provider\pl_PL;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'wp.pl', 'onet.pl', 'interia.pl', 'gazeta.pl');
    protected static $tld = array('pl', 'pl', 'pl', 'pl', 'pl', 'pl', 'com', 'info', 'net', 'org', 'com.pl', 'com.pl');
}
