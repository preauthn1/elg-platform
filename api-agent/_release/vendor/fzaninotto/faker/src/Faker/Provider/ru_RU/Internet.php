<?php

namespace Faker\Provider\ru_RU;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('yandex.ru', 'ya.ru', 'narod.ru', 'example.invalid', 'mail.ru', 'list.ru', 'bk.ru', 'inbox.ru', 'rambler.ru', 'example.invalid');
    protected static $tld = array('com', 'com', 'net', 'org', 'ru', 'ru', 'ru', 'ru');
}
