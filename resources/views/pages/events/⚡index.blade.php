<?php

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('イベント一覧')] class extends Component {
    use WithPagination;

    /**
     * Get the published events that have not ended yet, with their lowest ticket price.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        return Event::query()
            ->published()
            ->upcoming()
            ->withMin('ticketTypes', 'price')
            ->paginate(12);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">イベント一覧</flux:heading>

    @if ($this->events->isEmpty())
        <flux:text>公開中のイベントはまだありません。</flux:text>
    @else
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->events as $event)
                <a
                    href="{{ route('events.show', $event) }}"
                    wire:navigate
                    wire:key="event-{{ $event->id }}"
                    class="group overflow-hidden rounded-xl border border-zinc-200 bg-white transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <div class="flex aspect-video items-center justify-center bg-zinc-100 dark:bg-zinc-800">
                        <flux:icon.calendar-days class="size-10 text-zinc-400" />
                    </div>

                    <div class="space-y-2 p-4">
                        <flux:heading size="lg" class="line-clamp-2 group-hover:underline">{{ $event->title }}</flux:heading>

                        <flux:text class="flex items-center gap-1.5">
                            <flux:icon.clock variant="micro" />
                            {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                        </flux:text>

                        <flux:text class="flex items-center gap-1.5">
                            <flux:icon.map-pin variant="micro" />
                            {{ $event->venue }}
                        </flux:text>

                        <p class="pt-1 font-semibold text-zinc-800 dark:text-white">
                            @if ($event->ticket_types_min_price === null)
                                価格未定
                            @elseif ((int) $event->ticket_types_min_price === 0)
                                無料
                            @else
                                {{ number_format($event->ticket_types_min_price) }}円〜
                            @endif
                        </p>
                    </div>
                </a>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->events" />
    @endif
</section>
