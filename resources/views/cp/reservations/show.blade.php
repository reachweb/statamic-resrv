@extends('statamic::layout')
@section('title', 'Resrv Reservations')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <div class="flex">
        <a href="{{ cp_route('resrv.reservations.index') }}" class="flex-initial flex p-1 -m-1 items-center text-xs text-grey-70 hover:text-grey-90">
            <div class="h-6 rotate-180 svg-icon using-svg">
                <svg width="24" height="24" class="align-middle"><path d="M10.414 7.05l4.95 4.95-4.95 4.95L9 15.534 12.536 12 9 8.464z" fill="currentColor" fill-rule="evenodd"></path></svg>
            </div> 
            <span>Back</span>
        </a>
    </div>

    <header class="mb-3">
        <div class="flex items-center justify-between">
            <h1>Reservation #{{ $reservation->id }} - {{ $entry->title }}</h1> 
        </div>
    </header>

    <div>
    <div class="mb-2 content">
            <h2 class="text-base">Reservation Details</h2>
        </div>
        <div class="card p-2 mb-5">
            <div class="grid grid-cols-3 mb-3">
                <div>
                    <div class="font-bold mb-1">Reservation ID</div>
                    <div># {{ $reservation->id }}</div>
                </div>
                <div>
                    <div class="font-bold mb-1">Reference</div>
                    <div>{{ $reservation->reference }}</div>
                </div>
                <div>
                    <div class="font-bold mb-1">Status</div>
                    <div>{{ Str::upper($reservation->status) }}</div>
                </div>                
            </div>
            <div class="grid grid-cols-3 mb-3">
                <div>
                    <div class="font-bold mb-1">Start date</div>
                    <div>{{ $reservation->date_start->format('d-m-Y H:i') }}</div>
                </div>
                <div>
                    <div class="font-bold mb-1">End date</div>
                    <div>{{ $reservation->date_end->format('d-m-Y H:i') }}</div>
                </div>
                <div>
                    <div class="font-bold mb-1">Reservation date</div>
                    <div>{{ $reservation->created_at->format('d-m-Y H:i') }}</div>
                </div>                
            </div>
            @if (config('resrv-config.enable_locations'))
            <div class="grid grid-cols-3">
                <div>
                    <div class="font-bold mb-1">Pick-up location</div>
                    <div>{{ $reservation->location_start_data->name }}</div>
                </div>
                <div>
                    <div class="font-bold mb-1">Drop-off location</div>
                    <div>{{ $reservation->location_end_data->name }}</div>
                </div>                           
            </div>
            @endif
        </div>
    </div>
    
    @if ($reservation->customer->count() > 1)
    <div>
        <div class="mb-2 content">
            <h2 class="text-base">Customer data</h2>
        </div>
        <div class="card p-2 mb-5">
            <div class="grid grid-cols-2">
            @foreach ($reservation->customer as $field => $value)
                @if (is_array($value))
                    @continue
                @endif
                <div class="mb-2">
                    <div class="font-bold mb-1">{{ $fields[$field] }}</div>
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
            <h2 class="text-base">Options</h2>
        </div>
        <div class="card p-2 mb-5">
        @foreach ($reservation->options as $option)               
            <div class="mb-1 border-b border-gray flex justify-between w-full p-1">
                <div>{{ $option->name }}</div>
                <div>{{ $option->values->find($option->pivot->value)->name }}</div>
                @if ($option->values->find($option->pivot->value)->price_type != 'free')
                <div class="font-bold">
                    {{ $option->values->find($option->pivot->value)->price->format() }}
                    <span class="font-normal">
                        @if ($option->values->find($option->pivot->value)->price_type == 'fixed')
                        / reservation
                        @endif
                        @if ($option->values->find($option->pivot->value)->price_type == 'perday')
                        / day
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
            <h2 class="text-base">Extras</h2>
        </div>
        <div class="card p-2 mb-5">
        @foreach ($reservation->extras as $extra)               
            <div class="mb-1 border-b border-gray flex justify-between w-full p-1">
                <div>{{ $extra->name }} x{{ $extra->pivot->quantity }}</div>
                <div class="font-bold">
                    {{ $extra->price->format() }}
                    <span class="font-normal">
                        @if ($extra->price_type == 'fixed')
                        / reservation
                        @endif
                        @if ($extra->price_type == 'perday')
                        / day
                        @endif
                    </span>
                </div>
            </div>  
        @endforeach             
        </div>
    </div>
    @endif

    <div>
        <div class="mb-2 content">
            <h2 class="text-base">Payment information</h2>
        </div>
        <div class="card p-2 mb-5">
            <div class="mb-1 border-b border-gray flex justify-between w-full p-1">
                <div>Deposit</div>
                <div class="font-bold">
                    {{ config('resrv-config.currency_symbol') }} {{ $reservation->payment->format() }}
                </div>
            </div>  
            <div class="mb-1 border-b border-gray flex justify-between w-full p-1">
                <div class="font-bold text-xl">Total price</div>
                <div class="font-bold text-xl">
                    {{ config('resrv-config.currency_symbol') }} {{ $reservation->price->format() }}
                </div>
            </div>  
        </div>        
    </div>
    


@endsection