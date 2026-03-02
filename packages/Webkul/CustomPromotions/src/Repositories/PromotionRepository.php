<?php

namespace Webkul\CustomPromotions\Repositories;

use Webkul\CustomPromotions\Models\Promotion;

class PromotionRepository
{
    public function create(array $data): Promotion
    {
        $promotion = Promotion::query()->create([
            'name'       => $data['name'],
            'slug'       => $data['slug']       ?? null,         // model will auto-generate if null
            'sort_order' => $data['sort_order'] ?? 0,
            'from'       => $data['from']       ?? null,
            'to'         => $data['to']         ?? null,
            'is_active'  => array_key_exists('is_active', $data) ? (bool)$data['is_active'] : false,
        ]);

        $this->upsertMedia($promotion, $data);
        $this->syncItems($promotion, $data['items'] ?? []);

        return $promotion;
    }

    public function update(Promotion $promotion, array $data): Promotion
    {
        $promotion->update([
            'name'       => $data['name']       ?? $promotion->name,
            'slug'       => array_key_exists('slug', $data) ? $data['slug'] : $promotion->slug, // allow explicit change
            'sort_order' => array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : $promotion->sort_order,
            'from'       => $data['from']       ?? $promotion->from,
            'to'         => $data['to']         ?? $promotion->to,
            'is_active'  => array_key_exists('is_active', $data) ? (bool)$data['is_active'] : $promotion->is_active,
        ]);

        $this->upsertMedia($promotion, $data);

        if (array_key_exists('items', $data)) {
            $this->syncItems($promotion, $data['items'] ?? []);
        }

        return $promotion;
    }

    protected function upsertMedia(Promotion $promotion, array $data): void
    {
        if (!empty($data['remove_banner'])) {
            if ($promotion->banner_path) \Storage::disk('public')->delete($promotion->banner_path);
            $promotion->banner_path = null;
        }

        if (!empty($data['remove_logo'])) {
            if ($promotion->logo_path) \Storage::disk('public')->delete($promotion->logo_path);
            $promotion->logo_path = null;
        }

        if (isset($data['banner']) && $data['banner'] instanceof \Illuminate\Http\UploadedFile) {
            $promotion->banner_path = $data['banner']->store('custom-promotions/banners', 'public');
        }

        if (isset($data['logo']) && $data['logo'] instanceof \Illuminate\Http\UploadedFile) {
            $promotion->logo_path = $data['logo']->store('custom-promotions/logos', 'public');
        }

        $promotion->save();
    }

    protected function syncItems(Promotion $promotion, array $items): void
    {
        $map = [];
        foreach ($items as $row) {
            if (!isset($row['product_id']) || !isset($row['special_price'])) continue;
            $map[(int)$row['product_id']] = ['special_price' => $row['special_price']];
        }
        $promotion->products()->sync($map);
    }
}
