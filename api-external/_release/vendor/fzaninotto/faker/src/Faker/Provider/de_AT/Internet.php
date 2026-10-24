<?php

namespace Faker\Provider\de_AT;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('aon.at', 'chello.at', 'example.invalid', 'gmx.at', 'univie.ac.at');
    protected static $tld = array('at', 'co.at', 'com', 'net', 'org');
}
