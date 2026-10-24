<?php

declare(strict_types=1);

return [
    // display mappings for UI labels
    'display' => [
        'country' => 'Country',
        'mobile' => 'Mobile',
        'ifsc' => 'IFSC',
        'amount' => 'Amount',
    ],

    // placeholders for input hints
    'placeholder' => [
        'country' => 'Please select country',
        'mobile' => 'Please enter mobile number',
        'ifsc' => 'Please enter IFSC code',
        'amount' => 'Please enter amount',
    ],

    // country options keyed by provider value
    'country' => [
        'options' => [
            '91' => 'India',
            '1' => 'United States',
            '44' => 'United Kingdom',
            '86' => 'China',
        ],
    ],
];
