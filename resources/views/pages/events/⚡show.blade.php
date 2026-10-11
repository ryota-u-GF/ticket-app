<?php

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント詳細')] class extends Component {
    #[Locked]
    public Event $event;

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('view', $event);

        $this->event = $event->load('organizer');
    }

    /**
     * Get the ticket types of the event.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->orderBy('price')->get();
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div class="space-y-3">
        @unless ($event->isPublished())
            <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
        @endunless

        <flux:heading size="xl" level="1">{{ $event->title }}</flux:heading>

        <div class="space-y-1">
            <flux:text class="flex items-center gap-1.5">
                <flux:icon.clock variant="micro" />
                {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }} 〜 {{ $event->ends_at->isoFormat('M月D日(ddd) HH:mm') }}
            </flux:text>
            <flux:text class="flex items-center gap-1.5">
                <flux:icon.map-pin variant="micro" />
                {{ $event->venue }}
            </flux:text>
            <flux:text class="flex items-center gap-1.5">
                <flux:icon.user variant="micro" />
                主催: {{ $event->organizer->name }}
            </flux:text>
        </div>
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">イベントの説明</flux:heading>
        <flux:text>{!! nl2br(e($event->description)) !!}</flux:text>
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">券種</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種はまだありません。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column align="end">価格</flux:table.column>
                    <flux:table.column align="end">残り</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        @php($remainingCount = $ticketType->remainingCount())
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                            <flux:table.cell align="end">
                                {{ $ticketType->price === 0 ? '無料' : number_format($ticketType->price).'円' }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($remainingCount === 0)
                                    <flux:badge size="sm" color="red">売り切れ</flux:badge>
                                @else
                                    {{ number_format($remainingCount) }}枚
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $ticketType->sales_starts_at->isoFormat('M月D日(ddd) HH:mm') }}<br>
                                〜 {{ $ticketType->sales_ends_at->isoFormat('M月D日(ddd) HH:mm') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
