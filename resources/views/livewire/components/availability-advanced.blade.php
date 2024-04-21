@props(['advancedProperties', 'errors'])

<div class="{{ $attributes->get('class') }}">
    <select 
        id="availability-search-advanced" 
        class="form-select min-w-[200px] h-11 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full py-2.5"
        {{ $attributes->whereStartsWith('wire:model') }}
    >
        <option selected value="any">{{ trans('statamic-resrv::frontend.selectProperty') }}</option>
        @foreach ($advancedProperties as $value => $label)
            <option value="{{ $value }}">
                {{ $label }}                   
            </option>
        @endforeach
    </select>
    @if ($errors->has('data.advanced'))
    <div class="mt-2 text-red-600 text-sm space-y-1">
        <span class="block">{{ $errors->first('data.advanced') }}</span>
    </div>
    @endif
</div>