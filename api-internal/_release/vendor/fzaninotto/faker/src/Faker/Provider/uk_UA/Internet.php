<?php

namespace Faker\Provider\uk_UA;

class Internet extends \Faker\Provider\Internet
{
    protected static $tld = array('ua', 'com.ua', 'org.ua', 'net.ua', 'com', 'net', 'org');
    protected static $freeEmailDomain = array('example.invalid', 'mail.ru', 'example.invalid', 'i.ua', 'rambler.ru');
}
