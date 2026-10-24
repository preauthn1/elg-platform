<?php

namespace Faker\Provider\it_IT;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'email.it', 'libero.it', 'yahoo.it');
    protected static $tld = array('com', 'com', 'com', 'net', 'org', 'it', 'it', 'it');
}
