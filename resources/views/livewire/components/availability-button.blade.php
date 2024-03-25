<div class="{{ $attributes->get('class') }}">
    <button 
        class="flex justify-center h-11 items-center text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 focus:outline-none disabled:opacity-50"
        wire:click="submit()"
        wire:loading.attr="disabled"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" width="18" height="18" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
            <circle cx="10" cy="10" r="7"></circle>
            <line x1="21" y1="21" x2="15" y2="15"></line>
        </svg>
        <span class="uppercase pl-4">{{ trans('statamic-resrv::frontend.search') }}</span>   
    </button>
</div>