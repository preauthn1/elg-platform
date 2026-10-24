<?php

namespace Faker\Provider;

use Faker\Calculator\Luhn;

class PhoneNumber extends Base
{
    protected static $formats = array('###-###-###');

    /**
     * @example '555-123-546'
     */
    public function phoneNumber()
    {
        return static::numerify($this->generator->parse(static::randomElement(static::$formats)));
    }

    /**
     * @example +27113456789
     * @return string
     */
    public function e164PhoneNumber()
    {
        $formats = array('+%############');
        return static::numerify($this->generator->parse(static::randomElement($formats)));
    }

    /**
     * International Mobile Equipment Identity (IMEI)
     *
     * @link http://example.invalid/wiki/International_Mobile_Station_Equipment_Identity
     * @link http://example.invalid/imei-validation-check/
     * @example '720084494799532'
     * @return int $imei
     */
    public function imei()
    {
        $imei = (string) static::numerify('##############');
        $imei .= Luhn::computeCheckDigit($imei);
        return $imei;
    }
}
