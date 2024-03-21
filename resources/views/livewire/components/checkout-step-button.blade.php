<div>
    <button
        type="button" 
        class="w-full px-6 py-3.5 text-base font-medium text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg text-center"
        wire:click="$parent.checkout()"
    >
        {{ $slot }}
    </button>
</div>