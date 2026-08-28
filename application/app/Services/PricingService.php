<?php

namespace App\Services;

use App\Models\User;

class PricingService
{
    /** The list used when nobody says otherwise. */
    public static function defaultList(): string
    {
        return (string) config('pricing.default_list', 'standard');
    }

    /** Every list, as key => label, for the account form. */
    public static function lists(): array
    {
        return collect(config('pricing.lists', []))
            ->map(fn ($list) => $list['label'] ?? '')
            ->all();
    }

    /** A list key that certainly exists — anything unknown falls back. */
    public static function resolve(?string $list): string
    {
        $list = (string) $list;

        return config("pricing.lists.$list") ? $list : self::defaultList();
    }

    /**
     * The list an account officer sells from. Everyone else — leaders looking
     * at somebody's order, the admin — gets the standard one, and the ORDER's
     * own list is what actually prices a job once it exists.
     */
    public static function listFor(?User $user): string
    {
        return self::resolve($user?->price_list);
    }

    /** @return array<string, array> */
    public static function products(?string $list = null): array
    {
        return config('pricing.lists.'.self::resolve($list).'.products', []);
    }

    public static function label(?string $type, ?string $list = null): ?string
    {
        return config('pricing.lists.'.self::resolve($list).".products.$type.label");
    }

    /**
     * The most of this product one order may ask for.
     *
     * The product's own ceiling if it sets one, otherwise the shop's. Null
     * only if somebody clears both, which means no ceiling at all.
     */
    public static function maxQuantity(?string $type, ?string $list = null): ?int
    {
        $own = config('pricing.lists.'.self::resolve($list).".products.$type.max_quantity");

        $max = $own ?? config('pricing.max_quantity');

        return $max === null ? null : (int) $max;
    }

    public static function backPocketFee(): int
    {
        return (int) config('pricing.back_pocket_fee', 0);
    }

    /**
     * What one piece costs, and what the whole lot costs.
     *
     * A product is priced one of three ways: by quantity band ('tiers'), at
     * one figure whatever the quantity ('price'), or not automatically at all
     * ('range'), where the officer types the price and the range is handed
     * back so the form can say what it must fall between.
     *
     * @return array{unit: ?float, total: ?float, base: ?float, fee: float, needs_quote: bool, label: ?string, range: ?array}
     */
    public static function quote(
        string $type,
        int $qty,
        bool $backPocket = false,
        ?int $backPocketQty = null,
        ?string $list = null,
    ): array {
        $product = config('pricing.lists.'.self::resolve($list).".products.$type");
        $supportsPocket = (bool) ($product['back_pocket'] ?? false);

        // Back pocket is charged separately (its own line), never folded into
        // the garment's per-piece price.
        $feePer = $supportsPocket ? (float) self::backPocketFee() : 0.0;
        $pocketQty = ($backPocket && $supportsPocket)
            ? max(0, min((int) ($backPocketQty ?? $qty), max(0, $qty)))
            : 0;
        $backPocketAmount = $feePer * $pocketQty;

        $range = isset($product['range'])
            ? [(float) $product['range'][0], (float) $product['range'][1]]
            : null;

        $result = [
            'unit' => null, 'total' => null, 'base' => null,
            'fee' => $feePer, 'back_pocket_qty' => $pocketQty, 'back_pocket_amount' => $backPocketAmount,
            'supports_pocket' => $supportsPocket, 'needs_quote' => true,
            'label' => $product['label'] ?? null, 'range' => $range,
        ];

        if (! $product || $qty < 1) {
            return $result;
        }

        $base = self::basePrice($product, $qty);

        // A range, or over the highest tier — either way there is no figure to
        // compute and somebody has to say what the price is.
        if ($base === null) {
            return $result;
        }

        return [
            'unit' => $base,                                   // garment price per piece (no pocket)
            'total' => $base * $qty + $backPocketAmount,
            'base' => $base,
            'fee' => $feePer,
            'back_pocket_qty' => $pocketQty,
            'back_pocket_amount' => $backPocketAmount,
            'supports_pocket' => $supportsPocket,
            'needs_quote' => false,
            'label' => $product['label'],
            'range' => null,
        ];
    }

    /** The per-piece price for this quantity, or null when there isn't one. */
    private static function basePrice(array $product, int $qty): ?float
    {
        // One price, whatever the quantity — the merch line is sold this way.
        if (isset($product['price'])) {
            return (float) $product['price'];
        }

        foreach ($product['tiers'] ?? [] as $tier) {
            if ($qty >= $tier['min'] && $qty <= $tier['max']) {
                return (float) $tier['price'];
            }
        }

        return null;
    }
}
