@extends('layout')

@section('content')
<div dusk="search-route">
    <livewire:availability-search :entry="$entryId" :rates="true" :enable-quantity="true" :show-availability-on-calendar="true" />
</div>
@endsection
