<div>
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
            <livewire:option :option="$option" :alreadySelected="$this->findAlreadySelected($option['id'])" :key="$id" />
        @endforeach
    </div>
</div>