import { defineConfig } from 'tsup'

export default defineConfig([
  {
    entry: { index: 'src/index.ts' },
    format: ['cjs'],
    outDir: 'dist/cjs',
    splitting: false,
    sourcemap: true,
    clean: false,
    outExtension: () => ({ js: '.js' }),
  },
  {
    entry: { index: 'src/index.ts' },
    format: ['esm'],
    outDir: 'dist/esm',
    splitting: false,
    sourcemap: true,
    clean: false,
    outExtension: () => ({ js: '.js' }),
  },
])
