<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('購入の確認')] class extends Component {
    #[Locked]
    public Order $order;

    #[Locked]
    public ?string $sessionId = null;

    /**
     * Mount the component and try to confirm the payment once.
     */
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load('event');
        $this->sessionId = request()->query('session_id');

        $this->confirm();
    }

    /**
     * Ask Stripe whether the order was paid, in case the webhook has not arrived yet.
     *
     * Only a pending order whose Checkout Session id matches the one in the URL is checked.
     */
    public function confirm(): void
    {
        $this->order->refresh();
        $this->authorize('view', $this->order);

        if ($this->order->status !== OrderStatus::Pending
            || $this->sessionId === null
            || $this->sessionId !== $this->order->stripe_checkout_session_id) {
            return;
        }

        try {
            $session = app(CheckoutService::class)->retrieveSession($this->sessionId);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if ($session->payment_status === 'paid') {
            app(OrderService::class)->markAsPaid($this->order, $session->payment_intent ?? null);
        }
    }
}; ?>

<section class="w-full max-w-3xl space-y-4" @if ($order->status === OrderStatus::Pending) wire:poll.3s="confirm" @endif>
    @if ($order->isPaid())
        <flux:heading size="xl" level="1">購入が完了しました</flux:heading>
        <flux:text>チケットを{{ $order->items()->withCount('tickets')->get()->sum('tickets_count') }}枚発行しました。</flux:text>
    @elseif ($order->status === OrderStatus::Pending)
        <flux:heading size="xl" level="1">お支払いを確認中です</flux:heading>
        <flux:text>確認が終わるまで、このままお待ちください。</flux:text>
    @else
        <flux:heading size="xl" level="1">この注文は購入できていません</flux:heading>
        <flux:text>{{ $order->status->label() }}の注文です。</flux:text>
    @endif

    <flux:text>注文番号: {{ $order->id }}</flux:text>
    <flux:text>{{ $order->event->title }}</flux:text>
    <flux:text>合計: {{ number_format($order->total_amount) }}円</flux:text>

    <div class="flex gap-2">
        @if ($order->isPaid())
            @can('viewTickets', $order)
                <flux:button variant="primary" :href="route('orders.show', $order)" wire:navigate>注文詳細を見る</flux:button>
            @endcan
        @endif

        <flux:button :href="route('events.show', $order->event)" wire:navigate>イベント詳細へ戻る</flux:button>
    </div>
</section>
