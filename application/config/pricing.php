<?php

/*
 * Price lists.
 *
 * There is more than one. Most of the shop sells from the standard list,
 * where the price per piece falls as the order gets bigger. The merch line is
 * sold from a flat list — a hybrid jersey is the same price whether it is
 * five or eighty — and it carries products the standard list has never had.
 *
 * Which list an account officer sells from is on their account
 * (users.price_list). Which list an ORDER was priced from is written onto the
 * order when it is created (production_orders.price_list), so a job keeps the
 * prices it was quoted at even when somebody else opens it later, or the
 * officer is later moved to another list.
 *
 * A product may be priced three ways:
 *   'tiers' => [...]      price per piece by quantity band
 *   'price' => 1300       one price, any quantity
 *   'range' => [800, 850] no automatic price — the officer types it, and the
 *                         form says which figures it must fall between
 */
return [
    /*
     * The most of ONE product a single order may ask for.
     *
     * The tiers above price a piece; they do not say how many the shop can
     * make. Five hundred of anything is already a long run, and an order past
     * it is a conversation rather than a form. A product may override this
     * with its own 'max_quantity'.
     */
    'max_quantity' => 500,

    'back_pocket_fee' => 50,

    'default_list' => 'standard',

    'lists' => [

        'standard' => [
            'label' => 'Standard',
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
        ],

        /*
         * The merch list. Flat prices, no quantity bands.
         *
         * Where the list showed a spread, the spread turned out to be the
         * decoration or the type — a cotton shirt is 800 silkscreened and 850
         * embroidered — so those are two products, each at one price, and the
         * price follows from saying which is being sold, not from typing a
         * figure and hoping it was the right end of the range.
         *
         * Back pocket is off across this list: the standard list charges 50
         * for one on a shirt or polo, and whether the merch line does the
         * same has not been said.
         */
        'merch' => [
            'label' => 'Merch',
            'products' => [
                'hybrid_riding_jersey_type_1' => [
                    'label' => 'Hybrid Riding Jersey — Type 1',
                    'back_pocket' => false,
                    'price' => 1450,
                ],
                'hybrid_riding_jersey_type_2' => [
                    'label' => 'Hybrid Riding Jersey — Type 2',
                    'back_pocket' => false,
                    'price' => 1650,
                ],
                'regular_riding_jersey' => [
                    'label' => 'Regular Riding Jersey',
                    'back_pocket' => false,
                    'price' => 1300,
                ],
                // The spread on these is the decoration, not the garment: the
                // same shirt is 800 silkscreened and 850 embroidered. Two
                // products rather than a range, so the price is picked by
                // saying which one is being sold.
                'cotton_shirt_silkscreen' => [
                    'label' => 'Cotton Shirt — Silkscreen',
                    'back_pocket' => false,
                    'price' => 800,
                ],
                'cotton_shirt_embroidered' => [
                    'label' => 'Cotton Shirt — Embroidered',
                    'back_pocket' => false,
                    'price' => 850,
                ],
                // Taken as: the 950 is when the whole garment is sublimated.
                // Said with a "I think", so it is the one line here worth
                // checking before a quotation goes out on it.
                'sublimation_shirt' => [
                    'label' => 'Sublimation Shirt',
                    'back_pocket' => false,
                    'price' => 850,
                ],
                'sublimation_shirt_full' => [
                    'label' => 'Sublimation Shirt — Full Sublimation',
                    'back_pocket' => false,
                    'price' => 950,
                ],
                'polo_shirt' => [
                    'label' => 'Polo Shirt — Regular',
                    'back_pocket' => false,
                    'price' => 950,
                ],
                'polo_shirt_embroidered' => [
                    'label' => 'Polo Shirt — Embroidered',
                    'back_pocket' => false,
                    'price' => 1150,
                ],
                'polo_button_down' => [
                    'label' => 'Polo Button Down',
                    'back_pocket' => false,
                    'price' => 1150,
                ],
                'tanktop' => [
                    'label' => 'Tanktop',
                    'back_pocket' => false,
                    'price' => 600,
                ],
                'windbreaker' => [
                    'label' => 'Windbreaker',
                    'back_pocket' => false,
                    'price' => 1850,
                ],
                'trucker_cap' => [
                    'label' => 'Trucker Cap',
                    'back_pocket' => false,
                    'price' => 350,
                ],
                'embroidered_cap' => [
                    'label' => 'Embroidered Cap (Yupoong)',
                    'back_pocket' => false,
                    'price' => 1150,
                ],
                'cotton_hoodie' => [
                    'label' => 'Cotton Hoodie',
                    'back_pocket' => false,
                    'price' => 1650,
                ],
                'sweater' => [
                    'label' => 'Sweater',
                    'back_pocket' => false,
                    'price' => 1300,
                ],
                'sweatshirt' => [
                    'label' => 'Sweatshirt — Regular',
                    'back_pocket' => false,
                    'price' => 1650,
                ],
                'sweatshirt_embroidered' => [
                    'label' => 'Sweatshirt — Embroidered',
                    'back_pocket' => false,
                    'price' => 1850,
                ],
                'balaclava' => [
                    'label' => 'Balaclava',
                    'back_pocket' => false,
                    'price' => 350,
                ],
                'tubemask' => [
                    'label' => 'Tubemask',
                    'back_pocket' => false,
                    'price' => 350,
                ],
                'short_adult' => [
                    'label' => 'Short (Adult) — Regular',
                    'back_pocket' => false,
                    'price' => 750,
                ],
                'short_adult_embroidered' => [
                    'label' => 'Short (Adult) — Embroidered',
                    'back_pocket' => false,
                    'price' => 850,
                ],
                'short_kids' => [
                    'label' => 'Short (Kids)',
                    'back_pocket' => false,
                    'price' => 350,
                ],
                'tshirt_kids' => [
                    'label' => 'T-Shirt (Kids)',
                    'back_pocket' => false,
                    'price' => 350,
                ],
                'riding_jersey_kids' => [
                    'label' => 'Riding Jersey (Kids)',
                    'back_pocket' => false,
                    'price' => 650,
                ],
                'polo_button_down_kids' => [
                    'label' => 'Polo Button Down (Kids)',
                    'back_pocket' => false,
                    'price' => 550,
                ],
                'canvass_bag' => [
                    'label' => 'Canvass Bag',
                    'back_pocket' => false,
                    'price' => 450,
                ],
            ],
        ],
    ],
];
