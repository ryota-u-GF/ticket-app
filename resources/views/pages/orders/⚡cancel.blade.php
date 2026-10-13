<?php

use App\Models\Order;
use App\Services\OrderService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('お支払いを中断しました')] class extends Component {
    #[Locked]
    public Order $order;

    #[Locked]
    public bool $cancelled = false;

    /**
     * Mount the component and cancel the order if it is still pending.
     */
    public function mount(Order $order, OrderService $orderService): void
    {
        $this->authorize('cancel', $order);

        $this->cancelled = $orderService->cancelOrder($order);
        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-3xl space-y-4">
    @if ($cancelled)
        <flux:heading size="xl" level="1">お支払いを中断しました</flux:heading>
        <flux:text>注文をキャンセルし、確保していたチケットを戻しました。</flux:text>
    @else
        <flux:heading size="xl" level="1">この注文はキャンセルできません</flux:heading>
        <flux:text>すでに支払い済み、またはキャンセル済みの注文です。</flux:text>
    @endif

    <flux:text>{{ $order->event->title }}</flux:text>

    <flux:button :href="route('events.show', $order->event)" wire:navigate>イベント詳細へ戻る</flux:button>
</section>
