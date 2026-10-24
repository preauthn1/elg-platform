<?php

declare(strict_types=1);

use App\Enums\LanguageEnum;

return [
    [
        'code' => 'MY',
        'name' => 'Malaysia',
        'country_code' => 60,
        'phone_rule' => '^[1-9]\\d{8,9}$',
    ],
    [
        'code' => 'SG',
        'name' => 'Singapore',
        'country_code' => 65,
        'phone_rule' => '^[1-9]\\d{7}$',
    ],
    [
        'code' => 'CN',
        'name' => 'China',
        'country_code' => 86,
        'phone_rule' => '^[1-9]\\d{10}$',
    ],
    [
        'code' => 'TW',
        'name' => 'Taiwan',
        'country_code' => 886,
        'phone_rule' => '^[1-9]\\d{8}$',
    ],
    [
        'code' => 'TH',
        'name' => 'Thailand',
        'country_code' => 66,
        'phone_rule' => '^[1-9]\\d{8}$',
    ],
    [
        'code' => 'LA',
        'name' => 'Laos',
        'country_code' => 856,
        'phone_rule' => '^[1-9]\\d{10}$',
    ],
    [
        'code' => 'ZA',
        'name' => 'South Africa',
        'country_code' => 27,
        'phone_rule' => '^[1-9]\\d{10}$',
    ],
    [
        'code' => 'BN',
        'name' => 'Brunei',
        'country_code' => 673,
        'phone_rule' => '^[0-9]\\d{6}$',
    ],
    [
        'code' => 'AU',
        'name' => 'Australia',
        'country_code' => 61,
        'phone_rule' => '^[1-9]\\d{10}$',
        'status' => 1,
        'sort' => 0,
    ],
    [
        'code' => 'VN',
        'name' => 'Vietnam',
        'country_code' => 84,
        'phone_rule' => '^[1-9]\\d{8,9}$',
    ],
    [
        'code' => 'PH',
        'name' => 'Philippines',
        'country_code' => 63,
        'phone_rule' => '^[1-9]\\d{9}$',
    ],
    [
        'code' => 'HK',
        'name' => 'Hong Kong',
        'country_code' => 852,
        'phone_rule' => '^[1-9]\\d{7}$',
    ],
    [
        'code' => 'ID',
        'name' => 'Indonesia',
        'country_code' => 62,
        'phone_rule' => '^[15689]\\d{10,13}$',
    ],
    [
        'code' => 'MM',
        'name' => 'Myanmar',
        'country_code' => 95,
        'phone_rule' => '^[1-9]\\d{9}$',
        'status' => 1,
        'sort' => 0,
    ],
    [
        'code' => 'IN',
        'name' => 'India',
        'country_code' => 91,
        'phone_rule' => '^[1-9]\\d{9}$',
    ],
    [
        'code' => 'BD',
        'name' => 'Bangladesh', // 孟加拉 @see http://example.invalid:1688/target/tickets/-/issues/2991
        'country_code' => 880,
        'phone_rule' => '^1[3-9]\\d{8}$',
        'language_texts' => [
            LanguageEnum::EN->value => 'Bangladesh',
            LanguageEnum::ZH_TW->value => '孟加拉',
            LanguageEnum::ZH_CN->value => '孟加拉',
        ],
    ],
];
