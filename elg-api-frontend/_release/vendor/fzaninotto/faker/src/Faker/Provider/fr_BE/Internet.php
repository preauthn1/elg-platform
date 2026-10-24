<?php

namespace Faker\Provider\fr_BE;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'advalvas.be');
    protected static $tld = array('com', 'net', 'org', 'be');
}
