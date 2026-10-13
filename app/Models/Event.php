<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $organizer_id
 * @property string $title
 * @property string $description
 * @property string $venue
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property EventStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'description', 'venue', 'starts_at', 'ends_at', 'status'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    /**
     * Get the organizer who owns the event.
     *
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * Get the ticket types of the event.
     *
     * @return HasMany<TicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /**
     * Get the orders for the event.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the order items of the event through its orders.
     *
     * @return HasManyThrough<OrderItem, Order, $this>
     */
    public function orderItems(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, Order::class);
    }

    /**
     * Scope a query to only include published events.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    /**
     * Scope a query to only include events that have not ended yet, nearest start first.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('ends_at', '>', now())->orderBy('starts_at')->orderBy('id');
    }

    /**
     * Get the number of tickets sold, counting paid orders only.
     */
    public function soldQuantity(): int
    {
        return (int) OrderItem::query()
            ->whereHas('order', fn (Builder $query) => $query
                ->where('event_id', $this->id)
                ->where('status', OrderStatus::Paid))
            ->sum('quantity');
    }

    /**
     * Get the sales amount in yen: the sum of unit_price times quantity of the items of paid orders.
     */
    public function salesAmount(): int
    {
        return (int) $this->orderItems()
            ->where('orders.status', OrderStatus::Paid)
            ->sum(DB::raw('order_items.quantity * order_items.unit_price'));
    }

    /**
     * Determine if the event is published.
     */
    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }
}
