import { defineStore } from 'pinia'

export interface User {
  id: string
  name: string
  email: string | null
  phone: string | null
  type: 'customer' | 'staff'
  roles: string[]
  permissions: string[]
  mfa_enabled: boolean
  email_verified?: boolean
  phone_verified?: boolean
}

interface Envelope<T> { success: true, data: T, meta: { request_id: string } }
interface LoginResult { user: User, mfa_required: boolean, mfa_setup_required: boolean }

export const useAuthStore = defineStore('auth', () => {
  const { request, csrf } = useApi()
  const user = ref<User | null>(null)
  const mfaPending = ref(false)
  const loaded = ref(false)

  const isStaff = computed(() => user.value?.type === 'staff')
  const can = (permission: string) => user.value?.permissions.includes(permission) ?? false

  async function fetchMe() {
    try {
      const res = await request<Envelope<User>>('/auth/me')
      user.value = res.data
    }
    catch {
      user.value = null
    }
    finally {
      loaded.value = true
    }
  }

  async function login(login: string, password: string) {
    await csrf()
    const res = await request<Envelope<LoginResult>>('/auth/login', { method: 'POST', body: { login, password } })
    user.value = res.data.user
    mfaPending.value = res.data.mfa_required
    return res.data
  }

  async function register(payload: { name: string, email: string, phone?: string, password: string, password_confirmation: string }) {
    await csrf()
    const res = await request<Envelope<User>>('/auth/register', { method: 'POST', body: payload })
    user.value = res.data
  }

  async function mfaChallenge(code: string) {
    await request('/auth/mfa/challenge', { method: 'POST', body: { code } })
    mfaPending.value = false
  }

  async function logout() {
    await request('/auth/logout', { method: 'POST' }).catch(() => undefined)
    user.value = null
    mfaPending.value = false
  }

  return { user, mfaPending, loaded, isStaff, can, fetchMe, login, register, mfaChallenge, logout }
})
