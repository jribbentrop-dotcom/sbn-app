<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;

/**
 * Single source of truth for granting / revoking course access.
 *
 * Phase 12 (Lemon Squeezy) wires the webhook handler to call grantPurchase()
 * on `order_created` and revokePurchase() on `order_refunded`. The Customer
 * Backend admin grant tool also routes through grantManual() so all writes
 * to course_user funnel through one place.
 */
class CourseAccessService
{
    public function grantManual(User $user, Course $course, ?\DateTimeInterface $expiresAt = null): void
    {
        $this->attach($user, $course, 'manual_grant', null, $expiresAt);
    }

    public function grantPurchase(User $user, Order $order): void
    {
        foreach ($this->coursesForOrder($order) as $course) {
            $this->attach($user, $course, 'purchase', $order->id, null);
        }
    }

    public function revokePurchase(Order $order): void
    {
        \DB::table('course_user')
            ->where('order_id', $order->id)
            ->where('source', 'purchase')
            ->delete();
    }

    public function bumpLastAccessed(User $user, Course $course): void
    {
        \DB::table('course_user')
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->update(['last_accessed_at' => now()]);
    }

    private function attach(User $user, Course $course, string $source, ?int $orderId, ?\DateTimeInterface $expiresAt): void
    {
        $user->courses()->syncWithoutDetaching([
            $course->id => [
                'source'     => $source,
                'order_id'   => $orderId,
                'granted_at' => now(),
                'expires_at' => $expiresAt,
            ],
        ]);
    }

    /**
     * Resolve which courses a paid order grants.
     *
     * sbn_orders → sbn_order_items → sbn_products. Phase 12 will need each
     * Course to carry a product_id (already present on sbn_courses) so this
     * join works without extra schema.
     */
    private function coursesForOrder(Order $order): iterable
    {
        $productIds = $order->items()->pluck('product_id')->filter()->all();
        if (empty($productIds)) {
            return [];
        }
        return Course::whereIn('product_id', $productIds)->get();
    }
}
