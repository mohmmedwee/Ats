export interface ApiErrorBody {
  success: false
  error: { code: string, message: string, details?: { fields?: Record<string, string[]> } & Record<string, unknown> }
  meta: { request_id: string }
}

export function isApiErrorBody(value: unknown): value is ApiErrorBody {
  return typeof value === 'object' && value !== null
    && (value as { success?: unknown }).success === false
    && typeof (value as { error?: { code?: unknown } }).error?.code === 'string'
}

/** First human-readable message from an API error, or `fallback`. */
export function apiErrorMessage(error: unknown, fallback: string): string {
  const data = (error as { data?: unknown })?.data
  if (!isApiErrorBody(data)) return fallback
  const fields = data.error.details?.fields
  const firstField = fields ? Object.values(fields)[0]?.[0] : undefined
  return firstField ?? data.error.message ?? fallback
}
