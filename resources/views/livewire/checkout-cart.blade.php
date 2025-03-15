@use(Carbon\Carbon)
<div x-ref="checkout" x-data="{ step: $wire.entangle('step') }" x-init="$watch('step', () => $refs.checkout.scrollIntoView({ behavior: 'smooth' }))">
    <div class="w-full flex flex-col lg:flex-row">

        <div class="w-full lg:w-8/12 md:pr-8 xl:pr-16 order-2 lg:order-1">
            <div class="mt-4 mb-8">
                <x-resrv::checkout-steps :$step :$enableExtrasStep />
            </div>
            <div class="mt-4">
                @if ($step === 1)
                    @foreach($this->reservation->childs as $child)
                        @if ($this->extras->get($child->id)->count() > 0)
                        <x-resrv::checkout-cart-extras :enabledExtras="$this->data->getEnabledExtras($child->id)" :extras="$this->frontendExtras->get($child->id)" />
                        @endif
                    @endforeach
                    <div class="mt-8 xl:mt-10">
                        <x-resrv::checkout-step-button wire:click="handleFirstStep()">
                            {{ trans('statamic-resrv::frontend.continueToPersonalDetails') }}
                        </x-resrv::checkout-step-button>
                    </div>
                @endif
                @if ($step === 2)
                    <livewire:checkout-form :reservation="$this->reservation" :affiliateCanSkipPayment="$this->affiliateCanSkipPayment()" />
                @endif
                @if ($step === 3)
                    <livewire:checkout-payment :client_secret="$clientSecret" :amount="$this->reservation->payment->format()" />
                @endif
            </div>
            @if ($errors->has('reservation'))
            <div class="flex flex-col my-4 md:my-6 p-4 bg-red-50 border border-red-300 rounded">
                <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.somethingWentWrong') }}</dt>
                <dd class="mb-1 text-gray-700 lg:text-lg ">
                    @foreach ($errors->get('reservation') as $index => $error)
                        <div wire:key="{{ $index }}">{{ $error }}</div>
                    @endforeach
                </dd>
            </div>
            <a class="flex items-center lg:text-lg font-medium bg-gray-100 border border-gray-200 rounded p-4" href="{{ $this->entry->url() }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                </svg>
                {{ trans('statamic-resrv::frontend.returnToThePreviousPage') }}
            </a>
            @endif
        </div>
    </div>
</div>