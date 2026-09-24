<?php

namespace App\Enums;

/**
 * How the platform is paid by a store. Each store uses exactly one model.
 */
enum PlatformFeeModel: string
{
    case Percentage = 'percentage';
    case FlatPerOrder = 'flat_per_order';
    case Subscription = 'subscription';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of each sale',
            self::FlatPerOrder => 'Flat amount per order',
            self::Subscription => 'Monthly subscription',
            self::None => 'No platform fee',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Percentage => 'A percentage of the order subtotal, before sales tax and the processing fee.',
            self::FlatPerOrder => 'The same dollar amount on every paid order, regardless of its size.',
            self::Subscription => 'A fixed monthly charge billed to the funeral home, with no per-order fee.',
            self::None => 'The store keeps everything; nothing is collected by the platform.',
        };
    }

    /**
     * Whether this model takes a cut of each order via the Stripe application fee.
     */
    public function chargesPerOrder(): bool
    {
        return in_array($this, [self::Percentage, self::FlatPerOrder], true);
    }
}
