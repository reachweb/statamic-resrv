@extends('statamic::layout')
@section('title', 'Resrv Reservations')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex">
        <a href="{{ cp_route('resrv.reservations.index') }}" class="flex-initial flex p-2 -m-1 items-center text-xs text-grey-70 hover:text-grey-90">
            <div class="h-6 rotate-180 svg-icon using-svg">
                <svg width="24" height="24" class="align-middle"><path d="M10.414 7.05l4.95 4.95-4.95 4.95L9 15.534 12.536 12 9 8.464z" fill="currentColor" fill-rule="evenodd"></path></svg>
            </div> 
            <span>{{ __("Back") }}</span>
        </a>
    </div>

    <header class="mt-1 mb-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between">
            <h1>{{ __("Reservation") }} #{{ $reservation->id }} - {{ $reservation->entry['title'] }}</h1>
            <div class="mt-1 md:mt-0 font-semibold">{{ $reservation->created_at->format('d-m-Y H:i') }}</div>
        </div>
    </header>

    <div>
        <div class="mb-2 content flex">
            <h2 class="text-base">{{ __("Reservation Details") }}</h2>
        </div>
        <div class="card px-6 py-4 mb-8 divide-y">
            <div class="grid grid-cols-3 my-2">
                <div>
                    <div class="font-bold mb-2">{{ __("Reservation ID") }}</div>
                    <div># {{ $reservation->id }}</div>
                </div>
                <div>
                    <div class="font-bold mb-2">{{ __("Reference") }}</div>
                    <div>{{ $reservation->reference }}</div>
                </div>
                <div>
                    <div class="font-bold mb-2">{{ __("Status") }}</div>
                    <div>{{ Str::upper($reservation->status) }}</div>
                </div>                
            </div>
            @if ($reservation->type !== 'parent')
            <div class="grid grid-cols-2 my-2 pt-2">
                <div>
                    <div class="font-bold mb-2">{{ __("Start date") }}</div>
                    <div>{{ $reservation->date_start->format('d-m-Y H:i') }}</div>
                </div>
                <div>
                    <div class="font-bold mb-2">{{ __("End date") }}</div>
                    <div>{{ $reservation->date_end->format('d-m-Y H:i') }}</div>
                </div>      
            </div>
            @endif
            @if (config('resrv-config.enable_locations') && $reservation->location_start_data && $reservation->location_end_data)
            <div class="grid grid-cols-2 my-2 pt-2">
                <div>
                    <div class="font-bold mb-2">{{ __("Pick-up location") }}</div>
                    <div>{{ $reservation->location_start_data->name }}</div>
                </div>
                <div>
                    <div class="font-bold mb-2">{{ __("Drop-off location") }}</div>
                    <div>{{ $reservation->location_end_data->name }}</div>
                </div>
            </div>
            @endif
            @if ($reservation->type !== 'parent')
            <div class="grid grid-cols-2 my-2 pt-2">
                @if (config('resrv-config.maximum_quantity') > 1)
                <div>
                    <div class="font-bold mb-2">{{ __("Quantity") }}</div>
                    <div>x {{ $reservation->quantity }}</div>
                </div>
                @endif
                @if (config('resrv-config.enable_advanced_availability') && $reservation->property)
                <div>
                    <div class="font-bold mb-2">{{ __("Property") }}</div>
                    <div>{{ $reservation->getPropertyAttributeLabel() }}</div>
                </div>
                @endif
            </div>
            @endif      
        </div>
    </div>

    @if ($reservation->type === 'parent')
    <div>
        <div class="mb-2 content flex">
            <h2 class="text-base">{{ __("Related reservations") }}</h2>
        </div>
        @foreach ($reservation->childs as $child)
        <div class="card px-6 py-4 mb-6 divide-y">            
            <div class="grid grid-cols-2 my-2">
                <div>
                    <div class="font-bold mb-2">{{ __("Start date") }}</div>
                    <div>{{ $child->date_start->format('d-m-Y H:i') }}</div>
                </div>
                <div>
                    <div class="font-bold mb-2">{{ __("End date") }}</div>
                    <div>{{ $child->date_end->format('d-m-Y H:i') }}</div>
                </div>      
            </div>
            <div class="grid grid-cols-2 my-2 pt-2">
                @if (config('resrv-config.maximum_quantity') > 1)
                <div>
                    <div class="font-bold mb-2">{{ __("Quantity") }}</div>
                    <div>x {{ $child->quantity }}</div>
                </div>
                @endif
                @if (config('resrv-config.enable_advanced_availability'))
                <div>
                    <div class="font-bold mb-2">{{ __("Property") }}</div>
                    <div>{{ $child->getPropertyAttributeLabel() }}</div>
                </div>
                @endif
            </div>          
        </div>
        @endforeach
    </div>
    @endif
    
    @if ($reservation->customer && $reservation->customer->count() > 1)
    <div>
        <div class="mb-2 content">
            <h2 class="text-base">{{ __("Checkout data") }}</h2>
        </div>
        <div class="card px-6 py-4 mb-6">
            <div class="grid grid-cols-2 xl:grid-cols-3 mt-2">
            @foreach ($reservation->customer as $field => $value)
                @if (is_array($value) || $value == null)
                    @continue
                @endif
                <div class="mb-2">
                    <div class="font-bold mb-2">{{ $fields[$field] ?? $field }}</div>
                    <div>{{ $value }}</div>
                </div>  
            @endforeach 
            </div>
            
        </div>
    </div>
    @endif

    @if ($reservation->options->count() > 0)
    <div>
        <div class="mb-2 content">
            <h2 class="text-base">{{ __("Options") }}</h2>
        </div>
        <div class="card px-6 py-4 mb-6">
        @foreach ($reservation->options as $option)               
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div>{{ $option->name }}</div>
                <div>{{ $option->values->find($option->pivot->value)->name }}</div>
                @if ($option->values->find($option->pivot->value)->price_type != 'free')
                <div class="font-bold">
                    {{ $option->values->find($option->pivot->value)->price->format() }}
                    <span class="font-normal">
                        @if ($option->values->find($option->pivot->value)->price_type == 'fixed')
                        / {{ __("reservation") }}
                        @endif
                        @if ($option->values->find($option->pivot->value)->price_type == 'perday')
                        / {{ __("day") }}
                        @endif
                    </span>
                </div>
                @endif
            </div>  
        @endforeach             
        </div>
    </div>
    @endif

    @if ($reservation->extras->count() > 0)
    <div>
        <div class="mb-2 content">
            <h2 class="text-base">{{ __("Extras") }}</h2>
        </div>
        <div class="card px-6 py-4 mb-6">
        @foreach ($reservation->extras as $extra)               
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div>{{ $extra->name }} x{{ $extra->pivot->quantity }}</div>
                <div class="font-bold">
                    {{ config('resrv-config.currency_symbol') }} {{ $extra->priceFromPivot() }}
                </div>
            </div>  
        @endforeach             
        </div>
    </div>
    @endif

    @if ($reservation->affiliate->count() > 0)
    <div>
        <div class="mb-2 content">
            <h2 class="text-base">{{ __("Affiliate") }}</h2>
        </div>
        <div class="card px-6 py-4 mb-6">
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div>{{ $reservation->affiliate->first()->name }}</div>
                <div class="font-bold">
                    {{ $reservation->affiliate->first()->email }}
                </div>
            </div>
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div>{{ __('Fee at the time of reservation') }}</div>
                <div class="font-bold">
                    {{ $reservation->affiliate->first()->pivot->fee }}%
                </div>
            </div>
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div>{{ __('Preliminary fee to be paid') }}</div>
                <div class="font-bold">
                    {{ config('resrv-config.currency_symbol') }} {{ $reservation->total->multiply($reservation->affiliate->first()->pivot->fee / 100)->format() }}
                </div>
            </div>
        </div>
    </div>
    @endif

    <div>
        <div class="mb-2 content">
            <h2 class="text-base">{{ __("Payment information") }}</h2>
        </div>
        <div class="card px-6 py-4 mb-6">
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div>{{ __("Payment") }}</div>
                <div class="font-bold">
                    {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment->format() }}
                </div>
            </div>
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div>{{ __("Reservation price") }}</div>
                <div class="font-bold">
                    {{ config('resrv-config.currency_symbol') }} {{ $reservation->price->format() }}
                </div>
            </div>  
            <div class="mb-2 border-b border-gray flex justify-between w-full p-2">
                <div class="font-bold text-xl">{{ __("Total price (including extras & options)") }}</div>
                <div class="font-bold text-xl">
                    {{ config('resrv-config.currency_symbol') }} {{ $reservation->total->format() }}
                </div>
            </div>  
        </div>        
    </div>
    


@endsection