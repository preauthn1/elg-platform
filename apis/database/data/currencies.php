<?php

declare(strict_types=1);

// ⚠️ 本檔勿用 `php artisan generator:currencies` 重產：該命令整檔覆寫，會把下方關於 id 22／23／24 的說明洗掉。
//    手動增列即可。id 是明寫的，兩支 seeder 寫入前都會經 GuardsCurrencyMasterData 比對主檔，
//    id 或 code 與目標庫現況衝突就拋例外，不會靜默略過。
return [
    [
        'id' => 1,
        'code' => 'MYR',
        'name' => 'Malaysia Dollar',
        'rate' => '1.00000',
    ],
    [
        'id' => 2,
        'code' => 'SGD',
        'name' => 'Singapore Dollar',
        'rate' => '1.00000',
    ],
    [
        'id' => 3,
        'code' => 'VND',
        'name' => 'VND',
        'rate' => '1.00000',
    ],
    [
        'id' => 4,
        'code' => 'VND2',
        'name' => 'VND2',
        'rate' => '1.00000',
    ],
    [
        'id' => 5,
        'code' => 'KVND',
        'name' => 'KVND',
        'rate' => '1.00000',
    ],
    [
        'id' => 6,
        'code' => 'IDR',
        'name' => 'IDR',
        'rate' => '1.00000',
    ],
    [
        'id' => 7,
        'code' => 'IDR2',
        'name' => 'IDR2',
        'rate' => '1.00000',
    ],
    [
        'id' => 8,
        'code' => 'KIDR',
        'name' => 'KIDR',
        'rate' => '1.00000',
    ],
    [
        'id' => 9,
        'code' => 'THB',
        'name' => 'THB',
        'rate' => '1.00000',
    ],
    [
        'id' => 10,
        'code' => 'USDT',
        'name' => 'USDT',
        'rate' => '1.00000',
    ],
    [
        'id' => 11,
        'code' => 'FON',
        'name' => 'FON',
        'rate' => '1.00000',
    ],
    [
        'id' => 12,
        'code' => 'GCASH',
        'name' => 'GCASH',
        'rate' => '1.00000',
    ],
    [
        'id' => 13,
        'code' => 'BUSD',
        'name' => 'BUSD',
        'rate' => '1.00000',
    ],
    [
        'id' => 14,
        'code' => 'MMK',
        'name' => 'MMK',
        'rate' => '1.00000',
    ],
    [
        'id' => 15,
        'code' => 'PayMaya',
        'name' => 'PayMaya',
        'rate' => '1.00000',
    ],
    [
        'id' => 16,
        'code' => 'HKD',
        'name' => 'HKD',
        'rate' => '1.00000',
    ],
    [
        'id' => 17,
        'code' => 'PGK',
        'name' => 'PGK',
        'rate' => '1.00000',
    ],
    [
        'id' => 18,
        'code' => 'AUD',
        'name' => 'AUD',
        'rate' => '1.00000',
    ],
    [
        'id' => 19,
        'code' => 'BDT',
        'name' => 'BDT',
        'rate' => '1.00000',
    ],
    [
        'id' => 20,
        'code' => 'TON',
        'name' => 'TON',
        'rate' => '1.00000',
    ],
    [
        'id' => 21,
        'code' => 'INR',
        'name' => 'INR',
        'rate' => '1.00000',
    ],
    // 2026-09-07 多幣別錢包第二期 2.1 補入：VWorld 實測 vendor_infos.currency 有啟用中商戶
    // （PHP 16／USD 14／CNY 1）但主檔查無，currency_id 因此填不出來。id 接續 22／23／24，
    // 六站 id→code 一致是本案依賴的事實，不可重用停用 id、不可讓各站分配到不同 id。
    // rate 一律 1.00000：currencies.rate 六站全為 1 且未被使用，真正生效的是 vendor_infos.rate。
    [
        'id' => 22,
        'code' => 'PHP',
        'name' => 'Philippine Peso',
        'rate' => '1.00000',
    ],
    [
        'id' => 23,
        'code' => 'USD',
        'name' => 'US Dollar',
        'rate' => '1.00000',
    ],
    [
        'id' => 24,
        'code' => 'CNY',
        'name' => 'Chinese Yuan',
        'rate' => '1.00000',
    ],
];
