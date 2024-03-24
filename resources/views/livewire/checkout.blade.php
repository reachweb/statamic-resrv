@use(Carbon\Carbon)

<div>
    <div class="w-full flex flex-col md:flex-row">
        <div class="w-full md:w-8/12 md:pr-8 xl:pr-16">
            <div class="mt-4 mb-8">
                <x-resrv::checkout-steps :$step :$enableExtrasStep />
            </div>
            <div class="mt-4">
                @if ($step === 1)
                    <x-resrv::checkout-options :$enabledOptions :options="$this->options" />

                    <x-resrv::checkout-extras :$enabledExtras :extras="$this->extras" />

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
            @if ($errors->any())
            <div class="flex flex-col mt-6 p-4 bg-red-50 border border-red-300 rounded">
                <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.somethingWentWrong') }}</dt>
                <dd class="mb-1 text-gray-700 lg:text-lg ">
                    @foreach ($errors->all() as $index => $error)
                        <div wire:key="{{ $index }}">{{ $error }}</div>
                    @endforeach
                </dd>
            </div>
            @endif
        </div>
        <div class="w-full md:w-4/12 bg-gray-100 rounded p-4 md:p-8 xl:p-10">
            <div class="flex flex-col justify-between h-full">
                <div>
                    <x-resrv::checkout-reservation-details 
                        :entry="$this->entry"
                        :reservation="$this->reservation"
                    />
                </div>
                <div class="flex flex-grow pt-3 md:pt-4">
                    <x-resrv::checkout-payment-table 
                        :extras="$this->extras"
                        :$enabledExtras
                        :options="$this->options"
                        :$enabledOptions 
                        :totals="$this->calculateTotals()"
                        :key="'pt-'.$enabledExtras->pluck('id')->join('-').$enabledOptions->pluck('id')->join('-')"
                    />
                </div>
            </div>
        </div>
    </div>
</div>