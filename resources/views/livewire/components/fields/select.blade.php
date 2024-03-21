@props(['field', 'key', 'errors'])

<div {{ $attributes->class(['relative', 'col-span-2' => $field['width'] === 100, 'col-span-1' => $field['width'] === 50,]) }} wire:key={{ $key }}>
    <label for="{{ $field['handle'] }}" class="block mb-2 text-sm font-medium text-gray-900">
        {{ __($field['display']) }}
    </label>
    <select
        wire:model="form.{{ $field['handle'] }}"
        type="text" 
        id="{{ $field['handle'] }}" 
        class="form-select bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5"
        @if (array_key_exists('instructions', $field))
        aria-describedby="{{ $field['handle'] }}-explanation"
        @endif
    >
        <option selected>{{ __('Please select') }}</option>
        @foreach ($field['options'] as $key => $label)
        <option value="{{ $key }}">{{ __($label) }}</option>
        @endforeach
    </select>
    @if (array_key_exists('instructions', $field))
    <p id="{{ $field['handle'] }}-explanation" class="mt-2 text-sm text-gray-500">
        {{ __($field['instructions']) }}
    </p>
    @endif
    @if ($errors->has('form.' . $field['handle']))
    <p class="mt-2 text-sm text-red-600">{{ implode(', ', $errors->get('form.' . $field['handle'])) }}</p>
    @endif
</div>