<?php

namespace Webkul\RestApi\Http\Resources\V2\Shop\Cms;

use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray($request)
    {
        $locale = app()->getLocale();

        $translation = $this->translations
            ->where('locale', $locale)
            ->first()
            ?? $this->translations->first();

        return [
            'id'        => (int) $this->id,
            'type'      => (string) $this->type,
            'position'  => (int) $this->position,
            'is_active' => (bool) $this->is_active,
            'layout'    => $this->layout,

            /* Translated fields */
            'page_title'       => $translation?->page_title,
            'url_key'          => $translation?->url_key,
            'html_content'     => $translation?->html_content,
            'meta_title'       => $translation?->meta_title,
            'meta_description' => $translation?->meta_description,
            'meta_keywords'    => $translation?->meta_keywords,
            'locale'           => $translation?->locale,

            /* Channels */
            'channels' => $this->channels->map(fn ($channel) => [
                'id'   => (int) $channel->id,
                'code' => (string) $channel->code,
                'name' => (string) $channel->name,
            ]),

            /* Widgets (REFERENCE ONLY) */
            'widgets' => $this->whenLoaded('widgets', function () {
                return $this->widgets->map(fn ($widget) => [
                    'id'           => (int) $widget->id,
                    // 'type'         => (string) $widget->type,
                    // 'title'        => (string) ($widget->title ?? ''),
                    'sort_order'   => (int) $widget->pivot->sort_order,
                    'content_hash' => (string) ($widget->content_hash ?? ''),
                    'cache_key'    => "widget:{$widget->id}:{$widget->content_hash}",
                ]);
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
