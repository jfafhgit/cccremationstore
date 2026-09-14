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
}
