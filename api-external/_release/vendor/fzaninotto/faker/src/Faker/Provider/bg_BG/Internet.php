<?php

namespace Faker\Provider\bg_BG;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'mail.bg', 'abv.bg', 'dir.bg');
    protected static $tld = array('bg', 'bg', 'bg', 'bg', 'bg', 'bg', 'com', 'biz', 'info', 'net', 'org');
}
