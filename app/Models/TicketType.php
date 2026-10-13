<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\OrderService;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $name
 * @property int $price
 * @property int $capacity
 * @property Carbon $sales_starts_at
 * @property Carbon $sales_ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'price', 'capacity', 'sales_starts_at', 'sales_ends_at'])]
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'capacity' => 'integer',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
        ];
    }

    /**
     * Get the event that the ticket type belongs to.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the order items for the ticket type.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Determine if the ticket type is within its sales period.
     *
     * Both the start and end times are inclusive.
     */
    public function isOnSale(): bool
    {
        return now()->between($this->sales_starts_at, $this->sales_ends_at);
    }

    /**
     * Get the number of tickets that are reserved.
     *
     * Paid orders and pending orders created within the last PENDING_MINUTES are reserved.
     */
    public function reservedCount(): int
    {
        return (int) $this->orderItems()
            ->whereHas('order', function (Builder $query) {
                $query->where('status', OrderStatus::Paid)
                    ->orWhere(function (Builder $query) {
                        $query->where('status', OrderStatus::Pending)
                            ->where('created_at', '>=', now()->subMinutes(OrderService::PENDING_MINUTES));
                    });
            })
            ->sum('quantity');
    }

    /**
     * Get the number of tickets that can still be ordered.
     */
    public function remainingCount(): int
    {
        return max(0, $this->capacity - $this->reservedCount());
    }
}
