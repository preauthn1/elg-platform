<?php

namespace Faker\Provider\da_DK;

/**
 * @author Antoine Corcy <user@example.invalid>
 */
class Internet extends \Faker\Provider\Internet
{
    /**
     * @var array Some safe email TLD.
     */
    protected static $safeEmailTld = array(
        'org', 'com', 'net', 'dk', 'dk', 'dk',
    );

    /**
     * @var array Some email domains in Denmark.
     */
    protected static $freeEmailDomain = array(
        'example.invalid', 'example.invalid', 'yahoo.dk', 'example.invalid', 'hotmail.dk', 'mail.dk', 'live.dk'
    );

    /**
     * @var array Some TLD.
     */
    protected static $tld = array(
        'com', 'com', 'com', 'biz', 'info', 'net', 'org', 'dk', 'dk', 'dk',
    );
}
