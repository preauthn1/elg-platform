<?php

namespace Faker\Provider\da_DK;

/**
 * @author Antoine Corcy <user@example.invalid>
 */
class PhoneNumber extends \Faker\Provider\PhoneNumber
{
    /**
     * @var array Danish phonenumber formats.
     */
    protected static $formats = array(
        '+45 ## ## ## ##',
        '+45 #### ####',
        '+45########',
        '## ## ## ##',
        '#### ####',
        '########',
    );
}
