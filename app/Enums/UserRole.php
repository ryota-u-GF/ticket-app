<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Organizer = 'organizer';
    case Admin = 'admin';

    /**
     * Get the Japanese label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Customer => '参加者',
            self::Organizer => '主催者',
            self::Admin => '管理者',
        };
    }

    /**
     * Get the roles that can be chosen on the registration screen.
     *
     * @return list<self>
     */
    public static function registrable(): array
    {
        return [self::Customer, self::Organizer];
    }
}
