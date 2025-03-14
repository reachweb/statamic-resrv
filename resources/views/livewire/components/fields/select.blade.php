@props(['field', 'key', 'errors'])

<div {{ $attributes->class(['relative', 'md:col-span-2' => $field['width'] === 100, 'md:col-span-1' => $field['width'] === 50,]) }} wire:key="{{ $key }}">
    <label for="{{ $field['handle'] }}" class="block mb-2 font-medium text-gray-900">
        {{ __($field['display']) }}
    </label>
    <select
        wire:model="form.{{ $field['handle'] }}"
        id="{{ $field['handle'] }}" 
        class="form-select bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5"
        @if (array_key_exists('instructions', $field))
        aria-describedby="{{ $field['handle'] }}-explanation"
        @endif
    >
        <option selected>{{ __('Please select') }}</option>
        @if (array_key_exists('options', $field))
        @foreach ($field['options'] as $option)
        <option value="{{ $option['key'] }}">{{ __($option['value']) }}</option>
        @endforeach
        @endif
    </select>
    @if (array_key_exists('instructions', $field))
    <p id="{{ $field['handle'] }}-explanation" class="mt-2 text-gray-500">
        {{ __($field['instructions']) }}
    </p>
    @endif
    @if ($errors->has('form.' . $field['handle']))
    <p class="mt-2 text-red-600">{{ implode(', ', $errors->get('form.' . $field['handle'])) }}</p>
    @endif
</div>