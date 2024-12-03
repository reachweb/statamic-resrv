@props(['field', 'key', 'errors'])

@if ($this->isPhoneDictionary($field['handle']))
    @include('statamic-resrv::livewire.components.fields.dictionary_phone')
@else
    @include('statamic-resrv::livewire.components.fields.dictionary_default')
@endif
