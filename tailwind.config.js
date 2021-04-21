module.exports = {
    mode: 'jit',
    purge: {
      enabled: true,
      content: [
        './resources/js/fieldtypes/*.vue',
        './resources/js/components/*.vue',
      ]
    },
    important: false,
    theme: {
        extend: {},
        variants: {},
    }
}
