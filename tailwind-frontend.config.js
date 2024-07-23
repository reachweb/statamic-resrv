export default {
    content: [
      './resources/views/livewire/**/*.blade.php',
    ],

    plugins: [
      require("@tailwindcss/forms")({
          strategy: 'class'
      }),
  ],
}
