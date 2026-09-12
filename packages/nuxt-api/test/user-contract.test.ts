import { expect, it } from 'bun:test'
import type { components } from '../src/openapi'
import type { User } from '../src/types'

type OpenApiUser = components['schemas']['UserResource']

it('keeps the Nuxt user type compatible with nullable OpenAPI user fields', () => {
  const response: OpenApiUser = {
    uuid: '0190f912-1111-7000-8000-111111111111',
    email: 'member@example.com',
    email_verified_at: null,
    two_factor_enabled: false,
    profile: {
      first_name: null,
      last_name: null,
      phone: null,
      locale: null,
      timezone: null,
      avatar_url: null,
    },
    created_at: null,
    updated_at: null,
  }

  const user: User = response
  const roundTrip: OpenApiUser = user

  expect(roundTrip).toEqual(response)
})
