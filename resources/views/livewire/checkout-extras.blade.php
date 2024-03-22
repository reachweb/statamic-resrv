<div>
    <div class="mt-6 xl:mt-8">
        <div class="text-lg xl:text-xl font-medium mb-2">
            {{ trans('statamic-resrv::frontend.extras') }}
        </div>
        <div class="text-gray-700">
            {{ trans('statamic-resrv::frontend.extrasDescription') }}
        </div>
    </div>
    <hr class="h-px my-4 bg-gray-200 border-0">
    <div class="divide-y divide-gray-100">
        @foreach ($this->extras as $id => $extra)
            <livewire:extra :extra="$extra" :alreadySelected="$this->findAlreadySelected($extra->id)" :key="$id" />
        @endforeach
    </div>
</div>