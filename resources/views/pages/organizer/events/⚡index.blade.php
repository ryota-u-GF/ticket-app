<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント管理')] class extends Component {
    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Event::class);
    }

    /**
     * Get the events organized by the current user.
     *
     * @return Collection<int, Event>
     */
    #[Computed]
    public function events(): Collection
    {
        return Event::query()
            ->where('organizer_id', Auth::id())
            ->withSum(['orderItems as sales_amount' => fn ($query) => $query->where('orders.status', OrderStatus::Paid)], DB::raw('order_items.quantity * order_items.unit_price'))
            ->withSum(['orderItems as sold_quantity' => fn ($query) => $query->where('orders.status', OrderStatus::Paid)], 'quantity')
            ->orderByDesc('starts_at')
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">イベント管理</flux:heading>

        <flux:button variant="primary" icon="plus" :href="route('organizer.events.create')" wire:navigate>
            イベントを登録
        </flux:button>
    </div>

    @if ($this->events->isEmpty())
        <flux:text>まだイベントがありません。</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>タイトル</flux:table.column>
                <flux:table.column>開催日</flux:table.column>
                <flux:table.column>状態</flux:table.column>
                <flux:table.column align="end">販売枚数</flux:table.column>
                <flux:table.column align="end">売上</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->events as $event)
                    <flux:table.row :key="$event->id">
                        <flux:table.cell variant="strong">{{ $event->title }}</flux:table.cell>
                        <flux:table.cell>{{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((int) $event->sold_quantity) }}枚</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((int) $event->sales_amount) }}円</flux:table.cell>
                        <flux:table.cell align="end" class="space-x-1">
                            <flux:button size="sm" icon="chart-bar" :href="route('organizer.events.orders', $event)" wire:navigate>
                                売上
                            </flux:button>
                            <flux:button size="sm" icon="pencil-square" :href="route('organizer.events.edit', $event)" wire:navigate>
                                編集
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
