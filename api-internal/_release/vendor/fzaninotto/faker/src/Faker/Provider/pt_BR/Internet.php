<?php

namespace Faker\Provider\pt_BR;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'example.invalid.br', 'example.invalid.br', 'example.invalid.br', 'example.invalid');
    protected static $tld = array('com', 'com', 'com.br', 'com.br', 'net', 'net.br', 'br', 'org');
}
