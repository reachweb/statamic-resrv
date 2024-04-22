<div
    x-data="{
        coupon: $wire.coupon, 
        open: false, 
        applied: false,
        errors: false,
        toggle() {
            this.open = ! this.open;
        },
    }" 
    x-init="
        $wire.coupon !== null ? applied = true : applied = false;
        $watch('open', (value) => {
            if (open === false && ! applied) {
                coupon = null;
            }
        });
    "
    x-on:coupon-applied.window="applied = true; open = false"
    x-on:coupon-removed.window="applied = false; coupon = null"
    class="my-4 lg:my-6"
    x-ref="coupon"
    
>
    <div class="flex items-center text-blue-600 hover:text-blue-800 transition-colors duration-300 cursor-pointer" x-cloak x-show="! open && ! coupon" x-on:click="toggle">
        <span>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="fill-blue-600"><path d="M21 5H3a1 1 0 0 0-1 1v4h.893c.996 0 1.92.681 2.08 1.664A2.001 2.001 0 0 1 3 14H2v4a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-4h-1a2.001 2.001 0 0 1-1.973-2.336c.16-.983 1.084-1.664 2.08-1.664H22V6a1 1 0 0 0-1-1zM9 9a1 1 0 1 1 0 2 1 1 0 1 1 0-2zm-.8 6.4 6-8 1.6 1.2-6 8-1.6-1.2zM15 15a1 1 0 1 1 0-2 1 1 0 1 1 0 2z"></path></svg>
        </span>
        <span class="ml-2">
            {{ trans('statamic-resrv::frontend.addCoupon') }}
        </span>
    </div>
    <div x-cloak x-show="open" x-on:click.outside="toggle" class="relative" x-trap="open">
        <input 
            x-model="coupon"
            type="text"
            placeholder="{{ trans('statamic-resrv::frontend.addCoupon') }}"
            x-on:keyup.enter="$wire.addCoupon(coupon)"
            class="form-input bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5"
        >
        <div 
            class="absolute right-4 top-1/2 -translate-y-1/2 cursor-pointer" 
            x-show="applied === false && coupon !== null" 
            wire:click.debounce="addCoupon(coupon)"
        >
            {{ trans('statamic-resrv::frontend.apply') }}
        </div>
        <div class="absolute left-0 top-0 w-full h-full bg-opacity-50 bg-white" wire:loading></div>
    </div>
    <div x-cloak x-show="coupon !== null && applied === true" class="relative">
        <div class="flex items-center bg-white border border-gray-200 w-full p-2.5 rounded-md">
            <span>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="fill-blue-600"><path d="M21 5H3a1 1 0 0 0-1 1v4h.893c.996 0 1.92.681 2.08 1.664A2.001 2.001 0 0 1 3 14H2v4a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-4h-1a2.001 2.001 0 0 1-1.973-2.336c.16-.983 1.084-1.664 2.08-1.664H22V6a1 1 0 0 0-1-1zM9 9a1 1 0 1 1 0 2 1 1 0 1 1 0-2zm-.8 6.4 6-8 1.6 1.2-6 8-1.6-1.2zM15 15a1 1 0 1 1 0-2 1 1 0 1 1 0 2z"></path></svg>
            </span>
            <span x-html="coupon" class="ml-3"></span>
            <div 
                class="absolute right-4 top-1/2 -translate-y-1/2 cursor-pointer" 
                wire:click.debounce="removeCoupon()"
                x-show="step === 1"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>              
            </div>
        </div>
    </div>
    @if ($errors->has('coupon'))
    <div class="px-1 mt-2 text-red-600">
        <span class="block">{{ $errors->first('coupon') }}</span>
    </div>
    @endif
</div>
