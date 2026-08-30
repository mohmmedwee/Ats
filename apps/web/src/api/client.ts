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
    headers.set('Content-Type', 'application/json')
    if (!headers.has('Idempotency-Key')) {
      headers.set('Idempotency-Key', crypto.randomUUID())
    }
  }

  const response = await fetch(path, { ...init, headers })
  if (!response.ok) {
    throw new ApiError(`${method} ${path} failed`, response.status)
  }
  return (await response.json()) as T
}

export const api = {
  health: () => request<{ status: string }>('/health'),
  policy: () => request<PolicyResponse>('/api/v1/policy'),
  chatTools: () => request<ChatToolsResponse>('/api/v1/chat/tools'),
}

export { request }
