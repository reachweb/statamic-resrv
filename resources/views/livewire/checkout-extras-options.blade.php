<div>
    @if ($this->options->count() > 0)
        <div class="mt-6 xl:mt-8">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ trans('statamic-resrv::frontend.options') }}
            </div>
            <div class="text-gray-700">
                {{ trans('statamic-resrv::frontend.optionsDescription') }}
            </div>
        </div>
        <hr class="h-px my-4 bg-gray-200 border-0">
        <div class="divide-y divide-gray-100">
            @foreach ($this->options as $id => $option)
                <div wire:key="option-{{ $option->id }}" class="my-3 lg:my-5">
                    <div class="mb-2">
                        <div class="text-lg font-medium">{{ $option->name }}</div>
                        @if ($option->description)
                            <div class="text-sm text-gray-500">{{ $option->description }}</div>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach ($option->values as $value)
                            <label class="relative flex items-center p-3 rounded-lg border 
                                   {{ $this->getSelectedOptionValue($option->id) == $value->id ? 'border-blue-600 bg-blue-50' : 'border-gray-200' }}"
                                   wire:click="updateOption({{ $option->id }}, {{ $value->id }}, '{{ $value->price }}')">
                                <input type="radio" name="option-{{ $option->id }}" value="{{ $value->id }}" 
                                       class="sr-only" 
                                       {{ $this->getSelectedOptionValue($option->id) == $value->id ? 'checked' : '' }}>
                                <div class="flex flex-col">
                                    <span class="text-gray-900 font-medium">{{ $value->name }}</span>
                                    <span class="text-gray-500 text-sm">
                                        @if ($value->price_type !== 'free')
                                            {{ config('resrv-config.currency_symbol') }} {{ $value->price }}
                                        @else
                                            {{ ucfirst(trans('statamic-resrv::frontend.free')) }}
                                        @endif
                                    </span>
                                </div>
                                <div class="absolute inset-0 rounded-lg pointer-events-none 
                                    {{ $this->getSelectedOptionValue($option->id) == $value->id ? 'border-2 border-blue-600' : 'border border-transparent' }}">
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($this->frontendExtras->count() > 0)
        <div class="mt-6 xl:mt-8">
            <div class="text-lg xl:text-xl font-medium mb-2">
                {{ trans('statamic-resrv::frontend.extras') }}
            </div>
            <div class="text-gray-700">
                {{ trans('statamic-resrv::frontend.extrasDescription') }}
            </div>
        </div>
        <hr class="h-px my-4 bg-gray-200 border-0">
        <div>
            @foreach ($this->frontendExtras as $category)
            <div>
                @if ($category->id)
                <div class="my-3 lg:my-4">
                    <div class="text-lg xl:text-xl font-medium">
                        {{ $category->name }}
                    </div>
                    @if ($category->description)
                    <div class="text-gray-700">
                        {{ $category->description }}
                    </div>
                    @endif
                </div>
                @endif
                <div>
                    @foreach ($category->extras as $extra)
                    @include('statamic-resrv::livewire.components.partials.extra')
                    @endforeach
                </div>
                <hr class="h-px my-4 bg-gray-100 border-0">
            </div>
            @endforeach
        </div>
    @endif
</div>
