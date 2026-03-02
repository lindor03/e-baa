<?php

namespace Webkul\CustomPromotions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\CustomPromotions\Models\Promotion;

class ApplyPromotionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    protected static array $attrIds = [];
    protected static ?string $priceColumn = null;
    protected static ?string $dateColumn  = null;
    protected static bool $hasUniqueId    = true;

    public function handle(): void
    {
        $today = now()->toDateString(); // product_flat keeps DATE
        $now   = now();

        $this->bootstrapColumns();
        $this->bootstrapAttributeIds();

        $promotions = Promotion::query()
            ->activeNow()
            ->with(['products:id']) // relation has ->withPivot('special_price')
            ->get(['id','from','to']);

        // product_id => ['special_price','special_price_from','special_price_to','promotion_id']
        $perProduct = [];

        foreach ($promotions as $promo) {
            $fromDate = $promo->from?->toDateString();
            $toDate   = $promo->to?->toDateString();

            foreach ($promo->products as $p) {
                $raw = $p->pivot->special_price;

                if ($raw === null || $raw === '' || !is_numeric($raw)) {
                    continue;
                }

                $entry = [
                    'special_price'      => (string) $raw,
                    'special_price_from' => $fromDate,
                    'special_price_to'   => $toDate,
                    'promotion_id'       => (int) $promo->id,
                ];

                $pid = (int) $p->id;

                if (!isset($perProduct[$pid])) {
                    $perProduct[$pid] = $entry;
                    continue;
                }

                // Keep the lowest; tie -> earlier start
                $curr = $perProduct[$pid];
                $cmp  = bccomp($entry['special_price'], $curr['special_price'], 4);

                if ($cmp === -1) {
                    $perProduct[$pid] = $entry;
                } elseif ($cmp === 0) {
                    $a = $entry['special_price_from'];
                    $b = $curr['special_price_from'];
                    if ($a !== $b) {
                        if ($a === null || ($b !== null && $a < $b)) {
                            $perProduct[$pid] = $entry;
                        }
                    }
                }
            }
        }

        if (empty($perProduct)) {
            // No active promos -> clear flat dates/prices and null products.promotion_id
            $this->clearExpiredFlat($today);
            $this->clearProductsPromotion([]); // clear all currently set
            return;
        }

        // ---- Apply to product_flat, PAV, and products.promotion_id
        foreach ($perProduct as $pid => $vals) {
            // product_flat (all channel/locale rows)
            DB::table('product_flat')
                ->where('product_id', $pid)
                ->update($vals + ['updated_at' => $now]);

            // PAV (price -> null/null; dates -> default/null)
            $this->upsertAttributeValues(
                $pid,
                $vals['special_price'],
                $vals['special_price_from'],
                $vals['special_price_to']
            );

            // products.promotion_id (per product)
            DB::table('products')
                ->where('id', $pid)
                ->update(['promotion_id' => $vals['promotion_id']]);
        }

        // Clear future/expired rows in flat
        $this->clearExpiredFlat($today);

        // Ensure products not in the active set have promotion_id = NULL
        $this->clearProductsPromotion(array_keys($perProduct));
    }

    protected function bootstrapColumns(): void
    {
        static::$priceColumn = Schema::hasColumn('product_attribute_values', 'decimal_value')
            ? 'decimal_value'
            : (Schema::hasColumn('product_attribute_values', 'float_value') ? 'float_value' : 'text_value');

        static::$dateColumn = Schema::hasColumn('product_attribute_values', 'date_value')
            ? 'date_value'
            : 'datetime_value';

        static::$hasUniqueId = Schema::hasColumn('product_attribute_values', 'unique_id');
    }

    protected function bootstrapAttributeIds(): void
    {
        if (!static::$attrIds) {
            static::$attrIds = DB::table('attributes')
                ->whereIn('code', ['special_price', 'special_price_from', 'special_price_to'])
                ->pluck('id', 'code')
                ->all();
        }
    }

    // Force price -> (NULL,NULL) unique_id="product|attr"
    // Dates -> ("default",NULL) unique_id="default|product|attr"
    protected function upsertAttributeValues(int $productId, string $price, ?string $fromDate, ?string $toDate): void
    {
        $priceAttrId = static::$attrIds['special_price']      ?? null;
        $fromAttrId  = static::$attrIds['special_price_from'] ?? null;
        $toAttrId    = static::$attrIds['special_price_to']   ?? null;

        if (!$priceAttrId || !$fromAttrId || !$toAttrId) return;

        // special_price
        $this->updateOrInsertPav(
            productId:   $productId,
            attributeId: $priceAttrId,
            channel:     null,
            locale:      null,
            values:      [ static::$priceColumn => $price ],
            uniqueId:    "{$productId}|{$priceAttrId}"
        );

        // special_price_from
        $this->updateOrInsertPav(
            productId:   $productId,
            attributeId: $fromAttrId,
            channel:     'default',   // change if your base channel differs
            locale:      null,
            values:      [ static::$dateColumn => $fromDate ],
            uniqueId:    "default|{$productId}|{$fromAttrId}"
        );

        // special_price_to
        $this->updateOrInsertPav(
            productId:   $productId,
            attributeId: $toAttrId,
            channel:     'default',
            locale:      null,
            values:      [ static::$dateColumn => $toDate ],
            uniqueId:    "default|{$productId}|{$toAttrId}"
        );
    }

    protected function updateOrInsertPav(
        int $productId,
        int $attributeId,
        ?string $channel,
        ?string $locale,
        array $values,
        ?string $uniqueId = null
    ): void {
        $update = $values;

        if (static::$hasUniqueId && $uniqueId !== null) {
            $update['unique_id'] = $uniqueId;
        }

        DB::table('product_attribute_values')->updateOrInsert(
            [
                'product_id'   => $productId,
                'attribute_id' => $attributeId,
                'channel'      => $channel,
                'locale'       => $locale,
            ],
            $update
        );
    }

    protected function clearExpiredFlat(string $today): void
    {
        DB::table('product_flat')
            ->where(function (Builder $q) use ($today) {
                $q->where(function ($q) use ($today) {
                    $q->whereNotNull('special_price_from')
                      ->where('special_price_from', '>', $today);
                })->orWhere(function ($q) use ($today) {
                    $q->whereNotNull('special_price_to')
                      ->where('special_price_to', '<', $today);
                });
            })
            ->update([
                'special_price'      => null,
                'special_price_from' => null,
                'special_price_to'   => null,
                'promotion_id'       => null,
                'updated_at'         => now(),
            ]);
    }

    /**
     * Null out products.promotion_id for any product not in the current active set.
     */
    protected function clearProductsPromotion(array $activeProductIds): void
    {
        $query = DB::table('products')->whereNotNull('promotion_id');

        if (!empty($activeProductIds)) {
            $query->whereNotIn('id', $activeProductIds);
        }

        $query->update(['promotion_id' => null]);
    }
}
