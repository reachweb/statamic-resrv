@extends('statamic::layout')
@section('title', 'Resrv Data import')
@section('wrapper_class', 'page-wrapper max-w-xl')

@section('content')


    <header class="mb-3">
        <div class="flex items-center justify-between">
            <h1>Import availability</h1>
            <a href="{{ cp_route('resrv.dataimport.index') }}" class="btn-primary">Import again</a>
        </div>
    </header>

    <div class="card rounded p-3 lg:px-7 lg:py-5 shadow bg-white">
        <div class="mb-5">
            <div class="font-bold text-center text-base mb-2">
                Import finished!
            </div>
           
        </div>  
    </div>
@endsection