<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Support\EventRules;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント編集')] class extends Component {
    #[Locked]
    public Event $event;

    public string $title = '';
    public string $description = '';
    public string $venue = '';
    public string $starts_at = '';
    public string $ends_at = '';

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('update', $event);

        $this->event = $event;
        $this->title = $event->title;
        $this->description = $event->description;
        $this->venue = $event->venue;
        $this->starts_at = $event->starts_at->format('Y-m-d\TH:i');
        $this->ends_at = $event->ends_at->format('Y-m-d\TH:i');
    }

    /**
     * Update the event details.
     */
    public function save(): void
    {
        $this->authorize('update', $this->event);

        $validated = $this->validate(EventRules::rules(), [], EventRules::attributes());

        $this->event->update($validated);

        Flux::toast(variant: 'success', text: '保存しました。');
    }

    /**
     * Switch the event between draft and published.
     */
    public function togglePublish(): void
    {
        if ($this->event->isPublished()) {
            $this->authorize('update', $this->event);

            $this->event->update(['status' => EventStatus::Draft]);

            Flux::toast(variant: 'success', text: '下書きに戻しました。');

            return;
        }

        $this->authorize('publish', $this->event);

        $this->event->update(['status' => EventStatus::Published]);

        Flux::toast(variant: 'success', text: '公開しました。');
    }

    /**
     * Delete the event.
     */
    public function deleteEvent(): void
    {
        $this->authorize('delete', $this->event);

        $this->event->delete();

        Flux::toast(variant: 'success', text: 'イベントを削除しました。');

        $this->redirectRoute('organizer.events.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:heading size="xl" level="1">イベント編集</flux:heading>
            <flux:badge :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
        </div>

        @if ($event->isPublished())
            <flux:button wire:click="togglePublish" icon="eye-slash">下書きに戻す</flux:button>
        @elseif ($event->status === EventStatus::Draft)
            <flux:button variant="primary" wire:click="togglePublish" icon="eye">公開する</flux:button>
        @endif
    </div>

    @if ($event->status === EventStatus::Unpublished)
        <flux:callout variant="danger" icon="exclamation-triangle" heading="このイベントは管理者によって非公開にされています。主催者は公開に戻せません。" />
    @endif

    <form wire:submit="save" class="space-y-6">
        @include('partials.event-form-fields')

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit">保存</flux:button>
            <flux:button variant="ghost" :href="route('organizer.events.index')" wire:navigate>一覧に戻る</flux:button>
        </div>
    </form>

    <flux:separator />

    <div class="space-y-4">
        <flux:heading size="lg">イベントの削除</flux:heading>
        <flux:text>削除すると、券種もあわせて削除されます。元に戻せません。</flux:text>

        <flux:modal.trigger name="confirm-event-deletion">
            <flux:button variant="danger">イベントを削除</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="confirm-event-deletion" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">このイベントを削除しますか？</flux:heading>
                <flux:subheading>「{{ $event->title }}」を削除します。この操作は取り消せません。</flux:subheading>
            </div>

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">キャンセル</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteEvent">削除する</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
