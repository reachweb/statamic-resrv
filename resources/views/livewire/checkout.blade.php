@use(Carbon\Carbon)
<div x-ref="checkout" x-data="{ step: $wire.entangle('step') }" x-init="$watch('step', () => $refs.checkout.scrollIntoView({ behavior: 'smooth' }))">
    <div class="w-full flex flex-col lg:flex-row">
        <div class="w-full lg:w-8/12 md:pr-8 xl:pr-16 order-2 lg:order-1">
            <div class="mt-4 mb-8">
                <x-resrv::checkout-steps :$step :$enableExtrasStep />
            </div>
            <div class="mt-4">
                @if ($step === 1)
                    @if ($this->options->count() > 0)
                    <x-resrv::checkout-options :$enabledOptions :options="$this->options" />
                    @endif

                    @if ($this->extras->count() > 0)
                    <x-resrv::checkout-extras :$enabledExtras :extras="$this->extras" />
                    @endif

                    <div class="mt-8 xl:mt-10">
                        <x-resrv::checkout-step-button wire:click="handleFirstStep()">
                            {{ trans('statamic-resrv::frontend.continueToPersonalDetails') }}
                        </x-resrv::checkout-step-button>
                    </div>
                @endif
                @if ($step === 2)
                    <livewire:checkout-form :reservation="$this->reservation" />
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
        <div class="w-full lg:w-4/12 bg-gray-100 rounded p-4 lg:p-8 2xl:p-10 mb-8 lg:mb-0 order-1 lg:order-2">
            <div class="flex flex-col justify-between h-full">
                <div>
                    <x-resrv::checkout-reservation-details 
                        :entry="$this->entry"
                        :reservation="$this->reservation"
                    />
                </div>
                <div class="flex flex-col w-full pt-2 md:pt-4">
                    @if ($this->enableCoupon)
                    <x-resrv::checkout-coupon />
                    @endif
                    <x-resrv::checkout-payment-table 
                        :extras="$this->extras"
                        :$enabledExtras
                        :options="$this->options"
                        :$enabledOptions
                        :totals="$this->calculateReservationTotals()"
                        :key="'pt-'.$enabledExtras->extras->pluck('id')->join('-').$enabledOptions->options->pluck('id')->join('-')"
                    />
                </div>
            </div>
        </div>
    </div>
</div>