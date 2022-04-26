module.exports = {
    mode: 'jit',
    purge: {
      enabled: true,
      content: [
        './resources/js/fieldtypes/*.vue',
        './resources/js/components/*.vue',
        './resources/views/cp/*/*.blade.php',
      ]
    },
    important: false,
    theme: {
        extend: {},
        variants: {},
    }
}
