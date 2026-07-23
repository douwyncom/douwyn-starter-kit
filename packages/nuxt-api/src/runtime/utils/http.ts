const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

export function pickSsrForwardHeaders(
  headers: Readonly<Record<string, string | undefined>>,
  sessionCookieName: string,
  xsrfCookieName: string,
): Record<string, string> {
  const result: Record<string, string> = {}

  for (const [name, value] of Object.entries(headers)) {
    const normalizedName = name.toLowerCase()

    if (!value) {
      continue
    }

    if (normalizedName === 'accept-language') {
      result['accept-language'] = value
    }

    if (normalizedName === 'cookie') {
      const cookie = filterCookieHeader(value, [sessionCookieName, xsrfCookieName])

      if (cookie) {
        result.cookie = cookie
      }
    }
  }

  return result
}

export function filterCookieHeader(
  cookieHeader: string | undefined,
  allowedNames: readonly string[],
): string | undefined {
  if (!cookieHeader) {
    return undefined
  }

  const allowed = new Set(allowedNames.filter((name) => name.length > 0))
  const included = new Set<string>()
  const cookies: string[] = []

  for (const part of cookieHeader.split(';')) {
    const separator = part.indexOf('=')

    if (separator === -1) {
      continue
    }

    const name = part.slice(0, separator).trim()

    if (!allowed.has(name) || included.has(name)) {
      continue
    }

    cookies.push(`${name}=${part.slice(separator + 1).trim()}`)
    included.add(name)
  }

  return cookies.length > 0 ? cookies.join('; ') : undefined
}

export function cookieValue(cookieHeader: string | undefined, name: string): string | undefined {
  if (!cookieHeader) {
    return undefined
  }

  for (const part of cookieHeader.split(';')) {
    const separator = part.indexOf('=')

    if (separator === -1 || part.slice(0, separator).trim() !== name) {
      continue
    }

    const value = part.slice(separator + 1).trim()

    try {
      return decodeURIComponent(value)
    } catch {
      return value
    }
  }

  return undefined
}

export function isUnsafeMethod(method: string | undefined): boolean {
  return !SAFE_METHODS.has((method ?? 'GET').toUpperCase())
}

export function resolveCsrfURL(baseURL: string, configuredURL: string): string {
  if (configuredURL) {
    return configuredURL
  }

  try {
    return new URL('/sanctum/csrf-cookie', baseURL).toString()
  } catch {
    return '/sanctum/csrf-cookie'
  }
}
