export function createAccountLifecycleActions(api, state, ensureClientMutation) {
  async function prepareMutation() {
    ensureClientMutation();
    await api.csrf();
  }
  async function fetchEmailStatus() {
    const response = await api.request("/account/email");
    return response.data;
  }
  async function resendEmailVerification() {
    await prepareMutation();
    const response = await api.request(
      "/account/email/verification-notification",
      { method: "POST" }
    );
    return response.message;
  }
  async function verifyEmail(payload) {
    await prepareMutation();
    const response = await api.request("/auth/email/verify", {
      method: "POST",
      body: payload
    });
    return response.message;
  }
  async function forgotPassword(payload) {
    await prepareMutation();
    const response = await api.request("/auth/password/forgot", {
      method: "POST",
      body: payload
    });
    return response.message;
  }
  async function resetPassword(payload) {
    await prepareMutation();
    const response = await api.request("/auth/password/reset", {
      method: "POST",
      body: payload
    });
    state.clearCurrentUser();
    return response.message;
  }
  async function requestEmailChange(payload) {
    await prepareMutation();
    const response = await api.request("/account/email/change", {
      method: "POST",
      body: payload
    });
    return response.message;
  }
  async function confirmEmailChange(payload) {
    await prepareMutation();
    const response = await api.request("/account/email/change/confirm", {
      method: "POST",
      body: payload
    });
    state.setCurrentUser(response.data);
    return response.data;
  }
  return {
    fetchEmailStatus,
    resendEmailVerification,
    verifyEmail,
    forgotPassword,
    resetPassword,
    requestEmailChange,
    confirmEmailChange
  };
}
