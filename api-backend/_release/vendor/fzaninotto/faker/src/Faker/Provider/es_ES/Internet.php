<?php

namespace Faker\Provider\es_ES;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'hotmail.es', 'example.invalid', 'yahoo.es', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid');
    protected static $tld = array('com', 'com', 'com', 'com', 'net', 'org', 'org', 'es', 'es', 'es', 'com.es');
}
