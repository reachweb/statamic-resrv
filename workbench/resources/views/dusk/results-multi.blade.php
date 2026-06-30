@extends('layout')

@section('content')
<div dusk="results-multi-route">
    <livewire:availability-results :entry="$entryId" :rates="true" />
</div>
@endsection
