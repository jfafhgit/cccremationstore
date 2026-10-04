<?php

namespace App\Enums;

enum OrderTiming: string
{
    case Immediate = 'immediate';
    case Imminent = 'imminent';
    case PreNeed = 'pre_need';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Immediately, my loved one has passed',
            self::Imminent => 'Soon, my loved one is expected to pass in the coming days or weeks',
            self::PreNeed => 'Planning ahead',
        };
    }

    /**
     * How funeral home staff refer to it, rather than the family-facing wording.
     */
    public function staffLabel(): string
    {
        return match ($this) {
            self::Immediate => 'At-need (loved one has passed)',
            self::Imminent => 'Imminent need (passing expected soon)',
            self::PreNeed => 'Pre-need planning',
        };
    }
}
