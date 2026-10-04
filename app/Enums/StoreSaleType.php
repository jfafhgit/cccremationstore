<?php

namespace App\Enums;

/**
 * Whether a store sells for a death that has occurred (or is imminent) or
 * pre-need arrangements. Pre-need money often can't co-mingle with at-need
 * funds, so a funeral home runs each as its own store and payment account.
 */
enum StoreSaleType: string
{
    case AtNeed = 'at_need';
    case PreNeed = 'pre_need';

    public function label(): string
    {
        return match ($this) {
            self::AtNeed => 'At-need',
            self::PreNeed => 'Pre-need',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AtNeed => 'For a death that has occurred or is imminent. Families say which when they start.',
            self::PreNeed => 'For planning ahead. Families aren\'t asked about timing.',
        };
    }

    /**
     * The timings families can order under in this kind of store.
     *
     * @return list<OrderTiming>
     */
    public function orderTimings(): array
    {
        return match ($this) {
            self::AtNeed => [OrderTiming::Immediate, OrderTiming::Imminent],
            self::PreNeed => [OrderTiming::PreNeed],
        };
    }
}
