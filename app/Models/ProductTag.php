<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductTag extends Model
{
    protected $table = 'sbn_product_tags';

    protected $fillable = [
        'slug',
        'name',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'sbn_product_tag', 'tag_id', 'product_id');
    }
}
