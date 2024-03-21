<div>
    <div class="my-6 xl:my-8">
        <div class="text-lg xl:text-xl font-medium mb-2">
            {{ trans('statamic-resrv::frontend.personalDetails') }}
        </div>
        <div class="text-gray-700">
            {{ trans('statamic-resrv::frontend.personalDetailsDescription') }}
        </div>
    </div>
    <div class="grid grid-cols-2 gap-4 md:gap-6">
        @foreach ($this->checkoutForm as $field)
            <x-dynamic-component 
                :component="'resrv::fields.' . $field['type']" 
                :$field 
                :$errors
                :key="$field['handle']" 
            />
        @endforeach
    </div>
    <div class="mt-6 xl:mt-8">
        <x-resrv::checkout-step-button wire:click="submit()">
            {{ trans('statamic-resrv::frontend.continueToPayment') }}
        </x-resrv::checkout-step-button>
    </div>
</div>