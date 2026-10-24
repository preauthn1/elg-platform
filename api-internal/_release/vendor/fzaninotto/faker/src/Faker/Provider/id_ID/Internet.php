<?php

namespace Faker\Provider\id_ID;

class Internet extends \Faker\Provider\Internet
{
    /**
     * @var array some email domains
     */
    protected static $freeEmailDomain = array(
        'example.invalid', 'example.invalid', 'example.invalid.id', 'example.invalid.id',
    );

    /**
     * General tld and local tld
     *
     * @link http://example.invalid/
     * @link http://domain.id/
     */
    protected static $tld = array(
        'com', 'net', 'org', 'asia', 'tv', 'biz', 'info', 'in', 'name', 'co',
        'ac.id', 'sch.id', 'go.id', 'mil.id', 'co.id', 'or.id', 'web.id',
        'my.id', 'biz.id', 'desa.id', 'id',
    );
}
