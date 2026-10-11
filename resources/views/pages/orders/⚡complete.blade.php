<?php

use App\Models\Order;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Placeholder screen: payment is implemented later (weekday assignment).
 */
new #[Title('注文を受け付けました')] class extends Component {
    #[Locked]
    public Order $order;

    /**
     * Mount the component.
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-3xl space-y-4">
    <flux:heading size="xl" level="1">注文を受け付けました</flux:heading>

    <flux:text>注文番号: {{ $order->id }}</flux:text>
    <flux:text>{{ $order->event->title }}</flux:text>
    <flux:text>合計: {{ number_format($order->total_amount) }}円</flux:text>

    <flux:button :href="route('events.show', $order->event)" wire:navigate>イベント詳細へ戻る</flux:button>
</section>
