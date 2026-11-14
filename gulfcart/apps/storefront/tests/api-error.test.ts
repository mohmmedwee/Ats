import { describe, expect, it } from 'vitest'
import { apiErrorMessage } from '~/utils/api-error'

const body = (details: Record<string, unknown> = {}) => ({
  data: { success: false, error: { code: 'VALIDATION_FAILED', message: 'Invalid data.', details }, meta: { request_id: 'req_1' } },
})

describe('apiErrorMessage', () => {
  it('prefers the first field error', () => {
    expect(apiErrorMessage(body({ fields: { email: ['Email taken.'] } }), 'x')).toBe('Email taken.')
  })
  it('falls back to the error message', () => {
    expect(apiErrorMessage(body(), 'x')).toBe('Invalid data.')
  })
  it('falls back to the default for non-API errors', () => {
    expect(apiErrorMessage(new Error('boom'), 'fallback')).toBe('fallback')
  })
})
