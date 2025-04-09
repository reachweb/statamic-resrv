@php
    $compact = $isCompact ?? false;
@endphp
@unless ($this->hiddenExtras->contains($extra->id))
<div 
    wire:key="extra-{{ $extra->id }}"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 -translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2"
    @class([
        'grid grid-cols-4 items-center',
        'my-3 lg:my-5' => ! $compact, 
        'my-2 lg:my-3' => $compact
    ])
>
    <div @class([
        'order-0', 
        'col-span-3' => ! $extra->allow_multiple || ($extra->allow_multiple && $compact), 
        'col-span-2' => $extra->allow_multiple && ! $compact
    ])>
        <div>
            <label class="inline-flex items-center cursor-pointer">
                <input 
                    type="checkbox" 
                    class="sr-only peer" 
                    wire:change.throttle="toggleExtra({{ $extra->id }})"
                    {{ $this->requiredExtras->contains($extra->id) ? 'disabled' : '' }}
                    {{ $this->isExtraSelected($extra->id) ? 'checked' : '' }}>
                    <div 
                        class="relative flex-shrink-0 w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 
                            peer-focus:ring-blue-300 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full 
                            peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] 
                            after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full 
                            after:w-5 after:h-5 after:transition-all peer-checked:bg-blue-600
                            {{ $this->requiredExtras->contains($extra->id) ? 'cursor-not-allowed' : '' }}"
                    >
                </div>
                <span class="ms-3 font-medium text-gray-900">{{ $extra->name }}</span>
                @if ($this->requiredExtras->contains($extra->id))
                <span class="ml-2 text-xs text-gray-600 uppercase">
                    {{ trans('statamic-resrv::frontend.required') }}
                </span>
                @endif
            </label>
            @if ($extra->description)
            <div class="text-sm text-gray-500 mt-1">{{ $extra->description }}</div>
            @endif
        </div>
    </div>
    @if ($extra->allow_multiple)
        <div @class(['order-2 col-span-4 justify-start' => $compact])>
        @include('statamic-resrv::livewire.components.partials.extra-quantity')
        </div>
    @endif
    <div class="flex items-center justify-end text-gray-900 col-span-1">
        <span>{{ config('resrv-config.currency_symbol') }} {{ $extra->price }}</span>
    </div>
</div>
@endunless