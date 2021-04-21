const mix = require('laravel-mix');

mix.js('resources/js/resrv.js', 'public/js').vue({ version: 2 })

mix.postCss('resources/css/resrv.css', 'public/css', [
    require('postcss-import'),
    require('tailwindcss'),
    require('postcss-nested'),
    require('postcss-preset-env')({
        stage: 0,
        features: {
            'focus-within-pseudo-class': false
        }
    })
])