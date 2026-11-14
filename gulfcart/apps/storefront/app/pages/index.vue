<script setup lang="ts">
import type { Category, Envelope, ProductCard } from '~/types/api'

const { request, country } = useApi()
const localePath = useLocalePath()
const currency = computed(() => (country.value === 'SA' ? 'SAR' : 'AED'))

const load = (query: Record<string, string | number>) => request<Envelope<ProductCard[]>>('/products', { query }).then(r => r.data)
const { data: fresh, pending } = await useAsyncData('home-new', () => load({ limit: 10, sort: 'newest' }), { server: false, watch: [country], default: () => [] })
const { data: under } = await useAsyncData('home-under', () => load({ limit: 10, sort: 'price_asc', price_max: 100 }), { server: false, watch: [country], default: () => [] })
const { data: forYou } = await useAsyncData('home-all', () => load({ limit: 20, sort: 'price_desc' }), { server: false, watch: [country], default: () => [] })
const { data: categories } = await useAsyncData('home-cats', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })

const tones = ['from-brand-100 to-brand-200 text-brand-800', 'from-gold-100 to-gold-400 text-ink-900', 'from-ink-100 to-ink-300 text-ink-900', 'from-rose-100 to-orange-200 text-ink-900', 'from-sky-100 to-indigo-200 text-ink-900']
</script>

<template>
  <div class="space-y-6 md:space-y-8">
    <!-- hero banners -->
    <section class="grid gap-3 md:grid-cols-3">
      <NuxtLink :to="localePath('/products?sort=newest')" class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-600 via-brand-500 to-gold-400 p-6 text-white md:col-span-2 md:p-10">
        <p class="text-sm font-medium opacity-90">{{ $t('hero.kicker') }}</p>
        <h1 class="mt-2 max-w-md text-3xl font-bold leading-tight md:text-5xl">{{ $t('hero.title') }}</h1>
        <p class="mt-3 max-w-md text-sm opacity-90 md:text-base">{{ $t('hero.subtitle') }}</p>
        <span class="btn mt-6 bg-white text-brand-700 hover:bg-ink-100">{{ $t('hero.cta') }}</span>
        <div class="pointer-events-none absolute -bottom-16 -end-10 h-64 w-64 rounded-full bg-white/15" />
        <div class="pointer-events-none absolute -top-10 end-24 h-32 w-32 rounded-full bg-white/10" />
      </NuxtLink>
      <div class="grid grid-cols-2 gap-3 md:grid-cols-1">
        <NuxtLink :to="localePath({ path: '/products', query: { price_max: 100, sort: 'price_asc' } })" class="rounded-2xl bg-ink-900 p-5 text-white">
          <p class="text-xs opacity-70">{{ $t('hero.under_kicker') }}</p>
          <p class="mt-1 text-xl font-bold md:text-2xl">{{ $t('home.under', { amount: `100 ${currency}` }) }}</p>
          <span class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-gold-400">{{ $t('hero.shop_now') }} <AppIcon name="chevron" :size="16" class="rtl:rotate-180" /></span>
        </NuxtLink>
        <div class="rounded-2xl bg-gold-100 p-5">
          <p class="text-xs text-ink-700">{{ $t('hero.trust_kicker') }}</p>
          <p class="mt-1 text-lg font-bold text-ink-900">{{ $t('hero.trust_title') }}</p>
          <p class="mt-1 text-xs text-ink-700">{{ $t('promo.all_in_price') }}</p>
        </div>
      </div>
    </section>

    <!-- category circles -->
    <section class="card p-4">
      <div class="no-scrollbar flex gap-4 overflow-x-auto md:grid md:grid-cols-10 md:overflow-visible">
        <NuxtLink v-for="(c, i) in categories" :key="c.id" :to="localePath({ path: '/products', query: { category: c.slug } })" class="flex w-16 shrink-0 flex-col items-center gap-2 text-center md:w-auto">
          <span class="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br text-lg font-bold md:h-16 md:w-16" :class="tones[i % tones.length]">{{ c.name.slice(0, 1) }}</span>
          <span class="line-clamp-1 text-xs text-ink-700">{{ c.name }}</span>
        </NuxtLink>
      </div>
    </section>

    <section>
      <div class="mb-3 flex items-end justify-between">
        <h2 class="section-title">{{ $t('home.new') }}</h2>
        <NuxtLink :to="localePath('/products?sort=newest')" class="text-sm font-semibold text-brand-600">{{ $t('home.see_all') }}</NuxtLink>
      </div>
      <div class="no-scrollbar -mx-3 flex gap-2.5 overflow-x-auto px-3 md:mx-0 md:grid md:grid-cols-5 md:px-0">
        <div v-for="p in fresh" :key="p.id" class="w-36 shrink-0 md:w-auto"><ProductCardItem :product="p" /></div>
      </div>
    </section>

    <section v-if="under?.length" class="rounded-2xl bg-brand-50 p-3 md:p-5">
      <div class="mb-3 flex items-end justify-between">
        <h2 class="section-title text-brand-700">{{ $t('home.under', { amount: `100 ${currency}` }) }}</h2>
        <NuxtLink :to="localePath({ path: '/products', query: { price_max: 100, sort: 'price_asc' } })" class="text-sm font-semibold text-brand-600">{{ $t('home.see_all') }}</NuxtLink>
      </div>
      <div class="no-scrollbar flex gap-2.5 overflow-x-auto md:grid md:grid-cols-5">
        <div v-for="p in under" :key="p.id" class="w-36 shrink-0 md:w-auto"><ProductCardItem :product="p" /></div>
      </div>
    </section>

    <section>
      <h2 class="section-title mb-3">{{ $t('home.for_you') }}</h2>
      <ProductGrid :products="forYou ?? []" :loading="pending" />
    </section>
  </div>
</template>
