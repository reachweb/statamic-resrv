@props(['field', 'key', 'errors'])

<div {{ $attributes->class(['relative', 'col-span-2' => $field['width'] === 100, 'col-span-1' => $field['width'] === 50,]) }} wire:key={{ $key }}>
    <label class="inline-flex items-center cursor-pointer">
        <input type="checkbox" class="sr-only peer" wire:model="form.{{ $field['handle'] }}">
        <div 
            class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 
            rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white 
            after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 
            after:border after:rounded-full after:w-5 after:h-5 after:transition-all peer-checked:bg-blue-600"
        >
        </div>
            <span class="ms-3 text-sm font-medium text-gray-900">{{ __($field['display']) }}</span>
    </label>
    @if (array_key_exists('instructions', $field))
    <p id="{{ $field['handle'] }}-explanation" class="mt-2 text-sm text-gray-500">
        {{ __($field['instructions']) }}
    </p>
    @endif
    @if ($errors->has('form.' . $field['handle']))
    <p class="mt-2 text-sm text-red-600">{{ implode(', ', $errors->get('form.' . $field['handle'])) }}</p>
    @endif
</div>