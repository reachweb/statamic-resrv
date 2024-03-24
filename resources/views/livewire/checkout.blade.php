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
        </div>
        <div class="w-full md:w-4/12 bg-gray-100 rounded p-4 md:p-8 xl:p-10">
            <div class="flex flex-col justify-between h-full">
                <div>
                    <div class="text-lg xl:text-xl font-medium mb-2">
                        {{ trans('statamic-resrv::frontend.reservationDetails') }}
                    </div>
                    <hr class="h-px my-4 bg-gray-200 border-0">
                    <div wire:ignore>
                        <div class="pb-3 md:pb-4 border-b border-gray-200">
                            <p class="text-sm font-medium text-gray-500 truncate">
                                {{ trans('statamic-resrv::frontend.entryTitle') }}
                            </p>
                            <p class="text-sm text-gray-900 truncate">
                                {{ $this->entry->title }}
                                @if ($this->reservation->quantity > 1)
                                <span class="text-xs text-gray-500">
                                    (x{{ $this->reservation->quantity }})
                                </span>
                                @endif
                            </p>
                        </div>
                        <div class="py-3 md:py-4 border-b border-gray-200">
                            <p class="text-sm font-medium text-gray-500 truncate">
                                {{ trans('statamic-resrv::frontend.reservationPeriod') }}
                            </p>
                            <p class="text-sm text-gray-900 truncate">
                                {{ $this->reservation->date_start }}
                                {{ trans('statamic-resrv::frontend.to') }}
                                {{ $this->reservation->date_end }}
                            </p>
                        </div>                   
                    </div>
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
    @if ($errors->any())
    <div class="flex flex-col pt-6">
        <dt class="text-lg font-medium">{{ trans('statamic-resrv::frontend.somethingWentWrong') }}</dt>
        <dd class="mb-1 text-gray-500 lg:text-lg ">
            @foreach ($errors->all() as $index => $error)
                <div wire:key="{{ $index }}">{{ $error }}</div>
            @endforeach
        </dd>
    </div>
    @endif
</div>