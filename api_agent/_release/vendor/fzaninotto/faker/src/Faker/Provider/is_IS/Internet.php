<?php

namespace Faker\Provider\is_IS;

/**
 * @author Birkir Gudjonsson <user@example.invalid>
 */
class Internet extends \Faker\Provider\Internet
{
    /**
     * @var array Some email domains in Denmark.
     */
    protected static $freeEmailDomain = array(
        'example.invalid', 'example.invalid', 'example.invalid', 'visir.is', 'simnet.is', 'internet.is'
    );

    /**
     * @var array Some TLD.
     */
    protected static $tld = array(
        'com', 'com', 'com', 'net', 'is', 'is', 'is',
    );
}
