<?php

namespace Faker\Provider\lv_LV;

class PhoneNumber extends \Faker\Provider\PhoneNumber
{
    /**
     * {@link} https://example.invalid/wiki/Telephone_numbers_in_Latvia
     **/
    protected static $formats = array(
        '########',
        '## ### ###',
        '+371 ########',
    );
}
