@props(['field', 'key', 'errors'])

<div {{ $attributes->class(['relative', 'col-span-2' => $field['width'] === 100, 'col-span-1' => $field['width'] === 50,]) }} wire:key={{ $key }}>
    <label for="{{ $field['handle'] }}" class="block mb-2 text-sm font-medium text-gray-900">
        {{ __($field['display']) }}
    </label>
    <textarea
        wire:model="form.{{ $field['handle'] }}"
        rows="4" 
        id="{{ $field['handle'] }}" 
        class="form-textarea block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500"
        @if (array_key_exists('instructions', $field))
        aria-describedby="{{ $field['handle'] }}-explanation"
        @endif
    >
    </textarea>
    @if (array_key_exists('instructions', $field))
    <p id="{{ $field['handle'] }}-explanation" class="mt-2 text-sm text-gray-500">
        {{ __($field['instructions']) }}
    </p>
    @endif
    @if ($errors->has('form.' . $field['handle']))
    <p class="mt-2 text-sm text-red-600">{{ implode(', ', $errors->get('form.' . $field['handle'])) }}</p>
    @endif
</div>