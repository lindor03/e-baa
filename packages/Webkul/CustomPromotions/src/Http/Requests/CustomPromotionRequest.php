<?php

namespace Webkul\CustomPromotions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->guard('admin')->check();
    }

    public function rules(): array
    {
        $id = $this->route('id') ?? $this->route('promotion');

        return [
            'name'        => ['required','string','max:255'],
            'slug'        => [
                'nullable','string','max:191','alpha_dash',
                Rule::unique('custom_promotions','slug')->ignore($id)
            ],
            'sort_order'  => ['nullable','integer','between:-2147483648,2147483647'],
            'from'        => ['nullable','date'],
            'to'          => ['nullable','date','after_or_equal:from'],
            'is_active'   => ['sometimes','boolean'],

            'items'                 => ['required','array','min:1'],
            'items.*.product_id'    => ['required','integer','exists:products,id'],
            'items.*.special_price' => ['required','numeric','min:0'],

            'banner'        => ['nullable','mimes:jpeg,jpg,png,webp,avif,svg','max:4096'],
            'logo'          => ['nullable','mimes:jpeg,jpg,png,webp,avif,svg','max:2048'],
            'remove_banner' => ['nullable','boolean'],
            'remove_logo'   => ['nullable','boolean'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        $data['is_active']  = $this->boolean('is_active');
        // default sort_order to 0 if not provided
        if (! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = 0;
        }
        return $data;
    }
}
