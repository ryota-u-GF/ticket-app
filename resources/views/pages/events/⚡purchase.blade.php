<?php

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\OrderService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('購入内容の確認')] class extends Component {
    #[Locked]
    public Event $event;

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('purchase', $event);

        $this->event = $event;

        if ($this->quantities() === []) {
            $this->redirectRoute('events.show', $event);
        }
    }

    /**
     * Get the selected quantity per ticket type id from the session.
     *
     * @return array<int|string, mixed>
     */
    private function quantities(): array
    {
        return session("purchase.{$this->event->id}", []);
    }

    /**
     * Get the selected lines for display.
     *
     * The amounts here are for display only; OrderService decides the real ones.
     *
     * @return Collection<int, array{name: string, unit_price: int, quantity: int, subtotal: int}>
     */
    #[Computed]
    public function lines(): Collection
    {
        $quantities = $this->quantities();

        return $this->event->ticketTypes()
            ->whereKey(array_keys($quantities))
            ->orderBy('price')
            ->get()
            ->map(fn (TicketType $ticketType) => [
                'name' => $ticketType->name,
                'unit_price' => $ticketType->price,
                'quantity' => (int) $quantities[$ticketType->id],
                'subtotal' => $ticketType->price * (int) $quantities[$ticketType->id],
            ]);
    }

    /**
     * Get the total amount for display.
     */
    #[Computed]
    public function total(): int
    {
        return $this->lines->sum('subtotal');
    }

    /**
     * Create the order and go to the completion screen.
     */
    public function pay(OrderService $orderService): void
    {
        $this->authorize('purchase', $this->event);

        try {
            $order = $orderService->createOrder(auth()->user(), $this->event, $this->quantities());
        } catch (PurchaseException $e) {
            $this->addError('purchase', $e->getMessage());

            return;
        }

        session()->forget("purchase.{$this->event->id}");

        $this->redirectRoute('orders.complete', $order);
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <flux:heading size="xl" level="1">購入内容の確認</flux:heading>
    <flux:text>{{ $event->title }}</flux:text>

    @error('purchase')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">単価</flux:table.column>
            <flux:table.column align="end">枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->lines as $line)
                <flux:table.row :key="$line['name']">
                    <flux:table.cell variant="strong">{{ $line['name'] }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($line['unit_price']) }}円</flux:table.cell>
                    <flux:table.cell align="end">{{ $line['quantity'] }}枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($line['subtotal']) }}円</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="flex justify-end">
        <flux:heading size="lg">合計 {{ number_format($this->total) }}円</flux:heading>
    </div>

    <div class="flex justify-between">
        <flux:button :href="route('events.show', $event)" wire:navigate>戻る</flux:button>
        <flux:button variant="primary" wire:click="pay" wire:loading.attr="disabled">支払いへ進む</flux:button>
    </div>
</section>
