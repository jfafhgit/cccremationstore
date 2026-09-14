<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Pending Payment',
            self::Paid => 'Paid',
            self::InReview => 'In Review',
            self::Completed => 'Completed',
            self::Canceled => 'Canceled',
            self::Refunded => 'Refunded',
        };
    }
}
