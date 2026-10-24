<?php

declare(strict_types=1);

use App\Enums\WithdrawalTypeEnum;

/**
 * QuickPay 金流商配置
 */
return [
    // 代收配置
    'deposit' => [
        'cash_flow_id' => 87,
        'channels' => [],
    ],

    // 代付配置
    'withdrawal' => [
        'cash_flow_id' => 88,
        'channels' => [
            [
                'currency' => 'VND',
                'type' => WithdrawalTypeEnum::FOREIGN_BANK,
                'banks' => [
                    ['code' => '1548', 'name' => 'Vietnam International Commercial Joint Stock Bank'],
                    ['code' => '1549', 'name' => 'VPBank'],
                    ['code' => '2001', 'name' => 'Joint Stock Commercial Bank for Investment and Development of Vietnam'],
                    ['code' => '2002', 'name' => 'VietinBank'],
                    ['code' => '2003', 'name' => 'Saigon-Hanoi Commercial Joint Stock Bank'],
                    ['code' => '2004', 'name' => 'ABBANK'],
                    ['code' => '2005', 'name' => 'AGRIBANK'],
                    ['code' => '2006', 'name' => 'Vietcombank'],
                    ['code' => '2007', 'name' => 'Techcom'],
                    ['code' => '2008', 'name' => 'Asia Commercial Joint Stock Bank'],
                    ['code' => '2009', 'name' => 'Saigon Commercial Joint Stock Bank'],
                    ['code' => '2011', 'name' => 'MBBANK'],
                    ['code' => '2012', 'name' => 'EIB'],
                    ['code' => '2014', 'name' => 'Bac A Commercial Joint Stock Bank'],
                    ['code' => '2015', 'name' => 'Bao Viet Commercial Joint Stock Bank'],
                    ['code' => '2016', 'name' => 'Kien Long Commercial Joint Stock Bank'],
                    ['code' => '2017', 'name' => 'Maritime Commercial Joint Stock Bank'],
                    ['code' => '2018', 'name' => 'Orient Commercial Joint Stock Bank'],
                    ['code' => '2019', 'name' => 'SouthEast Asia Commercial Joint Stock Bank'],
                    ['code' => '2020', 'name' => 'STB'],
                    ['code' => '2021', 'name' => 'Lien Viet Post Joint Stock Commercial Bank'],
                    ['code' => '2022', 'name' => 'Shinhan Bank'],
                    ['code' => '2031', 'name' => 'DongABank'],
                    ['code' => '2032', 'name' => 'GPBank'],
                    ['code' => '2033', 'name' => 'Saigonbank'],
                    ['code' => '2034', 'name' => 'PG Bank'],
                    ['code' => '2035', 'name' => 'Oceanbank'],
                    ['code' => '2036', 'name' => 'NamABank'],
                    ['code' => '2037', 'name' => 'Tien Phong Commercial Joint Stock Bank'],
                    ['code' => '2038', 'name' => 'Ho Chi Minh City Development Joint Stock Commercial Bank'],
                    ['code' => '2039', 'name' => 'Vietnam Asia Commercial Joint Stock Bank'],
                    ['code' => '2064', 'name' => 'Vikki Digital Bank Limited'],
                    ['code' => '2065', 'name' => 'WOORI'],
                ],
            ],
        ],
    ],
];
