import { useState } from "#app";
import { createAccountLifecycleActions } from "../utils/accountLifecycle.js";
import { useApi } from "./useApi.js";
function clientMutationOnly() {
  if (typeof window === "undefined") {
    throw new Error(
      "Account lifecycle mutations must run in the browser so CSRF and Laravel session cookies reach the user agent."
    );
  }
}
export function useAccountLifecycle() {
  const api = useApi();
  const user = useState("douwyn:auth:user", () => null);
  const initialized = useState("douwyn:auth:initialized", () => false);
  return createAccountLifecycleActions(
    api,
    {
      setCurrentUser(currentUser) {
        user.value = currentUser;
        initialized.value = true;
      },
      clearCurrentUser() {
        user.value = null;
        initialized.value = true;
      }
    },
    clientMutationOnly
  );
}
