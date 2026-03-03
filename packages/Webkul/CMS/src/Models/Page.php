<?php

namespace Webkul\CMS\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Webkul\CMS\Contracts\Page as PageContract;
use Webkul\CMS\Database\Factories\PageFactory;
use Webkul\Core\Eloquent\TranslatableModel;
use Webkul\Core\Models\ChannelProxy;

class Page extends TranslatableModel implements PageContract
{
    use HasFactory;

    protected $table = 'cms_pages';

    protected $translationForeignKey = 'cms_page_id';

    protected $fillable = [
        'layout',
        'type',
        'position',
        'is_active',
    ];

    protected $casts = [
        'position'  => 'integer',
        'is_active' => 'boolean',
    ];

    public $translatedAttributes = [
        'content',
        'meta_description',
        'meta_title',
        'page_title',
        'meta_keywords',
        'html_content',
        'url_key',
    ];

    protected $with = ['translations'];

    public function channels()
    {
        return $this->belongsToMany(
            ChannelProxy::modelClass(),
            'cms_page_channels',
            'cms_page_id'
        );
    }

    /* -------------------- Scopes -------------------- */

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    protected static function newFactory(): Factory
    {
        return PageFactory::new();
    }


    public function widgets()
    {
        return $this->belongsToMany(
            \Webkul\Widgets\Models\Widget::class,
            'cms_page_widgets',
            'cms_page_id',
            'widget_id'
        )
        ->withPivot([
            'position',
            'sort_order',
            'is_active',
        ])
        ->withTimestamps()
        ->orderBy('cms_page_widgets.sort_order');
    }

}



