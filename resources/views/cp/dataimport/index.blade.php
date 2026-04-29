@extends('statamic::layout')
@section('title', 'Resrv Data import')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')

    <form action="{{ cp_route('resrv.dataimport.confirm') }}" method="POST" enctype="multipart/form-data">
        {{ csrf_field() }}
        <header class="mb-6">
            <div class="flex items-center justify-between">
                <h1>Import availability</h1>
                <button class="btn-primary">Continue</button>
            </div>
        </header>
        <div class="card rounded p-4 lg:px-8 lg:py-6 shadow bg-gray-100 dark:border-dark-900 dark:bg-dark-550 dark:shadow-dark-sm">
            <div class="mb-5">
                <label for="collection" class="font-bold text-base mb-2">Collection</label>
                @if ($collections->count() == 0)
                    <div class="text-2xs text-grey-60 mt-1 flex items-center">
                        No collections with Resrv availability fields.
                    </div>
                @else
                    @foreach ($collections as $collection)
                        <div class="flex mb-2 items-center">
                            <input type="radio" id="{{ $collection['handle'] }}" name="collection" value="{{ $collection['handle'] }}">
                            <label for="{{ $collection['handle'] }}" class="ml-1">{{ $collection['title'] }}</label>
                        </div>
                    @endforeach
                @endif
                @error('collection')
                    <div class="mt-1 text-red-600">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-5">
                <label for="file" class="font-bold text-base mb-sm">File</label>
                <input id="file" name="file" type="file" tabindex="1" class="input-text">
                <div class="text-2xs text-grey-60 mt-1 flex items-center">
                A CSV file, please check the docs for the correct format.
                </div>
                @error('file')
                    <div class="mt-1 text-red-600">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-5">
                <label for="identifier" class="font-bold text-base mb-sm">Identifier</label>
                <input id="identifier" name="identifier" placeholder="id" value="id" type="text" class="input-text">
                <div class="text-2xs text-grey-60 mt-1 flex items-center">
                    The unique ID for the import. It is usually the Statamic entry's <code>ID</code>
                </div>
                @error('identifier')
                    <div class="mt-1 text-red-600">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-5">
                <label for="delimiter" class="font-bold text-base mb-sm">Delimiter</label>
                <input id="delimiter" name="delimiter" placeholder="," value="," type="text" tabindex="1" class="input-text">
                <div class="text-2xs text-grey-60 mt-1 flex items-center">
                    Defaults to <code>,</code>. Is usually one of <code>,</code>,<code>;</code>,<code>|</code>
                </div>
                @error('delimited')
                    <div class="mt-1 text-red-600">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </form>

@endsection