import { defineStore } from 'pinia'

/** Saved product IDs for the signed-in customer. Guests are sent to sign in first. */
export const useWishlistStore = defineStore('wishlist', () => {
  const { request, csrf } = useApi()
  const ids = ref<string[]>([])
  const loadedFor = ref<string | null>(null)

  async function load(force = false) {
    const auth = useAuthStore()
    if (!auth.user) {
      ids.value = []
      loadedFor.value = null
      return
    }
    if (!force && loadedFor.value === auth.user.id) return
    ids.value = (await request<{ data: string[] }>('/wishlist')).data
    loadedFor.value = auth.user.id
  }

  const has = (id: string) => ids.value.includes(id)

  /** @returns false when the visitor must sign in first */
  async function toggle(id: string): Promise<boolean> {
    const auth = useAuthStore()
    if (!auth.loaded) await auth.fetchMe()
    if (!auth.user) return false
    await load()
    await csrf()
    if (has(id)) {
      ids.value = ids.value.filter(x => x !== id)
      await request(`/wishlist/${id}`, { method: 'DELETE' }).catch(() => load(true))
    }
    else {
      ids.value = [id, ...ids.value]
      await request('/wishlist', { method: 'POST', body: { product_id: id } }).catch(() => load(true))
    }
    return true
  }

  return { ids, has, load, toggle }
})
