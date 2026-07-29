<?php

// Price-per-piece tiers by product type. Prices may change for special
// requests — a sales agent can override the computed price at intake, and
// these tables can be edited here.
return [
    'back_pocket_fee' => 50,

    'products' => [
        'round_neck' => [
            'label' => 'Round Neck / V-Neck Shirt',
            'back_pocket' => true,
            'tiers' => [
                ['min' => 1,  'max' => 12,  'price' => 750],
                ['min' => 13, 'max' => 24,  'price' => 700],
                ['min' => 25, 'max' => 36,  'price' => 650],
                ['min' => 37, 'max' => 50,  'price' => 600],
                ['min' => 51, 'max' => 100, 'price' => 550],
            ],
        ],
        'polo' => [
            'label' => 'Polo Shirt',
            'back_pocket' => true,
            'tiers' => [
                ['min' => 1,  'max' => 12,  'price' => 850],
                ['min' => 13, 'max' => 24,  'price' => 800],
                ['min' => 25, 'max' => 36,  'price' => 750],
                ['min' => 37, 'max' => 50,  'price' => 700],
                ['min' => 51, 'max' => 100, 'price' => 650],
            ],
        ],
        'jacket_hoodie' => [
            'label' => 'Jacket / Hoodie',
            'back_pocket' => false,
            'tiers' => [
                ['min' => 1,  'max' => 6,   'price' => 1900],
                ['min' => 7,  'max' => 12,  'price' => 1600],
                ['min' => 13, 'max' => 24,  'price' => 1400],
                ['min' => 25, 'max' => 36,  'price' => 1250],
                ['min' => 37, 'max' => 50,  'price' => 1150],
                ['min' => 51, 'max' => 100, 'price' => 1100],
            ],
        ],
        'riding_jersey' => [
            'label' => 'Riding Jersey',
            'back_pocket' => false,
            'tiers' => [
                ['min' => 1,  'max' => 5,   'price' => 1500],
                ['min' => 6,  'max' => 12,  'price' => 1200],
                ['min' => 13, 'max' => 24,  'price' => 1000],
                ['min' => 25, 'max' => 36,  'price' => 950],
                ['min' => 37, 'max' => 50,  'price' => 850],
                ['min' => 51, 'max' => 100, 'price' => 800],
            ],
        ],
    ],
];
