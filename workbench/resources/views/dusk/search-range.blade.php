@extends('layout')

@section('content')
<div dusk="search-range-route">
    <livewire:availability-search
        :entry="$entryId"
        :calendar="'range'"
        :rates="true"
        :enable-quantity="true"
        :show-availability-on-calendar="true" />
</div>
@endsection
