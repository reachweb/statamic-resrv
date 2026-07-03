@extends('layout')

{{-- T19 — entry-level pagination. paginate=1 puts one entry per page; the test drives the
     Livewire nextPage() round-trip and asserts page 2 renders the other entry. --}}
@section('content')
<div dusk="collection-paginate-route">
    <livewire:availability-collection collection="rooms" :rates="true" :paginate="1" />
</div>
@endsection
