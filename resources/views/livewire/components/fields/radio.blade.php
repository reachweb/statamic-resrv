@props(['field', 'key', 'errors'])

<div {{ $attributes->class(['relative', 'md:col-span-2' => $field['width'] === 100, 'md:col-span-1' => $field['width'] === 50,]) }} wire:key="{{ $key }}">
    <label for="{{ $field['handle'] }}" class="block mb-2 font-medium text-gray-900">
        {{ __($field['display']) }}
    </label>
    @if (array_key_exists('options', $field))
        @foreach ($field['options'] as $key => $label)
        <div class="flex items-center mb-4">
            <input type="radio" wire:model="form.{{ $field['handle'] }}" id="{{ $key }}" value="{{ $key }}" class="form-radio w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 focus:ring-blue-500 ">
            <label for="{{ $key }}" class="ms-2 font-medium text-gray-900">{{ $label }}</label>
        </div>
        @endforeach
    @endif
    @if (array_key_exists('instructions', $field))
    <p id="{{ $field['handle'] }}-explanation" class="mt-2 text-gray-500">
        {{ __($field['instructions']) }}
    </p>
    @endif
    @if ($errors->has('form.' . $field['handle']))
    <p class="mt-2 text-red-600">{{ implode(', ', $errors->get('form.' . $field['handle'])) }}</p>
    @endif
</div>