@use(Carbon\Carbon)

<div>
    @ray($this->reservation)
    @ray($this->entry)
    @ray($this->checkoutForm)
    @ray(session('resrv_reservation'))


    <div class="w-full flex flex-col md:flex-row">
        <div class="w-full w-4/12 bg-gray-100 rounded p-4 md:p-8 xl:p-12">
            <div>
                <div class="text-lg xl:text-xl font-medium mb-2">
                    {{ trans('statamic-resrv::frontend.reservationDetails') }}
                </div>
                <hr class="h-px my-4 bg-gray-200 border-0">
                <div class="divide-y divide-gray-200">
                    <div class="pb-3 md:pb-4">
                        <p class="text-sm font-medium text-gray-500 truncate">
                            {{ trans('statamic-resrv::frontend.entryTitle') }}
                        </p>
                        <p class="text-sm text-gray-900 truncate">
                            {{ $this->entry->title }}
                        </p>
                    </div>
                    <div class="py-3 md:py-4">
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
                <div>
                    
                </div>
            </div>

        </div>
        <div class="w-full w-8/12">
            <div class="p-4 md:p-8 xl:p-12">
                
            </div>
        </div>
    </div>

</div>