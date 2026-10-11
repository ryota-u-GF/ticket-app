<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\EventRules;
use App\Support\TicketTypeRules;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
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

    #[Locked]
    public ?int $editingTicketTypeId = null;
    public string $ticket_name = '';
    public int|string $ticket_price = '';
    public int|string $ticket_capacity = '';
    public string $ticket_sales_starts_at = '';
    public string $ticket_sales_ends_at = '';

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

        $published = DB::transaction(function (): bool {
            $event = Event::query()->lockForUpdate()->findOrFail($this->event->id);

            if (! $event->ticketTypes()->exists()) {
                return false;
            }

            $event->update(['status' => EventStatus::Published]);

            return true;
        });

        if (! $published) {
            Flux::toast(variant: 'danger', text: '券種を1つ以上登録してから公開してください。');

            return;
        }

        $this->event->refresh();

        Flux::toast(variant: 'success', text: '公開しました。');
    }

    /**
     * Delete the event.
     */
    public function deleteEvent(): void
    {
        $this->authorize('delete', $this->event);

        if ($this->event->orders()->exists()) {
            Flux::toast(variant: 'danger', text: '注文があるため削除できません。');

            return;
        }

        $this->event->delete();

        Flux::toast(variant: 'success', text: 'イベントを削除しました。');

        $this->redirectRoute('organizer.events.index', navigate: true);
    }

    /**
     * Get the ticket types of the event.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->orderBy('id')->get();
    }

    /**
     * Fill the ticket type form with an existing ticket type.
     */
    public function editTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        $this->resetValidation();

        $this->editingTicketTypeId = $ticketType->id;
        $this->ticket_name = $ticketType->name;
        $this->ticket_price = $ticketType->price;
        $this->ticket_capacity = $ticketType->capacity;
        $this->ticket_sales_starts_at = $ticketType->sales_starts_at->format('Y-m-d\TH:i');
        $this->ticket_sales_ends_at = $ticketType->sales_ends_at->format('Y-m-d\TH:i');
    }

    /**
     * Create a ticket type, or update the one being edited.
     */
    public function saveTicketType(): void
    {
        $this->authorize('update', $this->event);

        $this->validate(TicketTypeRules::rules('ticket_'), [], TicketTypeRules::attributes('ticket_'));

        $attributes = [
            'name' => $this->ticket_name,
            'price' => (int) $this->ticket_price,
            'capacity' => (int) $this->ticket_capacity,
            'sales_starts_at' => $this->ticket_sales_starts_at,
            'sales_ends_at' => $this->ticket_sales_ends_at,
        ];

        if ($this->editingTicketTypeId !== null) {
            $ticketType = $this->event->ticketTypes()->find($this->editingTicketTypeId);

            if ($ticketType === null) {
                $this->resetTicketTypeForm();

                Flux::toast(variant: 'danger', text: 'この券種はすでに削除されています。');

                return;
            }

            $ticketType->update($attributes);

            Flux::toast(variant: 'success', text: '券種を更新しました。');
        } else {
            $this->event->ticketTypes()->create($attributes);

            Flux::toast(variant: 'success', text: '券種を追加しました。');
        }

        $this->resetTicketTypeForm();
    }

    /**
     * Stop editing and clear the ticket type form.
     */
    public function resetTicketTypeForm(): void
    {
        $this->reset([
            'editingTicketTypeId',
            'ticket_name',
            'ticket_price',
            'ticket_capacity',
            'ticket_sales_starts_at',
            'ticket_sales_ends_at',
        ]);

        $this->resetValidation();
    }

    /**
     * Delete a ticket type of the event.
     */
    public function deleteTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $errorMessage = DB::transaction(function () use ($ticketTypeId): ?string {
            $event = Event::query()->lockForUpdate()->findOrFail($this->event->id);
            $ticketType = $event->ticketTypes()->findOrFail($ticketTypeId);

            if ($ticketType->orderItems()->exists()) {
                return '注文があるため削除できません。';
            }

            if ($event->isPublished() && $event->ticketTypes()->count() === 1) {
                return '公開中のイベントの券種をすべて削除することはできません。';
            }

            $ticketType->delete();

            return null;
        });

        if ($errorMessage !== null) {
            Flux::toast(variant: 'danger', text: $errorMessage);

            return;
        }

        if ($this->editingTicketTypeId === $ticketTypeId) {
            $this->resetTicketTypeForm();
        }

        Flux::toast(variant: 'success', text: '券種を削除しました。');
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
        <flux:heading size="lg">券種</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種がありません。公開するには1つ以上登録してください。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種名</flux:table.column>
                    <flux:table.column align="end">価格</flux:table.column>
                    <flux:table.column align="end">定員</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($ticketType->price) }}円</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($ticketType->capacity) }}枚</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $ticketType->sales_starts_at->isoFormat('M月D日(ddd) HH:mm') }}<br>
                                〜 {{ $ticketType->sales_ends_at->isoFormat('M月D日(ddd) HH:mm') }}
                            </flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap">
                                <flux:button size="sm" icon="pencil-square" wire:click="editTicketType({{ $ticketType->id }})">編集</flux:button>
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    icon="trash"
                                    wire:click="deleteTicketType({{ $ticketType->id }})"
                                    wire:confirm="「{{ $ticketType->name }}」を削除しますか？"
                                >削除</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        <form wire:submit="saveTicketType" class="space-y-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading>{{ $editingTicketTypeId === null ? '券種を追加' : '券種を編集' }}</flux:heading>

            <flux:input wire:model="ticket_name" label="券種名" type="text" required maxlength="50" placeholder="一般、学生 など" />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="ticket_price" label="価格（円）" type="number" min="1" max="1000000" step="1" required />
                <flux:input wire:model="ticket_capacity" label="定員（枚）" type="number" min="1" max="100000" step="1" required />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="ticket_sales_starts_at" label="販売開始日時" type="datetime-local" required />
                <flux:input wire:model="ticket_sales_ends_at" label="販売終了日時" type="datetime-local" required />
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">
                    {{ $editingTicketTypeId === null ? '追加' : '更新' }}
                </flux:button>

                @if ($editingTicketTypeId !== null)
                    <flux:button variant="ghost" wire:click="resetTicketTypeForm">キャンセル</flux:button>
                @endif
            </div>
        </form>
    </div>

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
