<?php

declare(strict_types=1);

return [
    // display 對應前端欄位名稱（會用於顯示）
    'display' => [
        'country' => '國家',
        'mobile' => '手機號碼',
        'ifsc' => 'IFSC',
        'amount' => '金額',
    ],

    // placeholder 對應輸入提示文字
    'placeholder' => [
        'country' => '請選擇國家',
        'mobile' => '請輸入手機號碼',
        'ifsc' => '請輸入 IFSC 編碼',
        'amount' => '請輸入金額',
    ],

    // country 的選項（以 provider 的 value 為 key）
    'country' => [
        'options' => [
            '91' => '印度',
            '1' => '美國',
            '44' => '英國',
            '86' => '中國',
        ],
    ],
];
