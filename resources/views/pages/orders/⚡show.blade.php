<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文詳細')] class extends Component {
    #[Locked]
    public Order $order;

    /**
     * Mount the component after checking that the user may view the order.
     */
    public function mount(Order $order): void
    {
        $this->authorize('viewTickets', $order);

        $this->order = $order->load(['event', 'items.ticketType', 'items.tickets']);
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div class="flex items-center gap-3">
        <flux:heading size="xl" level="1">注文詳細</flux:heading>
        <flux:badge :color="$order->status->color()">{{ $order->status->label() }}</flux:badge>
    </div>

    @if ($order->status === OrderStatus::Pending)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="支払いが完了していません" />
    @endif

    <div class="space-y-1">
        <flux:heading size="lg">{{ $order->event->title }}</flux:heading>
        <flux:text>{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:text>
        <flux:text>{{ $order->event->venue }}</flux:text>
        <flux:text>注文番号: {{ $order->id }}／注文日: {{ $order->created_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:text>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">単価</flux:table.column>
            <flux:table.column align="end">枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($order->items as $item)
                <flux:table.row wire:key="item-{{ $item->id }}">
                    <flux:table.cell>{{ $item->ticketType->name }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($item->unit_price) }}円</flux:table.cell>
                    <flux:table.cell align="end">{{ $item->quantity }}枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($item->unit_price * $item->quantity) }}円</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:heading class="text-right">合計: {{ number_format($order->total_amount) }}円</flux:heading>

    @if ($order->isPaid())
        <div class="space-y-3">
            <flux:heading size="lg">チケット</flux:heading>

            <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($order->items as $item)
                    @foreach ($item->tickets as $ticket)
                        <li class="flex items-center justify-between gap-4 p-3" wire:key="ticket-{{ $ticket->id }}">
                            <span>{{ $item->ticketType->name }}</span>
                            <code class="font-mono text-sm">{{ $ticket->code }}</code>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif

    <flux:button :href="route('orders.index')" wire:navigate>注文一覧へ戻る</flux:button>
</section>
