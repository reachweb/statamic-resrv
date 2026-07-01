@extends('layout')

{{--
    Minimal detail page for the `rooms` collection. AvailabilityCollection::select() redirects
    to $entry->url() (→ /rooms/{slug}) when an entry has a detail page, so that URL must render
    a clean 200 for T19's select → redirect assertion. Kept static (no Livewire / availability
    queries) so the redirect target is deterministic and never 500s on an availability edge case.
--}}
@section('content')
<div dusk="room-detail">Room detail page</div>
@endsection
