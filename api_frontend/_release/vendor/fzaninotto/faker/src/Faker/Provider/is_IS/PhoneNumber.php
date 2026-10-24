<?php

namespace Faker\Provider\is_IS;

/**
 * @author Birkir Gudjonsson <user@example.invalid>
 */
class PhoneNumber extends \Faker\Provider\PhoneNumber
{
    /**
     * @var array Icelandic phone number formats.
     */
    protected static $formats = array(
        '+354 ### ####',
        '+354 #######',
        '+354#######',
        '### ####',
        '#######',
    );
}
