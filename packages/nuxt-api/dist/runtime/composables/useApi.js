import { useNuxtApp, useRuntimeConfig } from "#app";
import { $fetch } from "ofetch";
import { resolveCsrfURL } from "../utils/http.js";
let clientCsrfRequest;
export function useApi() {
  const { $douwynApi } = useNuxtApp();
  const config = useRuntimeConfig().public.douwynApi;
  async function csrf(force = false) {
    if (typeof window === "undefined") {
      throw new Error(
        "CSRF initialization must run in the browser. SSR reads may forward cookies, but auth mutations must be client-side."
      );
    }
    if (force) {
      clientCsrfRequest = void 0;
    }
    clientCsrfRequest ??= $fetch(resolveCsrfURL(config.baseURL, config.csrfURL), {
      credentials: "include",
      retry: 0,
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest"
      }
    }).then(() => void 0).catch((error) => {
      clientCsrfRequest = void 0;
      throw error;
    });
    return clientCsrfRequest;
  }
  return {
    request: (path, options) => $douwynApi(path, options),
    csrf
  };
}
