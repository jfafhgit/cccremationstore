<?php

namespace App\Enums;

enum OrderTiming: string
{
    case Immediate = 'immediate';
    case PreNeed = 'pre_need';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immediately, my loved one has passed',
            self::PreNeed => 'Soon, I\'m preparing for end-of-life needs',
        };
    }

    /**
     * How funeral home staff refer to it, rather than the family-facing wording.
     */
    public function staffLabel(): string
    {
        return match ($this) {
            self::Immediate => 'At-need (loved one has passed)',
            self::PreNeed => 'Pre-need planning',
        };
    }
}
