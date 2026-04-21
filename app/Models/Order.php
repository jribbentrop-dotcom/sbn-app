<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'sbn_orders';

    protected $fillable = [
        'guest_email',
        'total_cents',
        'display_currency',
        'status',
        'stripe_payment_intent_id',
        'token',
    ];

    protected $casts = [
        'total_cents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getTotalFormattedAttribute(): string
    {
        $symbol = $this->display_currency === 'USD' ? '$' : '€';
        return $symbol . number_format($this->total_cents / 100, 2);
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function downloadGrants(): HasMany
    {
        return $this->hasMany(DownloadGrant::class, 'order_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending_stub');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
}
