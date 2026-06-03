<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'sbn_orders';

    protected $fillable = [
        'user_id',
        'guest_email',
        'total_cents',
        'display_currency',
        'status',
        'provider_order_id',
        'stripe_payment_intent_id',
        'token',
    ];

    // Order status values (status column is a string; not all are enum-enforced).
    public const STATUS_PENDING_STUB    = 'pending_stub';
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID            = 'paid';
    public const STATUS_REFUNDED        = 'refunded';
    public const STATUS_FAILED          = 'failed';

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
