<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Minutes during which a pending order still reserves its tickets.
     */
    public const PENDING_MINUTES = 15;

    /**
     * The maximum number of tickets per ticket type in one order.
     */
    public const MAX_QUANTITY = 10;

    /**
     * Create a pending order with its items.
     *
     * The amounts are calculated here from the ticket type prices, never passed in.
     * The user's earlier pending orders for the same event are cancelled.
     *
     * @param  array<int|string, mixed>  $quantities  Quantity per ticket type id.
     *
     * @throws PurchaseException
     */
    public function createOrder(User $user, Event $event, array $quantities): Order
    {
        if (! $event->isPublished()) {
            throw new PurchaseException('このイベントは購入できません。');
        }

        $quantities = $this->selectedQuantities($quantities);
        $ticketTypes = $this->ticketTypesOf($event, array_keys($quantities));

        try {
            return Cache::lock("create-order:{$user->id}:{$event->id}", 10)
                ->block(5, fn (): Order => $this->placeOrder($user, $event, $quantities, $ticketTypes));
        } catch (LockTimeoutException) {
            throw new PurchaseException('注文が混み合っています。しばらくしてからもう一度お試しください。');
        }
    }

    /**
     * Cancel the user's earlier pending orders for the event, then validate and create the new one.
     *
     * Keeping only one pending order per user and event stops repeated submits from holding tickets.
     * Everything runs in one transaction, so a failed validation leaves the earlier orders untouched.
     *
     * @param  array<int, int>  $quantities
     * @param  Collection<int, TicketType>  $ticketTypes
     *
     * @throws PurchaseException
     */
    private function placeOrder(User $user, Event $event, array $quantities, Collection $ticketTypes): Order
    {
        return DB::transaction(function () use ($user, $event, $quantities, $ticketTypes): Order {
            Order::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($event)
                ->where('status', OrderStatus::Pending)
                ->update(['status' => OrderStatus::Cancelled]);

            foreach ($ticketTypes as $ticketType) {
                $this->assertPurchasable($ticketType, $quantities[$ticketType->id]);
            }

            $order = new Order([
                'event_id' => $event->id,
                'status' => OrderStatus::Pending,
                'total_amount' => $ticketTypes->sum(fn (TicketType $ticketType) => $ticketType->price * $quantities[$ticketType->id]),
            ]);
            $order->user()->associate($user);
            $order->save();

            foreach ($ticketTypes as $ticketType) {
                $order->items()->create([
                    'ticket_type_id' => $ticketType->id,
                    'quantity' => $quantities[$ticketType->id],
                    'unit_price' => $ticketType->price,
                ]);
            }

            return $order->load('items');
        });
    }

    /**
     * Drop unselected (zero) entries and validate the rest.
     *
     * @param  array<int|string, mixed>  $quantities
     * @return array<int, int>
     *
     * @throws PurchaseException
     */
    private function selectedQuantities(array $quantities): array
    {
        $selected = [];

        foreach ($quantities as $ticketTypeId => $quantity) {
            if ($quantity === 0 || $quantity === '0') {
                continue;
            }

            if (filter_var($quantity, FILTER_VALIDATE_INT) === false) {
                throw new PurchaseException('枚数が正しくありません。');
            }

            $quantity = (int) $quantity;

            if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw new PurchaseException('枚数は1枚以上'.self::MAX_QUANTITY.'枚以下で選んでください。');
            }

            $selected[(int) $ticketTypeId] = $quantity;
        }

        if ($selected === []) {
            throw new PurchaseException('チケットを選択してください。');
        }

        return $selected;
    }

    /**
     * Load the ticket types, making sure they all belong to the event.
     *
     * @param  list<int>  $ticketTypeIds
     * @return Collection<int, TicketType>
     *
     * @throws PurchaseException
     */
    private function ticketTypesOf(Event $event, array $ticketTypeIds): Collection
    {
        $ticketTypes = $event->ticketTypes()->whereKey($ticketTypeIds)->get();

        if ($ticketTypes->count() !== count($ticketTypeIds)) {
            throw new PurchaseException('選択された券種が見つかりません。');
        }

        return $ticketTypes;
    }

    /**
     * @throws PurchaseException
     */
    private function assertPurchasable(TicketType $ticketType, int $quantity): void
    {
        if (! $ticketType->isOnSale()) {
            throw new PurchaseException("「{$ticketType->name}」は販売期間外です。");
        }

        $remainingCount = $ticketType->remainingCount();

        if ($quantity > $remainingCount) {
            throw new PurchaseException("「{$ticketType->name}」の残りは{$remainingCount}枚です。");
        }
    }
}
