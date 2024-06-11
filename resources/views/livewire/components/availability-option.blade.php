@props(['option', 'selectedValue' => null])

<div 
    class="{{ $attributes->get('class') }}"
    x-data="{selected: ''}"
    x-init="selected = '{{ $selectedValue ?? '' }}'"
>
    
    <select 
        class="form-select min-w-[200px] h-11 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full py-2.5"
        x-model="selected"
    >
        @foreach ($option->values as $value)
            <option value="{{ $value }}">
                {{ $value->name }}                   
            </option>
        @endforeach
    </select>
</div>