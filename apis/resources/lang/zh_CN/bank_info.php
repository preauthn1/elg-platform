<?php

declare(strict_types=1);

return [
    // display 对应前端字段名称（用于显示）
    'display' => [
        'country' => '国家',
        'mobile' => '手机号码',
        'ifsc' => 'IFSC',
        'amount' => '金额',
    ],

    // placeholder 对应输入提示文字
    'placeholder' => [
        'country' => '请选择国家',
        'mobile' => '请输入手机号码',
        'ifsc' => '请输入 IFSC 编码',
        'amount' => '请输入金额',
    ],

    // country 的选项（以 provider 的 value 为 key）
    'country' => [
        'options' => [
            '91' => '印度',
            '1' => '美国',
            '44' => '英国',
            '86' => '中国',
        ],
    ],
];
