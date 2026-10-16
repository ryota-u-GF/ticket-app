<flux:input wire:model="title" label="タイトル" type="text" required maxlength="100" />

<flux:textarea wire:model="description" label="説明" rows="6" required />

<flux:input wire:model="venue" label="会場" type="text" required maxlength="100" />

<div class="grid gap-6 sm:grid-cols-2">
    <flux:input wire:model="starts_at" label="開始日時" type="datetime-local" required />
    <flux:input wire:model="ends_at" label="終了日時" type="datetime-local" required />
</div>
