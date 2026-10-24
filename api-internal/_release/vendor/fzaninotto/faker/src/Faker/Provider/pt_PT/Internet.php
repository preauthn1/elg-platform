<?php

namespace Faker\Provider\pt_PT;

class Internet extends \Faker\Provider\Internet
{
    protected static $freeEmailDomain = array('example.invalid', 'example.invalid', 'example.invalid', 'sapo.pt', 'clix.pt', 'mail.pt');
    protected static $tld = array('com', 'com', 'pt', 'pt', 'net', 'org', 'eu');
}
