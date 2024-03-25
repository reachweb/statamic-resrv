<div class="relative flex items-center gap-x-8">

    <x-resrv::availability-dates
        :$calendar
        :errors="$errors"
    />

    @if ($advanced)
    <x-resrv::availability-advanced
        wire:model.live="data.advanced"
        :advancedProperties="$this->advancedProperties"
        :errors="$errors"
    />
    @endif

    @if ($enableQuantity)
    <x-resrv::availability-quantity
        :maxQuantity="$this->maxQuantity"
        :errors="$errors"
    />
    @endif

    @if ($live === false)
    <x-resrv::availability-button />
    @endif
</div>
