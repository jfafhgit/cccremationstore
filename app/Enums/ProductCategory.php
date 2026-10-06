<?php

namespace App\Enums;

/**
 * Cases are declared in the order the storefront wizard presents them,
 * which the admin products page follows too. Keepsake allowances come last:
 * they're never offered on their own, only included with a package.
 */
enum ProductCategory: string
{
    case Package = 'package';
    case Container = 'container';
    case Urn = 'urn';
    case UrnVault = 'urn_vault';
    case Addon = 'addon';
    case Choice = 'choice';
    case Keepsake = 'keepsake';
    case KeepsakeAllowance = 'keepsake_allowance';

    public function label(): string
    {
        return match ($this) {
            self::Package => 'Package',
            self::Container => 'Cremation Container',
            self::Urn => 'Urn',
            self::UrnVault => 'Urn Vault',
            self::Addon => 'Add-on / Service',
            self::Choice => 'Choose-One Item',
            self::Keepsake => 'Keepsake',
            self::KeepsakeAllowance => 'Keepsake Allowance',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Addon => 'Add-ons & Services',
            default => $this->label().'s',
        };
    }
}
