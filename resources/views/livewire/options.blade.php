<div>
    @if ($this->options->count() > 0)
        <div class="mt-4 xl:mt-6">
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
            @include('statamic-resrv::livewire.components.partials.option')
            @endforeach
        </div>
        @if ($errors)
        <div class="flex flex-col my-4 md:my-6 p-4 bg-red-50 border border-red-300 rounded">
            <dd class="text-gray-700">
                @foreach ($errors as $index => $error)
                    <div wire:key="{{ $index }}">{{ $error }}</div>
                @endforeach
            </dd>
        </div>
        @endif
    @endif
</div>