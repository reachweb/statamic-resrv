@extends('statamic::layout')
@section('title', 'Resrv Data import')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')


    <header class="mb-6">
        <div class="flex items-center justify-between">
            <h1>Import availability</h1>
            <a href="{{ cp_route('resrv.dataimport.store') }}" class="btn-primary">Continue</a>
        </div>
    </header>

    <div class="card rounded p-4 lg:px-8 lg:py-6 shadow bg-gray-100 dark:border-dark-900 dark:bg-dark-550 dark:shadow-dark-sm">
        @if ($errors->count() > 0)
        <div class="mb-5">
            <div class="font-bold text-base mb-2">
                Please correct the following errors to continue:
            </div>
            @foreach ($errors as $error)
            <div class="mb-1">
                {{ $error }}
            </div>
            @endforeach
            <button class="mt-2 btn-primary" onclick="history.back()">Go Back</button>
        </div>
        @else
        <div>
            <div class="font-bold text-base">
                Sample data
            </div>
            <div class="text-2xs text-grey-60 mt-1 mb-2 flex items-center">
                Please check that the data is correct before you proceed.
            </div>
            <div class="mt-3">
                <div class="text-sm mb-1">Item ID:</div>
                @foreach ($sample as $id => $data)
                    <div class="mb-3">{{ $id }}</div>
                    @foreach ($data as $value)
                    <div class="border-b py-2">
                        <div class="flex mb-1">
                            <div class="text-sm text-grey-60 mr-2">
                                From date
                            </div>
                            <div class="text-sm">
                                {{ $value['date_start'] }}
                            </div>
                        </div>
                        <div class="flex mb-1">
                            <div class="text-sm text-grey-60 mr-2">
                                To date
                            </div>
                            <div class="text-sm">
                                {{ $value['date_end'] }}
                            </div>
                        </div>
                        <div class="flex mb-1">
                            <div class="text-sm text-grey-60 mr-2">
                                Price
                            </div>
                            <div class="text-sm">
                                {{ $value['price'] }}
                            </div>
                        </div>
                        <div class="flex mb-1">
                            <div class="text-sm text-grey-60 mr-2">
                                Available
                            </div>
                            <div class="text-sm">
                                {{ $value['available'] }}
                            </div>
                        </div>
                    </div>
                    @endforeach
                @endforeach
            </div>
            
        </div>
        @endif
    </div>


@endsection