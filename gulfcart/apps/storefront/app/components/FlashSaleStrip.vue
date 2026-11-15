<script setup lang="ts">
import type { Envelope, ProductCard } from '~/types/api'

/** Home-page block shown only while a flash sale is running: title, countdown and the products on sale. */
interface Sale { id: string, name: string, percent_off: string, ends_at: string }
const { request, country } = useApi()
const localePath = useLocalePath()
const sale = ref<Sale | null>(null)
const products = ref<ProductCard[]>([])

async function load() {
  const sales = (await request<Envelope<Sale[]>>('/flash-sales/current').catch(() => null))?.data ?? []
  sale.value = sales[0] ?? null
  products.value = sale.value ? (await request<Envelope<ProductCard[]>>('/products', { query: { sale: 1, limit: 12 } })).data : []
}
onMounted(load)
watch(country, load)
</script>

<template>
  <section v-if="sale && products.length" class="px-3 md:px-4" data-test="flash-sale">
    <div class="rounded-md bg-brand-600 p-3 text-white md:p-5">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-3">
          <span class="text-xl">⚡</span>
          <div>
            <h2 class="text-base font-black uppercase tracking-wide md:text-xl">{{ sale.name }}</h2>
            <p class="text-xs opacity-90">{{ $t('flash.up_to', { n: Number(sale.percent_off) }) }}</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <span class="text-xs font-semibold opacity-90">{{ $t('flash.ends_in') }}</span>
          <SaleCountdown :ends="sale.ends_at" dark @ended="load" />
          <NuxtLink :to="localePath({ path: '/products', query: { sale: '1' } })" class="hidden text-xs font-bold underline underline-offset-4 md:inline">{{ $t('home.see_all') }}</NuxtLink>
        </div>
      </div>
      <div class="no-scrollbar -mx-3 flex gap-2 overflow-x-auto px-3 md:mx-0 md:grid md:grid-cols-6 md:gap-3 md:px-0">
        <div v-for="p in products" :key="p.id" class="w-[132px] shrink-0 rounded-md bg-white p-1.5 text-ink-900 md:w-auto"><ProductCardItem :product="p" /></div>
      </div>
    </div>
  </section>
</template>
