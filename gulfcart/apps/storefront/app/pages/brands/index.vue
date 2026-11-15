<script setup lang="ts">
import type { Envelope } from '~/types/api'

interface Brand { id: string, slug: string, name: string, logo_url: string | null, authorized: boolean, featured: boolean, product_count: number }
const { request } = useApi()
const localePath = useLocalePath()
const { t } = useI18n()
const { data: brands } = await useAsyncData('brands', () => request<Envelope<Brand[]>>('/brands').then(r => r.data), { server: false, default: () => [] })
const featured = computed(() => (brands.value ?? []).filter(b => b.featured))
/** A–Z (Latin initial) groups; names starting with Arabic letters go under their own letter. */
const groups = computed(() => {
  const map = new Map<string, Brand[]>()
  for (const b of brands.value ?? []) {
    const letter = b.name.trim().charAt(0).toUpperCase() || '#'
    map.set(letter, [...(map.get(letter) ?? []), b])
  }
  return [...map.entries()].sort(([a], [b]) => a.localeCompare(b))
})
useHead({ title: `${t('brands.title')} · GulfCart` })
</script>

<template>
  <section>
    <h1 class="section-title mb-4">{{ $t('brands.title') }}</h1>

    <div v-if="featured.length" class="mb-8 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6" data-test="featured-brands">
      <NuxtLink v-for="b in featured" :key="b.id" :to="localePath({ path: '/products', query: { brand: b.slug } })" class="card flex aspect-square flex-col items-center justify-center gap-2 p-3 text-center transition hover:shadow-md">
        <img v-if="b.logo_url" :src="b.logo_url" :alt="b.name" class="h-12 w-full object-contain">
        <span v-else class="grid h-12 w-12 place-items-center rounded-full bg-ink-100 text-lg font-bold text-ink-700">{{ b.name.slice(0, 2) }}</span>
        <span class="text-sm font-semibold">{{ b.name }}</span>
      </NuxtLink>
    </div>

    <div class="space-y-6">
      <div v-for="[letter, list] in groups" :key="letter">
        <h2 class="mb-2 border-b border-ink-100 pb-1 text-lg font-bold text-ink-500">{{ letter }}</h2>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-5">
          <NuxtLink v-for="b in list" :key="b.id" :to="localePath({ path: '/products', query: { brand: b.slug } })" class="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm hover:bg-white" data-test="brand-link">
            <span>{{ b.name }}<span v-if="b.authorized" class="ms-1 text-green-700" :title="$t('brands.authorized')">✓</span></span>
            <span class="text-xs text-ink-500">{{ b.product_count }}</span>
          </NuxtLink>
        </div>
      </div>
    </div>
    <p v-if="!brands?.length" class="card p-10 text-center text-ink-500">{{ $t('common.none') }}</p>
  </section>
</template>
