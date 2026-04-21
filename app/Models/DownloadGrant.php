<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadGrant extends Model
{
    protected $table = 'sbn_download_grants';

    protected $fillable = [
        'order_id',
        'product_id',
        'token',
        'guest_email',
        'downloads_used',
        'expires_at',
    ];

    protected $casts = [
        'downloads_used' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    /**
     * Check if download grant is still valid.
     */
    public function getIsValidAttribute(): bool
    {
        if ($this->downloads_used >= config('shop.download.max_downloads', 5)) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get remaining downloads.
     */
    public function getDownloadsRemainingAttribute(): int
    {
        return max(0, config('shop.download.max_downloads', 5) - $this->downloads_used);
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Increment download count.
     */
    public function recordDownload(): void
    {
        $this->increment('downloads_used');
    }
}
