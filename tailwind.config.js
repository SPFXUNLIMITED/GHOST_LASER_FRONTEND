module.exports = {
  content: [
    './*.php',
    './admin/**/*.php',
    './assets/**/*.js',
    './old/**/*.php',
    './templates/**/*.php',
    './technician/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        cyan: {
          400: '#22d3ee',
          500: '#06b6d4',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
      backgroundImage: {
        'grid-pattern': 'linear-gradient(rgba(6,182,212,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(6,182,212,0.05) 1px, transparent 1px)',
      },
      backgroundSize: {
        grid: '60px 60px',
      },
    },
  },
  plugins: [],
};
