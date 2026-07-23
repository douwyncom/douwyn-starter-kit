import { defineBuildConfig } from 'unbuild'

export default defineBuildConfig({
  clean: true,
  declaration: false,
  failOnWarn: false,
  entries: [
    'src/module',
    'src/types',
    'src/openapi',
    {
      builder: 'mkdist',
      input: 'src/runtime',
      outDir: 'dist/runtime',
      format: 'esm',
      ext: 'js',
      declaration: false,
    },
  ],
  externals: ['#app', '@nuxt/kit', 'nuxt', 'ofetch', 'vue'],
  rollup: {
    emitCJS: false,
  },
})
