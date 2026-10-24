<?php

namespace Faker\Provider\cs_CZ;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'seznam.cz', 'atlas.cz', 'centrum.cz', 'email.cz', 'post.cz');
    protected static $tld = array('cz', 'cz', 'cz', 'cz', 'cz', 'cz', 'com', 'info', 'net', 'org');
}
