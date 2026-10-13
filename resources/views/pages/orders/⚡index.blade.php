<?php

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('マイチケット')] class extends Component {
    use WithPagination;

    /**
     * Get the signed-in user's orders, newest first, with the total number of tickets.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        return Order::query()
            ->whereBelongsTo(auth()->user())
            ->with('event')
            ->withSum('items', 'quantity')
            ->latest()
            ->latest('id')
            ->paginate(10);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">マイチケット</flux:heading>

    @if ($this->orders->isEmpty())
        <flux:text>購入した注文はまだありません。</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>注文日</flux:table.column>
                <flux:table.column>イベント</flux:table.column>
                <flux:table.column>開催日</flux:table.column>
                <flux:table.column align="end">枚数</flux:table.column>
                <flux:table.column align="end">合計</flux:table.column>
                <flux:table.column>状態</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->orders as $order)
                    <flux:table.row wire:key="order-{{ $order->id }}">
                        <flux:table.cell>{{ $order->created_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell>{{ $order->event->title }}</flux:table.cell>
                        <flux:table.cell>{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell align="end">{{ (int) $order->items_sum_quantity }}枚</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($order->total_amount) }}円</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$order->status->color()">{{ $order->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="sm" :href="route('orders.show', $order)" wire:navigate>詳細</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <flux:pagination :paginator="$this->orders" />
    @endif
</section>
