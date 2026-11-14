<script setup lang="ts">
import type { Envelope, ProductCard } from '~/types/api'

const { request, country } = useApi()
const { data: products } = await useAsyncData('home-products', () => request<Envelope<ProductCard[]>>('/products', { query: { limit: 8 } }).then(r => r.data), { server: false, watch: [country], default: () => [] })
const localePath = useLocalePath()
</script>

<template>
  <div>
    <section class="py-12 text-center">
      <h1 class="text-3xl font-bold">{{ $t('home.title') }}</h1>
      <p class="mt-4 text-gray-600">{{ $t('home.subtitle') }}</p>
      <NuxtLink :to="localePath('/products')" class="mt-6 inline-block rounded bg-gray-900 px-6 py-2 text-white">{{ $t('nav.products') }}</NuxtLink>
    </section>
    <section v-if="products?.length" class="grid grid-cols-2 gap-4 md:grid-cols-4">
      <ProductCardItem v-for="p in products" :key="p.id" :product="p" />
    </section>
  </div>
</template>
