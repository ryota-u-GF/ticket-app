<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';

    /**
     * Get the Japanese label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Published => '公開中',
            self::Unpublished => '非公開',
        };
    }

    /**
     * Get the Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Published => 'green',
            self::Unpublished => 'red',
        };
    }
}
