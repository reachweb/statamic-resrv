@use(Carbon\Carbon)

<div>
    <div class="w-full flex flex-col md:flex-row">
        <div class="w-full md:w-8/12 md:pr-8 xl:pr-16">
            <div class="mt-4 mb-8">
                <x-resrv::checkout-steps :$step :$enableExtrasStep />
            </div>
            <div class="my-4">
                @if ($step === 1)
                    <livewire:checkout-extras wire:model.live="enabledExtras" :extras="$this->extras" />
                @endif
            </div>
        </div>

        <div class="w-full md:w-4/12 bg-gray-100 rounded p-4 md:p-8 xl:p-10">
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
                <div class="py-3 md:py-4">
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