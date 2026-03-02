<?php

namespace Webkul\CustomPromotions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Promotion extends Model
{
    protected $table = 'custom_promotions';

    protected $fillable = [
        'name','slug','sort_order','from','to','is_active','banner_path','logo_path',
    ];

    protected $casts = [
        'from'       => 'datetime',
        'to'         => 'datetime',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['banner_url', 'logo_url'];

    protected static function booted(): void
    {
        static::creating(function (self $promotion) {
            if (blank($promotion->slug)) {
                $promotion->slug = self::makeUniqueSlug($promotion->name);
            }
        });

        static::updating(function (self $promotion) {
            // Preserve existing slug unless explicitly changed/cleared
            if ($promotion->isDirty('slug')) {
                $promotion->slug = blank($promotion->slug)
                    ? self::makeUniqueSlug($promotion->name, $promotion->id)
                    : self::makeUniqueSlug($promotion->slug, $promotion->id, preSlugged: true);
            } elseif ($promotion->isDirty('name') && blank($promotion->slug)) {
                // If slug is still null and name changed, generate one
                $promotion->slug = self::makeUniqueSlug($promotion->name, $promotion->id);
            }
        });
    }

    public static function makeUniqueSlug(string $value, ?int $exceptId = null, bool $preSlugged = false): string
    {
        $base = $preSlugged ? Str::slug($value) : Str::slug($value ?? '');
        $base = $base !== '' ? $base : 'promotion';

        $query = static::query()->where('slug', $base);
        if ($exceptId) { $query->where('id', '!=', $exceptId); }

        if (! $query->exists()) {
            return $base;
        }

        // Append -2, -3, ...
        $i = 2;
        do {
            $candidate = "{$base}-{$i}";
            $dupe = static::query()
                ->where('slug', $candidate)
                ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
                ->exists();
            if (! $dupe) return $candidate;
            $i++;
        } while (true);
    }

    public function products()
    {
        return $this->belongsToMany(\Webkul\Product\Models\Product::class, 'custom_promotion_products', 'promotion_id', 'product_id')
                    ->withPivot(['special_price'])
                    ->withTimestamps();
    }

    public function scopeActiveNow($q)
    {
        $now = now();
        return $q->where('is_active', true)
                 ->where(fn($q) => $q->whereNull('from')->orWhere('from','<=',$now))
                 ->where(fn($q) => $q->whereNull('to')->orWhere('to','>=',$now));
    }

    public function getBannerUrlAttribute(): ?string
    {
        $path = $this->banner_path;
        if (! $path) return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
        return Storage::disk('public')->url($path);
    }

    public function getLogoUrlAttribute(): ?string
    {
        $path = $this->logo_path;
        if (! $path) return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
        return Storage::disk('public')->url($path);
    }
}
