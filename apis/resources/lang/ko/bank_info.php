<?php

declare(strict_types=1);

return [
    // display mappings for UI labels
    'display' => [
        'country' => '국가',
        'mobile' => '휴대전화',
        'ifsc' => 'IFSC',
        'amount' => '금액',
    ],

    // placeholders for input hints
    'placeholder' => [
        'country' => '국가를 선택해 주세요',
        'mobile' => '휴대전화 번호를 입력해 주세요',
        'ifsc' => 'IFSC 코드를 입력해 주세요',
        'amount' => '금액을 입력해 주세요',
    ],

    // country options keyed by provider value
    'country' => [
        'options' => [
            '91' => '인도',
            '1' => '미국',
            '44' => '영국',
            '86' => '중국',
        ],
    ],
];
