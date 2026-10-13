<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('売上')] class extends Component {
    use WithPagination;

    #[Locked]
    public Event $event;

    #[Locked]
    public ?int $detailOrderId = null;

    /**
     * Mount the component after checking that the user may view the sales.
     */
    public function mount(Event $event): void
    {
        $this->authorize('viewOrders', $event);

        $this->event = $event;
    }

    /**
     * Check the permission again on every later request, because mount() runs only once.
     */
    public function hydrate(): void
    {
        $this->authorize('viewOrders', $this->event);
    }

    /**
     * Open the detail modal of a paid order of this event.
     */
    public function showDetail(int $orderId): void
    {
        $this->authorize('viewOrders', $this->event);

        $this->detailOrderId = $this->paidOrders()->findOrFail($orderId)->id;

        Flux::modal('order-detail')->show();
    }

    /**
     * Get the paid orders of the event.
     *
     * @return Builder<Order>
     */
    private function paidOrders(): Builder
    {
        return Order::query()
            ->where('event_id', $this->event->id)
            ->where('status', OrderStatus::Paid);
    }

    /**
     * Get the paid orders, newest payment first.
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return $this->paidOrders()
            ->with(['user', 'items.ticketType'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(10);
    }

    /**
     * Get the order shown in the detail modal.
     */
    #[Computed]
    public function detailOrder(): ?Order
    {
        if ($this->detailOrderId === null) {
            return null;
        }

        return $this->paidOrders()->with(['user', 'items.ticketType'])->find($this->detailOrderId);
    }

    /**
     * Get each ticket type with its sold quantity and sales amount.
     *
     * @return Collection<int, array{name: string, capacity: int, sold: int, amount: int}>
     */
    #[Computed]
    public function ticketTypeSales(): Collection
    {
        $sales = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $this->event->id)
            ->where('orders.status', OrderStatus::Paid->value)
            ->groupBy('order_items.ticket_type_id')
            ->selectRaw('order_items.ticket_type_id, SUM(order_items.quantity) as sold, SUM(order_items.quantity * order_items.unit_price) as amount')
            ->get()
            ->keyBy('ticket_type_id');

        return $this->event->ticketTypes()->orderBy('id')->get()->map(fn (TicketType $ticketType) => [
            'name' => $ticketType->name,
            'capacity' => $ticketType->capacity,
            'sold' => (int) ($sales[$ticketType->id]->sold ?? 0),
            'amount' => (int) ($sales[$ticketType->id]->amount ?? 0),
        ]);
    }

    /**
     * Get the totals of the event.
     *
     * @return array{amount: int, quantity: int, orders: int}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'amount' => $this->event->salesAmount(),
            'quantity' => $this->event->soldQuantity(),
            'orders' => $this->paidOrders()->count(),
        ];
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1">売上</flux:heading>
            <flux:text>{{ $event->title }}</flux:text>
        </div>

        @can('create', Event::class)
            <flux:button icon="arrow-left" :href="route('organizer.events.index')" wire:navigate>イベント管理へ戻る</flux:button>
        @else
            <flux:button icon="arrow-left" :href="route('events.show', $event)" wire:navigate>イベントへ戻る</flux:button>
        @endcan
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>売上合計</flux:text>
            <flux:heading size="xl">{{ number_format($this->totals['amount']) }}円</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>販売枚数</flux:text>
            <flux:heading size="xl">{{ number_format($this->totals['quantity']) }}枚</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text>注文数</flux:text>
            <flux:heading size="xl">{{ number_format($this->totals['orders']) }}件</flux:heading>
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">券種ごとの販売状況</flux:heading>

        @if ($this->ticketTypeSales->isEmpty())
            <flux:text>券種がまだありません。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column align="end">販売枚数 / 定員</flux:table.column>
                    <flux:table.column align="end">売上</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypeSales as $row)
                        <flux:table.row wire:key="type-{{ $loop->index }}">
                            <flux:table.cell variant="strong">{{ $row['name'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['sold']) }}枚 / {{ number_format($row['capacity']) }}枚</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['amount']) }}円</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">支払い済みの注文</flux:heading>

        @if ($this->orders->isEmpty())
            <flux:text>支払い済みの注文はまだありません。</flux:text>
        @else
            <flux:table :paginate="$this->orders">
                <flux:table.columns>
                    <flux:table.column>支払い日時</flux:table.column>
                    <flux:table.column>購入者</flux:table.column>
                    <flux:table.column>内容</flux:table.column>
                    <flux:table.column align="end">合計</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->orders as $order)
                        <flux:table.row :key="$order->id">
                            <flux:table.cell>{{ $order->paid_at?->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                            <flux:table.cell>
                                <div>{{ $order->user->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $order->user->email }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $order->items->map(fn ($item) => $item->ticketType->name.'×'.$item->quantity)->implode('、') }}
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($order->total_amount) }}円</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" wire:click="showDetail({{ $order->id }})">詳細</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <flux:modal name="order-detail" class="w-full max-w-lg">
        @if ($this->detailOrder)
            <div class="space-y-4">
                <flux:heading size="lg">注文詳細</flux:heading>

                <div class="space-y-1">
                    <flux:text>注文番号: {{ $this->detailOrder->id }}</flux:text>
                    <flux:text>支払い日時: {{ $this->detailOrder->paid_at?->isoFormat('M月D日(ddd) HH:mm') }}</flux:text>
                    <flux:text>購入者: {{ $this->detailOrder->user->name }}（{{ $this->detailOrder->user->email }}）</flux:text>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>券種</flux:table.column>
                        <flux:table.column align="end">単価</flux:table.column>
                        <flux:table.column align="end">枚数</flux:table.column>
                        <flux:table.column align="end">小計</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->detailOrder->items as $item)
                            <flux:table.row wire:key="detail-{{ $item->id }}">
                                <flux:table.cell>{{ $item->ticketType->name }}</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($item->unit_price) }}円</flux:table.cell>
                                <flux:table.cell align="end">{{ $item->quantity }}枚</flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($item->unit_price * $item->quantity) }}円</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                <flux:heading class="text-right">合計: {{ number_format($this->detailOrder->total_amount) }}円</flux:heading>
            </div>
        @endif
    </flux:modal>
</section>
