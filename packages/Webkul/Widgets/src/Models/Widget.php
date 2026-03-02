<?php

namespace Webkul\Widgets\Models;

use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    protected $table = 'widgets';

    protected $fillable = [
        'type',
        'title',
        'description',
        'config',
        'sort_order',
        'status',
        'content_hash', // ✅ add
    ];

    protected $casts = [
        'config'  => 'array',
        'status'  => 'boolean',
    ];

    protected static function booted()
    {
        static::saving(function (self $widget) {
            // ✅ Only recompute if something relevant changed
            if (! $widget->isDirty(['type', 'title', 'description', 'config', 'sort_order', 'status'])) {
                return;
            }

            $payload = [
                'type'        => (string) $widget->type,
                'title'       => (string) ($widget->title ?? ''),
                'description' => (string) ($widget->description ?? ''),
                'sort_order'  => (int) ($widget->sort_order ?? 0),
                'status'      => (bool) ($widget->status ?? false),
                'config'      => is_array($widget->config) ? $widget->config : [],
            ];

            $normalized = self::normalizeForHash($payload);

            // ✅ JSON_UNESCAPED_* makes it more stable + readable
            $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $widget->content_hash = hash('sha256', $json ?: '');
        });
    }

    protected static function normalizeForHash($value)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::normalizeForHash($v);
            }

            // ✅ sort keys for associative arrays
            if (array_keys($value) !== range(0, count($value) - 1)) {
                ksort($value);
            }
        }

        return $value;
    }



    public function pages()
    {
        return $this->belongsToMany(
            \Webkul\CMS\Models\Page::class,
            'cms_page_widgets',
            'widget_id',
            'cms_page_id'
        )
        ->withPivot([
            'position',
            'sort_order',
            'is_active',
        ])
        ->withTimestamps();
    }
}
