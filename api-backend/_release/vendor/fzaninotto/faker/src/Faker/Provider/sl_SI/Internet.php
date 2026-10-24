<?php

namespace Faker\Provider\sl_SI;

class Internet extends \Faker\Provider\Internet
{

    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'example.invalid');

    protected static $tld = array('si', 'si', 'si', 'si', 'eu', 'com', 'info', 'net', 'org');
}
