<script setup lang="ts">
import type { ProductCard } from '~/types/api'

definePageMeta({ middleware: 'auth' })
const wishlist = useWishlistStore()
const { request, country } = useApi()
const localePath = useLocalePath()
const products = ref<ProductCard[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  await wishlist.load(true)
  products.value = wishlist.ids.length
    ? (await request<{ data: ProductCard[] }>('/products', { query: { ids: wishlist.ids.join(','), limit: 100 } })).data
    : []
  loading.value = false
}
onMounted(load)
watch(country, load)
watch(() => wishlist.ids.length, (n) => {
  products.value = products.value.filter(p => wishlist.ids.includes(p.id))
  if (n === 0) products.value = []
})
</script>

<template>
  <section>
    <h1 class="section-title mb-4">{{ $t('wishlist.title') }}</h1>
    <ProductGrid v-if="products.length" :products="products" />
    <div v-else-if="!loading" class="card p-10 text-center">
      <p class="mb-4 text-ink-500">{{ $t('wishlist.empty') }}</p>
      <NuxtLink :to="localePath('/products')" class="btn-primary">{{ $t('home.all') }}</NuxtLink>
    </div>
  </section>
</template>
