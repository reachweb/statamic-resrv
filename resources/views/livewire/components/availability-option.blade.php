@props(['option'])

<div 
    class="{{ $attributes->get('class') }}"
    x-data="{
        values: @js($option->values),
        selected: '{{ $option->required ? $option->values->first()->id : '' }}',
        dispatchOptionChanged() {
            let selectedValue = this.values.find(value => value.id == this.selected);
            if (selectedValue !== undefined) {
                $dispatch('option-changed', {id: {{ $option->id }}, price: selectedValue.price, value: selectedValue.id});
            }
        },
        dispatchOptionRemoved() {
            $dispatch('option-removed', {id: {{ $option->id }}});
        }
    }"
    x-init="
        if (selected !== '') {
            dispatchOptionChanged();
        }
        $watch('selected', value => (value === '' ? dispatchOptionRemoved() : dispatchOptionChanged()));     
    "
>
    <select
        class="form-select min-w-[200px] h-11 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full py-2.5"
        x-model="selected"
    >   
        @if (! $option->required)
        <option value="">
            {{ trans('statamic-resrv::frontend.select') }}
        </option>
        @endif
        @foreach ($option->values as $value)
            <option value="{{ $value->id }}" @selected($option->required && $loop->first)>
                {{ $value->name }}
                @if ($value->price_type !== 'free')
                ({{ config('resrv-config.currency_symbol') }} {{ $value->price->format() }})
                @endif         
            </option>
        @endforeach
    </select>
</div>