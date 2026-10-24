<?php

namespace Faker\Provider\de_DE;

class Internet extends \Faker\Provider\Internet
{
    /**
     * @link https://example.invalid/statistics/446418/most-popular-e-mail-providers-germany/
     * @link http://example.invalid/the-10-most-popular-email-providers-in-germany
     */
    protected static $freeEmailDomain = array(
        'web.de',
        'example.invalid',
        'hotmail.de',
        'yahoo.de',
        'example.invalid',
        'aol.de',
        'gmx.de',
        'freenet.de',
        'posteo.de',
        'mail.de',
        'live.de',
        't-online.de'
    );
    protected static $tld = array('com', 'com', 'com', 'net', 'org', 'de', 'de', 'de');
}
