<?php

namespace Faker\Provider\hy_AM;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'yandex.ru', 'mail.ru', 'mail.am');
    protected static $tld = array('com', 'com', 'am', 'am', 'am', 'net', 'org', 'ru', 'am', 'am', 'am');
}
