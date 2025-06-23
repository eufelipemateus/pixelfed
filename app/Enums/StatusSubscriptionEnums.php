<?php

namespace App\Enums;

enum StatusSubscriptionEnums
{

    case ACTIVE;
    case CANCELED;
    case EXPIRED;
    case SUSPENDED;

    public function value(): ?string
    {
        return match ($this) {
            self::ACTIVE => 'active',
            self::CANCELED => 'canceled',
            self::EXPIRED => 'expired',
            self::SUSPENDED => 'suspended'
        };
    }

    public static function fromValue(?string $value): ?self
    {
        return match ($value) {
            'active' => self::ACTIVE,
            'canceled' => self::CANCELED,
            'expired' => self::EXPIRED,
            'suspended' => self::SUSPENDED,
            default => self::ACTIVE,
        };
    }

    public function badge(): array
    {
        return match ($this) {
            self::ACTIVE => ['Ativa', 'success'],
            self::CANCELED => ['Cancelada', 'danger'],
            self::EXPIRED => ['Expirada', 'secondary'],
            self::SUSPENDED => ['Suspensa', 'warning'],
        };
    }
}
