@php
    $quantity = $this->getExtraQuantity($extra->id);
@endphp

<div @class([
    'flex items-center',
    'justify-center' => ! $compact,
    'mt-2' => $compact,
    'hidden' => ! $this->isExtraSelected($extra->id),
])>
    <div class="max-w-xs mx-auto flex flex-col lg:flex-row items-center">
        <label for="counter-input" class="block mb-2 lg:mb-0 lg:mr-3 text-sm font-medium text-gray-900">{{ trans('statamic-resrv::frontend.quantity') }}</label>
        <div class="relative flex items-center">
            <button 
                type="button"
                class="flex-shrink-0 bg-gray-100 hover:bg-gray-200 inline-flex items-center justify-center border border-gray-300 rounded-md 
                h-5 w-5 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                wire:click.throttle="updateExtraQuantity({{ $extra->id }}, {{ $quantity - 1 }})"
                @if ($quantity <= 1)disabled @endif
            >
                <svg class="w-2.5 h-2.5 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 2">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 1h16"/>
                </svg>
            </button>
            <input 
                type="text" 
                id="counter-input" 
                class="flex-shrink-0 text-gray-900 border-0 bg-transparent text-sm font-normal focus:outline-none focus:ring-0 max-w-[2.5rem] text-center" 
                placeholder="" 
                value="{{ $quantity }}" 
                required
            />
            <button 
                type="button"
                class="flex-shrink-0 bg-gray-100 hover:bg-gray-200 inline-flex items-center justify-center border border-gray-300 rounded-md 
                h-5 w-5 focus:ring-gray-100 focus:ring-2 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                wire:click.throttle="updateExtraQuantity({{ $extra->id }}, {{ $quantity + 1 }})"
                @if ($extra->maximum && $quantity >= $extra->maximum) disabled @endif
            >
                <svg class="w-2.5 h-2.5 text-gray-900" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 18 18">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 1v16M1 9h16"/>
                </svg>
            </button>
        </div>
    </div>
</div>