<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Tester = 'tester';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Yönetici',
            self::Tester => 'Deney Sorumlusu',
        };
    }
}
