import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, request } from '@/api/client'

const okResponse = () =>
  ({ ok: true, status: 200, json: async () => ({ status: 'ok' }) }) as Response

type FetchFn = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>

const fetchStub = () => vi.fn<FetchFn>(async () => okResponse())

const headersOf = (call: [RequestInfo | URL, RequestInit?] | undefined): Headers => {
  if (!call?.[1]?.headers) throw new Error('fetch was not called with headers')
  return call[1].headers as Headers
}

afterEach(() => vi.restoreAllMocks())

describe('api client', () => {
  it('adds an Idempotency-Key to mutating requests', async () => {
    const fetchMock = fetchStub()
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('crypto', { randomUUID: () => 'fixed-uuid' })

    await request('/api/v1/discovery/run', { method: 'POST', body: '{}' })

    expect(headersOf(fetchMock.mock.calls[0]).get('Idempotency-Key')).toBe('fixed-uuid')
  })

  it('does not add an Idempotency-Key to reads', async () => {
    const fetchMock = fetchStub()
    vi.stubGlobal('fetch', fetchMock)

    await request('/api/v1/policy')

    expect(headersOf(fetchMock.mock.calls[0]).has('Idempotency-Key')).toBe(false)
  })

  it('throws ApiError with the status on failure', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 428 }) as Response))
    await expect(request('/api/v1/discovery/run', { method: 'POST' })).rejects.toBeInstanceOf(
      ApiError,
    )
  })
})

describe('uploads', () => {
  it('does not set Content-Type on multipart bodies', async () => {
    const fetchMock = fetchStub()
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('crypto', { randomUUID: () => 'fixed-uuid' })

    const body = new FormData()
    body.append('file', new Blob(['x']), 'cv.docx')
    await request('/api/v1/resumes', { method: 'POST', body })

    const headers = headersOf(fetchMock.mock.calls[0])
    // The browser has to pick the multipart boundary itself.
    expect(headers.has('Content-Type')).toBe(false)
    expect(headers.get('Idempotency-Key')).toBe('fixed-uuid')
  })

  it('surfaces the server detail message on failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({ ok: false, status: 400, json: async () => ({ detail: 'not a CV' }) }) as Response),
    )
    await expect(request('/api/v1/resumes', { method: 'POST' })).rejects.toThrow('not a CV')
  })

  it('returns nothing for a 204', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 204 }) as Response))
    await expect(request('/api/v1/profile/facts/x', { method: 'DELETE' })).resolves.toBeUndefined()
  })
})
