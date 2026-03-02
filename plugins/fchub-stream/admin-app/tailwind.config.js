/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  safelist: [
    'bg-primary-50',
    'text-primary-700',
    'border-primary-500',
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#faf5ff',
          100: '#f3e8ff',
          200: '#e9d5ff',
          300: '#d8b4fe',
          400: '#c084fc',
          500: '#a855f7',
          600: '#9333ea',
          700: '#7e22ce',
          800: '#6b21a8',
          900: '#581c87',
        },
        onprimary: '#ffffff',
        onprimarylight: 'rgba(255, 255, 255, 0.8)',
        onprimarymuted: 'rgba(255, 255, 255, 0.6)',
      },
    },
  },
  plugins: [],
}

