import { defineNuxtModule, createResolver, addPlugin, addImportsDir, addTypeTemplate } from '@nuxt/kit';

const module$1 = defineNuxtModule({
  meta: {
    name: "@douwyn/nuxt-api",
    configKey: "douwynApi",
    compatibility: {
      nuxt: "^4.0.0"
    }
  },
  defaults: {
    baseURL: "/api/v1",
    csrfURL: "",
    xsrfCookieName: "XSRF-TOKEN",
    sessionCookieName: "douwyn-starter-kit-session"
  },
  setup(options, nuxt) {
    const resolver = createResolver(import.meta.url);
    const existing = nuxt.options.runtimeConfig.public.douwynApi;
    nuxt.options.runtimeConfig.public.douwynApi = {
      baseURL: existing?.baseURL ?? options.baseURL,
      csrfURL: existing?.csrfURL ?? options.csrfURL,
      xsrfCookieName: existing?.xsrfCookieName ?? options.xsrfCookieName,
      sessionCookieName: existing?.sessionCookieName ?? options.sessionCookieName
    };
    addPlugin(resolver.resolve("./runtime/plugin"));
    addImportsDir(resolver.resolve("./runtime/composables"));
    addTypeTemplate({
      filename: "types/douwyn-api.d.ts",
      getContents: () => `
import type { $Fetch } from 'ofetch'

declare module '#app' {
  interface NuxtApp {
    $douwynApi: $Fetch
  }

  interface PublicRuntimeConfig {
    douwynApi: {
      baseURL: string
      csrfURL: string
      xsrfCookieName: string
      sessionCookieName: string
    }
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $douwynApi: $Fetch
  }
}

export {}
`
    });
  }
});

export { module$1 as default };
