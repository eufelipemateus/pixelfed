<?php

namespace App\Enums;

enum StatusSubscriptionEnums
{

case ACTIVE;
case CANCELED;

    public function value(): ?string
    {
        return match ($this) {
            self::ACTIVE => 'active',
            self::CANCELED => 'canceled',
        };
    }

    public static function fromValue(?string $value): ?self
    {
        return match ($value) {
            'active' => self::ACTIVE,
            'canceled' => self::CANCELED,
            default => self::ACTIVE,
        };
    }
}
