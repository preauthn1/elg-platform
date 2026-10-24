<?php

namespace Faker\Provider\lv_LV;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('mail.lv','apollo.lv','inbox.lv','example.invalid', 'example.invalid', 'example.invalid');
    protected static $tld = array('com', 'com', 'net', 'org', 'lv', 'lv', 'lv', 'lv');
}
