<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $table = 'sbn_products';

    protected $fillable = [
        'slug',
        'payment_ref',
        'title',
        'excerpt',
        'description',
        'price_cents',
        'thumbnail_path',
        'pdf_path',
        'pdf_filename',
        'pdf_original_url',
        'attributes',
        'meta_description',
        'wp_post_id',
        'published_at',
        'status',
        'tax_code',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'wp_post_id' => 'integer',
        'published_at' => 'datetime',
        'attributes' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get price in USD cents using config rate.
     */
    public function getPriceCentsUsdAttribute(): int
    {
        return (int) round($this->price_cents * config('shop.usd_rate', 1.08));
    }

    /**
     * Get formatted price in EUR.
     */
    public function getPriceEurAttribute(): string
    {
        return '€' . number_format($this->price_cents / 100, 2);
    }

    /**
     * Get formatted price in USD.
     */
    public function getPriceUsdAttribute(): string
    {
        $usdCents = $this->price_cents_usd;
        return '$' . number_format($usdCents / 100, 2);
    }

    /**
     * Get thumbnail URL.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (!$this->thumbnail_path) {
            return null;
        }
        return asset('images/products/' . $this->thumbnail_path);
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'sbn_product_category', 'product_id', 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ProductTag::class, 'sbn_product_tag', 'product_id', 'tag_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                     ->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByCategory($query, string $categorySlug)
    {
        return $query->whereHas('categories', function ($q) use ($categorySlug) {
            $q->where('slug', $categorySlug);
        });
    }
}
