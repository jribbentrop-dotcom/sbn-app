import type { Config } from 'tailwindcss'

export default {
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/**/*.blade.php',
    './resources/**/*.ts',
    './resources/**/*.vue',
  ],
  theme: {
    extend: {
      colors: {
        bg: 'var(--clr-bg)',
        surface: 'var(--clr-surface)',
        'surface-2': 'var(--clr-surface-2)',
        'surface-3': 'var(--clr-surface-3)',
        accent: 'var(--clr-accent)',
        'accent-dim': 'var(--clr-accent-dim)',
        'accent-bg': 'var(--clr-accent-bg)',
        'accent-border': 'var(--clr-accent-border)',
        text: 'var(--clr-text)',
        'text-dim': 'var(--clr-text-dim)',
        'text-muted': 'var(--clr-text-muted)',
        success: 'var(--clr-success)',
        warning: 'var(--clr-warning)',
        error: 'var(--clr-error)',
      },
      fontFamily: {
        sans: ['"DM Sans"', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'monospace'],
        chord: ['"Crimson Text"', 'serif'],
      }
    },
  },
  plugins: [],
} satisfies Config

