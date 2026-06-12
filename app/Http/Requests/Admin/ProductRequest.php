<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'title'            => ['required', 'string', 'max:255'],
            'slug'             => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::unique('sbn_products', 'slug')->ignore($productId)],
            'price'            => ['required', 'numeric', 'min:0'],
            'excerpt'          => ['nullable', 'string'],
            'description'      => ['nullable', 'string'],
            'meta_description' => ['nullable', 'string'],
            'status'           => ['required', Rule::in(['published', 'draft'])],
            'payment_ref'      => ['nullable', 'string', 'max:255'],
            'tax_code'         => ['nullable', 'string', 'max:255'],
            'thumbnail_path'   => ['nullable', 'string', 'max:500'],
            'attr_composer'    => ['nullable', 'string', 'max:255'],
            'attr_performer'   => ['nullable', 'string', 'max:255'],
            'attr_pages'       => ['nullable', 'integer', 'min:1'],
            'attr_notation'    => ['nullable', 'string', 'max:255'],
            'categories'       => ['nullable', 'array'],
            'categories.*'     => ['integer'],
        ];
    }

    /** Return data ready to pass to Product::create/update (maps price euros -> price_cents). */
    public function productData(): array
    {
        $data = $this->safe()->except(['price', 'categories', 'attr_composer', 'attr_performer', 'attr_pages', 'attr_notation']);

        $data['price_cents'] = (int) round($this->input('price', 0) * 100);

        $data['attributes'] = array_filter([
            'composer'  => $this->input('attr_composer'),
            'performer' => $this->input('attr_performer'),
            'pages'     => $this->input('attr_pages') ? (int) $this->input('attr_pages') : null,
            'notation'  => $this->input('attr_notation'),
        ], fn($v) => $v !== null && $v !== '');

        return $data;
    }
}
