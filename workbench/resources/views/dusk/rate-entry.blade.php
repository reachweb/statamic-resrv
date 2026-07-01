@extends('layout')

@section('content')
<div dusk="rate-entry-route">
    <livewire:availability-search :entry="$entryId" :rates="true" :any-rate="true" />
    <livewire:availability-results :entry="$entryId" :rates="true" />
</div>
@endsection
