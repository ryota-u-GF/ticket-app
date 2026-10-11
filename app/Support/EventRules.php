<?php

namespace App\Support;

use Illuminate\Contracts\Validation\ValidationRule;

class EventRules
{
    /**
     * Get the validation rules used to validate events.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'venue' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * Get the Japanese names of the event attributes.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'description' => '説明',
            'venue' => '会場',
            'starts_at' => '開始日時',
            'ends_at' => '終了日時',
        ];
    }
}
