<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $table = 'sbn_order_items';

    protected $fillable = [
        'order_id',
        'product_id',
        'title_snapshot',
        'price_cents_snapshot',
        'quantity',
    ];

    protected $casts = [
        'price_cents_snapshot' => 'integer',
        'quantity' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getSubtotalCentsAttribute(): int
    {
        return $this->price_cents_snapshot * $this->quantity;
    }
}
