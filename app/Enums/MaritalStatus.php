<?php

namespace App\Enums;

enum MaritalStatus: string
{
    case NeverMarried = 'never_married';
    case Married = 'married';
    case MarriedButSeparated = 'married_but_separated';
    case Widowed = 'widowed';
    case Divorced = 'divorced';

    public function label(): string
    {
        return match ($this) {
            self::NeverMarried => 'Never married',
            self::Married => 'Married',
            self::MarriedButSeparated => 'Married, but separated',
            self::Widowed => 'Widowed',
            self::Divorced => 'Divorced',
        };
    }

    /**
     * Whether the death certificate asks for a (surviving or late) spouse.
     */
    public function hasSpouse(): bool
    {
        return in_array($this, [self::Married, self::MarriedButSeparated, self::Widowed], true);
    }
}
