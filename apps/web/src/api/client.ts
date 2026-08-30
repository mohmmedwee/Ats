/**
 * Thin API client.
 *
 * Every mutating call carries an Idempotency-Key: the server rejects the request
 * without one (see the API's IdempotencyMiddleware), which keeps a double-clicked
 * button from becoming two external actions.
 */

export interface ChatTool {
  name: string
  tier: string
  description: string
}

export interface ExternalAction {
  name: string
  deep_link: string
  callable_from_chat: boolean
}

export interface ChatToolsResponse {
  tools: ChatTool[]
  tiers: Record<string, string>
  external_actions: ExternalAction[]
}

export interface PolicyResponse {
  autonomy_level: number
  autonomy_name: string
  auto_submit_enabled: boolean
  max_applications_per_day: number
  discovery_cron: string
  discovery_timezone: string
  chat: {
    daily_token_budget: number
    max_tool_calls_per_turn: number
    confirmation_ttl_seconds: number
  }
}

export type Provenance = 'user_confirmed' | 'cv_derived' | 'generated_draft'

export interface Fact {
  id: string
  kind: string
  value: string
  provenance: Provenance
  evidence_ref: string | null
  confirmed_at: string | null
  sort_order: number
}

export interface Profile {
  id: string
  headline: string | null
  location: string | null
  years_experience: number | null
  preferences: Record<string, unknown>
  locked_fields: string[]
  version: number
  facts: Fact[]
}

export interface ParseReport {
  resume_id: string
  status: string
  facts_added: number
  facts_withdrawn: number
  facts_kept: number
  rejected: { kind: string; value: string }[]
  error: string | null
}

export interface Resume {
  id: string
  filename: string
  byte_size: number
  parse_status: string
  parse_error: string | null
  is_primary: boolean
  created_at: string
}

export interface Answer {
  id: string
  question: string
  question_key: string
  answer: string
  provenance: Provenance
  confirmed_at: string | null
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (method !== 'GET' && method !== 'HEAD') {
    // Let the browser set the multipart boundary for uploads; overriding
    // Content-Type here would make the body unparseable on the server.
    if (!(init.body instanceof FormData)) {
      headers.set('Content-Type', 'application/json')
    }
    if (!headers.has('Idempotency-Key')) {
      headers.set('Idempotency-Key', crypto.randomUUID())
    }
  }

  const response = await fetch(path, { ...init, headers })
  if (!response.ok) {
    let detail: unknown
    try {
      detail = (await response.json())?.detail
    } catch {
      detail = undefined
    }
    throw new ApiError(
      typeof detail === 'string' ? detail : `${method} ${path} failed`,
      response.status,
    )
  }
  if (response.status === 204) {
    return undefined as T
  }
  return (await response.json()) as T
}

export const api = {
  health: () => request<{ status: string }>('/health'),
  policy: () => request<PolicyResponse>('/api/v1/policy'),
  chatTools: () => request<ChatToolsResponse>('/api/v1/chat/tools'),

  profile: () => request<Profile>('/api/v1/profile'),
  updateProfile: (body: Partial<Pick<Profile, 'headline' | 'location' | 'years_experience'>>) =>
    request<Profile>('/api/v1/profile', { method: 'PATCH', body: JSON.stringify(body) }),

  resumes: () => request<Resume[]>('/api/v1/resumes'),
  uploadResume: (file: File) => {
    const body = new FormData()
    body.append('file', file)
    return request<ParseReport>('/api/v1/resumes', { method: 'POST', body })
  },
  reparseResume: (id: string) =>
    request<ParseReport>(`/api/v1/resumes/${id}/parse`, { method: 'POST' }),

  confirmFact: (id: string) =>
    request<Fact>(`/api/v1/profile/facts/${id}/confirm`, { method: 'POST' }),
  deleteFact: (id: string) => request<void>(`/api/v1/profile/facts/${id}`, { method: 'DELETE' }),
  createFact: (kind: string, value: string) =>
    request<Fact>('/api/v1/profile/facts', {
      method: 'POST',
      body: JSON.stringify({ kind, value }),
    }),

  answers: () => request<Answer[]>('/api/v1/answers'),
  saveAnswer: (question: string, answer: string, confirmed = true) =>
    request<Answer>('/api/v1/answers', {
      method: 'POST',
      body: JSON.stringify({ question, answer, confirmed }),
    }),
}

export { request }
