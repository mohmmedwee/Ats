import { defineStore } from 'pinia'
import type { Cart, Envelope } from '~/types/api'

export const useCartStore = defineStore('cart', () => {
  const { request, csrf, cartToken } = useApi()
  const cart = ref<Cart | null>(null)
  const busy = ref(false)

  const count = computed(() => cart.value?.item_count ?? 0)

  function apply(next: Cart) {
    cart.value = next
    // Guests get a token; signed-in carts don't (the guest cart was merged).
    cartToken.value = next.guest_token ?? (next.id ? null : cartToken.value)
  }

  async function load() {
    const res = await request<Envelope<Cart>>('/cart')
    apply(res.data)
  }

  async function add(variantId: string, quantity = 1) {
    busy.value = true
    try {
      await csrf()
      apply((await request<Envelope<Cart>>('/cart/items', { method: 'POST', body: { variant_id: variantId, quantity } })).data)
    }
    finally {
      busy.value = false
    }
  }

  async function update(itemId: string, quantity: number) {
    apply((await request<Envelope<Cart>>(`/cart/items/${itemId}`, { method: 'PATCH', body: { quantity } })).data)
  }

  async function remove(itemId: string) {
    apply((await request<Envelope<Cart>>(`/cart/items/${itemId}`, { method: 'DELETE' })).data)
  }

  async function validate() {
    apply((await request<Envelope<Cart>>('/cart/validate', { method: 'POST' })).data)
  }

  return { cart, busy, count, load, add, update, remove, validate }
})
