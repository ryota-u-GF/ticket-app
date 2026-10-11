<?php

namespace App\Support;

use Illuminate\Contracts\Validation\ValidationRule;

class TicketTypeRules
{
    /**
     * Get the validation rules used to validate ticket types.
     *
     * The prefix is prepended to each key so that forms can use names like "ticket_name".
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:50'],
            $prefix.'price' => ['required', 'integer', 'min:1', 'max:1000000'],
            $prefix.'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            $prefix.'sales_starts_at' => ['required', 'date'],
            $prefix.'sales_ends_at' => ['required', 'date', 'after:'.$prefix.'sales_starts_at'],
        ];
    }

    /**
     * Get the Japanese names of the ticket type attributes.
     *
     * @return array<string, string>
     */
    public static function attributes(string $prefix = ''): array
    {
        return [
            $prefix.'name' => '券種名',
            $prefix.'price' => '価格',
            $prefix.'capacity' => '定員',
            $prefix.'sales_starts_at' => '販売開始日時',
            $prefix.'sales_ends_at' => '販売終了日時',
        ];
    }
}
