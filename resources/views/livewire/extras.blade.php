<div>
    @if ($this->frontendExtras->count() > 0)
        <div class="mt-4 xl:mt-6">
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