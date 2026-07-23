import { useState } from "#app";
import { computed } from "vue";
import { useApi } from "./useApi.js";
function isTwoFactorResponse(response) {
  return "code" in response && response.code === "two_factor_required";
}
function responseStatus(error) {
  if (!error || typeof error !== "object") {
    return void 0;
  }
  const candidate = error;
  return candidate.response?.status ?? candidate.statusCode ?? candidate.status;
}
function clientMutationOnly() {
  if (typeof window === "undefined") {
    throw new Error(
      "Stateful authentication mutations must run in the browser so Laravel Set-Cookie headers reach the user agent."
    );
  }
}
export function useAuth() {
  const api = useApi();
  const user = useState("douwyn:auth:user", () => null);
  const initialized = useState("douwyn:auth:initialized", () => false);
  async function fetchUser() {
    try {
      const response = await api.request("/auth/me");
      user.value = response.data;
    } catch (error) {
      if (![401, 403].includes(responseStatus(error) ?? 0)) {
        throw error;
      }
      user.value = null;
    } finally {
      initialized.value = true;
    }
    return user.value;
  }
  async function login(payload) {
    clientMutationOnly();
    await api.csrf(true);
    const response = await api.request("/auth/session/login", {
      method: "POST",
      body: payload
    });
    if (isTwoFactorResponse(response)) {
      return { status: "challenge", challenge: response.data };
    }
    user.value = response.data.user;
    initialized.value = true;
    return { status: "authenticated", user: response.data.user };
  }
  async function register(payload) {
    clientMutationOnly();
    await api.csrf(true);
    const response = await api.request("/auth/session/register", {
      method: "POST",
      body: payload
    });
    user.value = response.data.user;
    initialized.value = true;
    return response.data.user;
  }
  async function verifyChallenge(payload) {
    clientMutationOnly();
    await api.csrf(true);
    const response = await api.request(
      "/auth/session/challenges/verify",
      {
        method: "POST",
        body: payload
      }
    );
    user.value = response.data.user;
    initialized.value = true;
    return response.data.user;
  }
  async function logout() {
    clientMutationOnly();
    await api.csrf(true);
    try {
      await api.request("/auth/session/logout", { method: "POST" });
    } finally {
      user.value = null;
      initialized.value = true;
    }
  }
  function clear() {
    user.value = null;
    initialized.value = true;
  }
  return {
    user,
    initialized,
    isAuthenticated: computed(() => user.value !== null),
    fetchUser,
    login,
    register,
    verifyChallenge,
    logout,
    clear,
    refreshCsrf: () => api.csrf(true)
  };
}
