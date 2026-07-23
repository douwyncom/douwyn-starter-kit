const SAFE_METHODS = /* @__PURE__ */ new Set(["GET", "HEAD", "OPTIONS"]);
export function pickSsrForwardHeaders(headers, sessionCookieName, xsrfCookieName) {
  const result = {};
  for (const [name, value] of Object.entries(headers)) {
    const normalizedName = name.toLowerCase();
    if (!value) {
      continue;
    }
    if (normalizedName === "accept-language") {
      result["accept-language"] = value;
    }
    if (normalizedName === "cookie") {
      const cookie = filterCookieHeader(value, [sessionCookieName, xsrfCookieName]);
      if (cookie) {
        result.cookie = cookie;
      }
    }
  }
  return result;
}
export function filterCookieHeader(cookieHeader, allowedNames) {
  if (!cookieHeader) {
    return void 0;
  }
  const allowed = new Set(allowedNames.filter((name) => name.length > 0));
  const included = /* @__PURE__ */ new Set();
  const cookies = [];
  for (const part of cookieHeader.split(";")) {
    const separator = part.indexOf("=");
    if (separator === -1) {
      continue;
    }
    const name = part.slice(0, separator).trim();
    if (!allowed.has(name) || included.has(name)) {
      continue;
    }
    cookies.push(`${name}=${part.slice(separator + 1).trim()}`);
    included.add(name);
  }
  return cookies.length > 0 ? cookies.join("; ") : void 0;
}
export function cookieValue(cookieHeader, name) {
  if (!cookieHeader) {
    return void 0;
  }
  for (const part of cookieHeader.split(";")) {
    const separator = part.indexOf("=");
    if (separator === -1 || part.slice(0, separator).trim() !== name) {
      continue;
    }
    const value = part.slice(separator + 1).trim();
    try {
      return decodeURIComponent(value);
    } catch {
      return value;
    }
  }
  return void 0;
}
export function isUnsafeMethod(method) {
  return !SAFE_METHODS.has((method ?? "GET").toUpperCase());
}
export function resolveCsrfURL(baseURL, configuredURL) {
  if (configuredURL) {
    return configuredURL;
  }
  try {
    return new URL("/sanctum/csrf-cookie", baseURL).toString();
  } catch {
    return "/sanctum/csrf-cookie";
  }
}
