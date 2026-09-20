<?php

namespace App\Enums;

enum StorePath: string
{
    case Packages = 'packages';
    case ALaCarte = 'a_la_carte';

    public function label(): string
    {
        return match ($this) {
            self::Packages => 'Start with packages',
            self::ALaCarte => 'Pure à la carte',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Packages => 'Customers choose from your packages first (3 is ideal, but there is no limit), then personalize.',
            self::ALaCarte => 'Customers start with one base package, with no other package options, then build the rest à la carte.',
        };
    }
}
