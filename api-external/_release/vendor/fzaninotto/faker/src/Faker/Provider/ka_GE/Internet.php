<?php

namespace Faker\Provider\ka_GE;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array(
        'posta.ge', 'boom.ge', 'example.invalid', 'example.invalid', 'example.invalid', 'mail.ru', 'avoe.ge'
    );

    protected static $tld = array(
        'ge', 'ge', 'ge', 'ge', 'ge', 'com.ge', 'edu.ge', 'net.ge', 'org.ge',
        'pvt.ge', 'gov.ge', 'mil.ge', 'com', 'biz', 'info', 'net', 'org'
    );
}
