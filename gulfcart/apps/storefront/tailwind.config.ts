import type { Config } from 'tailwindcss'

// GulfCart identity: rose primary, warm gold accent, near-black ink.
export default {
  theme: {
    extend: {
      colors: {
        brand: { 50: '#FFF1F4', 100: '#FFE0E8', 200: '#FFC2D2', 500: '#F0457A', 600: '#E0245E', 700: '#BC1A4D', 800: '#9A1640' },
        gold: { 100: '#FFF6D6', 400: '#F7C948', 500: '#F2B705', 600: '#C99400' },
        ink: { 900: '#14121A', 700: '#3A3742', 500: '#6B6876', 300: '#B9B6C2', 100: '#F2F1F5' },
      },
      fontFamily: {
        sans: ['"IBM Plex Sans Arabic"', 'Inter', 'system-ui', 'sans-serif'],
      },
      boxShadow: { card: '0 1px 2px rgba(20,18,26,.06), 0 4px 16px rgba(20,18,26,.06)' },
    },
  },
} satisfies Partial<Config>
