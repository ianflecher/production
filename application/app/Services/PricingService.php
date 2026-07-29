<?php

namespace App\Services;

class PricingService
{
    /** @return array<string, array> */
    public static function products(): array
    {
        return config('pricing.products', []);
    }

    public static function label(?string $type): ?string
    {
        return config("pricing.products.$type.label");
    }

    public static function backPocketFee(): int
    {
        return (int) config('pricing.back_pocket_fee', 0);
    }

    /**
     * Compute the price for a product type + quantity (+ optional back pocket).
     *
     * @return array{unit: ?float, total: ?float, base: ?float, fee: float, needs_quote: bool, label: ?string}
     */
    public static function quote(string $type, int $qty, bool $backPocket = false, ?int $backPocketQty = null): array
    {
        $product = config("pricing.products.$type");
        $supportsPocket = (bool) ($product['back_pocket'] ?? false);

        // Back pocket is charged separately (its own line), never folded into
        // the garment's per-piece price.
        $feePer = $supportsPocket ? (float) self::backPocketFee() : 0.0;
        $pocketQty = ($backPocket && $supportsPocket)
            ? max(0, min((int) ($backPocketQty ?? $qty), max(0, $qty)))
            : 0;
        $backPocketAmount = $feePer * $pocketQty;

        $result = [
            'unit' => null, 'total' => null, 'base' => null,
            'fee' => $feePer, 'back_pocket_qty' => $pocketQty, 'back_pocket_amount' => $backPocketAmount,
            'supports_pocket' => $supportsPocket, 'needs_quote' => true, 'label' => $product['label'] ?? null,
        ];

        if (! $product || $qty < 1) {
            return $result;
        }

        $base = null;
        foreach ($product['tiers'] as $tier) {
            if ($qty >= $tier['min'] && $qty <= $tier['max']) {
                $base = (float) $tier['price'];
                break;
            }
        }

        // Over the highest tier — needs a manual quotation.
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
        ];
    }
}
