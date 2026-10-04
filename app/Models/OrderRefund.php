<?php

namespace App\Models;

use Database\Factories\OrderRefundFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A refund against an order's Stripe payment, whether issued from the admin
 * or from the store's own Stripe dashboard (synced in by webhook).
 *
 * @property int $id
 * @property int $order_id
 * @property string $stripe_refund_id
 * @property int $amount_cents
 * @property int $application_fee_refunded_cents
 * @property string $status Stripe's refund status: pending, succeeded, failed, canceled, or requires_action.
 * @property string|null $reason
 * @property int|null $refunded_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderRefund extends Model
{
    /** @use HasFactory<OrderRefundFactory> */
    use HasFactory;

    /** Statuses whose money has left (or is leaving) the store's account. */
    public const COUNTED_STATUSES = ['pending', 'succeeded', 'requires_action'];

    protected $fillable = [
        'order_id',
        'stripe_refund_id',
        'amount_cents',
        'application_fee_refunded_cents',
        'status',
        'reason',
        'refunded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'application_fee_refunded_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by_user_id');
    }

    #[Scope]
    protected function counted(Builder $query): Builder
    {
        return $query->whereIn('status', self::COUNTED_STATUSES);
    }

    public function amountInDollars(): string
    {
        return number_format($this->amount_cents / 100, 2);
    }
}
