<div class="py-3 xl:my-5">
    <div class="mb-3">
        <div class="flex items-center">
            <span class="text-base xl:text-lg font-medium text-gray-900">{{ $option->name }}</span>
            @if ($option->required)
            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-1.5 py-0.5 rounded uppercase ms-4">
                {{ trans('statamic-resrv::frontend.required') }}
            </span>
            @endif
        </div>
        @if ($option->description)
        <div class="text-gray-500">{{ $option->description }}</div>
        @endif
    </div>
    <ul class="grid w-full gap-6 md:grid-cols-2">
        @foreach ($option->values as $value)
        <li wire:key="{{ $value->id }}">
            <label 
                for="{{ $option->slug }}-{{ $value->id }}" 
                class="inline-flex items-center w-full h-full p-5 text-gray-500 bg-white border border-gray-200 rounded-lg 
                cursor-pointer has-[:checked]:border-blue-600 has-[:checked]:text-blue-600 hover:text-gray-600 hover:bg-gray-100"
            >
                <input 
                    type="radio" 
                    id="{{ $option->slug }}-{{ $value->id }}" 
                    wire:model.live="selected"
                    value="{{ $value->id }}" 
                    class="form-radio w-5 h-5 text-blue-600 bg-gray-100 border-gray-300" 
                />
                <div class="block ml-3">
                    <div class="w-full text-md xl:text-base font-medium text-gray-900">{{ $value->name }}</div>
                    @if ($value->description)
                    <div class="w-full text-sm">{{ $value->description }}</div>
                    @endif
                    @if ($value->price_type !== 'free')
                    <div class="w-full text-sm mt-1 font-medium text-gray-700">
                        {{ config('resrv-config.currency_symbol') }} {{ $value->price->format() }}
                    </div>
                    @endif
                </div>
            </label>
        </li>
        @endforeach
    </ul>
</div>