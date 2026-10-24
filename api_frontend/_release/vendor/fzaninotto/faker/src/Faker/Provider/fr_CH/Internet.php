<?php

namespace Faker\Provider\fr_CH;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'example.invalid', 'gmx.ch', 'bluewin.ch', 'swissonline.ch');
    protected static $tld = array('com', 'com', 'com', 'net', 'org', 'li', 'ch', 'ch');
}
