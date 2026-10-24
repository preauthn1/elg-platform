<?php

declare(strict_types=1);

return [
    // display mappings for UI labels
    'display' => [
        'country' => 'Pays',
        'mobile' => 'Téléphone mobile',
        'ifsc' => 'IFSC',
        'amount' => 'Montant',
    ],

    // placeholders for input hints
    'placeholder' => [
        'country' => 'Veuillez sélectionner un pays',
        'mobile' => 'Veuillez saisir le numéro de téléphone mobile',
        'ifsc' => 'Veuillez saisir le code IFSC',
        'amount' => 'Veuillez saisir le montant',
    ],

    // country options keyed by provider value
    'country' => [
        'options' => [
            '91' => 'Inde',
            '1' => 'États-Unis',
            '44' => 'Royaume-Uni',
            '86' => 'Chine',
        ],
    ],
];
