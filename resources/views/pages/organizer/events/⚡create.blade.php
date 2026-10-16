<?php

use App\Models\Event;
use App\Support\EventRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント登録')] class extends Component {
    public string $title = '';
    public string $description = '';
    public string $venue = '';
    public string $starts_at = '';
    public string $ends_at = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Event::class);
    }

    /**
     * Store the event as a draft.
     */
    public function save(): void
    {
        $this->authorize('create', Event::class);

        $validated = $this->validate(EventRules::rules(), [], EventRules::attributes());

        $event = new Event($validated);
        $event->organizer()->associate(Auth::user());
        $event->save();

        Flux::toast(variant: 'success', text: '下書きとして保存しました。');

        $this->redirectRoute('organizer.events.edit', $event, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">イベント登録</flux:heading>

    <form wire:submit="save" class="space-y-6">
        @include('partials.event-form-fields')

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit">下書きとして保存</flux:button>
            <flux:button variant="ghost" :href="route('organizer.events.index')" wire:navigate>キャンセル</flux:button>
        </div>
    </form>
</section>
