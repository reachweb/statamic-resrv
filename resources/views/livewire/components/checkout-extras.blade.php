@props(['extras', 'enabledExtras'])

<div
    x-data="{selectedExtras: {}}" 
    x-on:extra-changed.debounce="selectedExtras[$event.detail.id.toString()] = $event.detail; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-on:extra-removed.debounce="delete selectedExtras[$event.detail.id.toString()]; $wire.set('enabledExtras.extras', Object.assign({}, selectedExtras));"
    x-init="selectedExtras = @js($enabledExtras->extras)"
>
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
        @foreach ($extras as $category)
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
                <div wire:key="{{ $extra->id }}.{{ $extra->price }}">
                    <x-resrv::checkout-extra 
                        :extra="$extra" 
                        :selectedValue="data_get($enabledExtras->extras, $extra->id)"
                        :required="$this->extraConditions->get('required', collect())->contains($extra->id)"
                        :hide="$this->extraConditions->get('hide', collect())->contains($extra->id)"
                        x-bind:key="{{ $extra->id }}" 
                    />
                </div>
                @endforeach
            </div>
            <hr class="h-px my-4 bg-gray-100 border-0">
        </div>
        @endforeach
    </div>
</div>