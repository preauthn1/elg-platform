import { createRequire } from 'node:module'
import { dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

const r = (p: string) => fileURLToPath(new URL(p, import.meta.url))

const require_ = createRequire(import.meta.url)
const viaNuxt = createRequire(require_.resolve('nuxt/package.json'))
const pkgDir = (name: string) => dirname(viaNuxt.resolve(`${name}/package.json`))

export default defineConfig({
  resolve: {
    alias: {
      '#shared': r('./shared'),
      '~~': r('.'),
      '~': r('./app'),
      'vue': pkgDir('vue'),
      'h3': pkgDir('h3'),
    },
  },
  test: {
    environment: 'node',
    include: ['tests/unit/**/*.spec.ts'],
    reporters: ['default'],
    passWithNoTests: false,
  },
})
