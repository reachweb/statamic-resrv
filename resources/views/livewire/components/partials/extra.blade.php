<div 
    wire:key="extra-{{ $extra->id }}"
    wire:loading.class="opacity-50 pointer-events-none"
    @class([
        'grid grid-cols-4 my-3 lg:my-5 items-center transition-opacity duration-150',
        'hidden' => $this->hiddenExtras->contains($extra->id)
    ])
>
    <div @class([
        'col-span-3' => ! $extra->allow_multiple, 
        'col-span-2' => $extra->allow_multiple ,
    ])>
        <div class="flex flex-col justify-center items-start">
            <label class="inline-flex items-center cursor-pointer">
                <input 
                    type="checkbox" 
                    class="sr-only peer" 
                    wire:change.throttle="toggleExtra({{ $extra->id }})"
                    {{ $this->requiredExtras->contains($extra->id) ? 'disabled' : '' }}
                    {{ $this->isExtraSelected($extra->id) ? 'checked' : '' }}>
                    <div 
                        class="relative flex-shrink-0 w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 
                        peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full 
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
        <div>
        @include('statamic-resrv::livewire.components.partials.extra-quantity')
        </div>
    @endif
    <div class="flex items-center justify-end text-gray-900 col-span-1">
        <span>{{ config('resrv-config.currency_symbol') }} {{ $extra->price }}</span>
    </div>
</div>
