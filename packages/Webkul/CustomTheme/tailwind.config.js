export default {
    content: [
        './src/Resources/views/**/*.blade.php',
        './src/Resources/assets/js/**/*.js',
        '../../../resources/themes/custom-theme/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                zinc950: '#09090b',
                cyan400: '#22d3ee',
                cyan500: '#06b6d4',
                violet500: '#8b5cf6',
                navyBlue: '#060C3B',
                lightOrange: '#F6F2EB',
                darkGreen: '#40994A',
                darkBlue: '#0044F2',
                darkPink: '#F85156',
            },

            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
            },

            backgroundImage: {
                'grid-pattern':
                    'linear-gradient(rgba(6,182,212,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(6,182,212,0.05) 1px, transparent 1px)',
            },

            backgroundSize: {
                grid: '60px 60px',
            },
        },
    },

    plugins: [],
};
