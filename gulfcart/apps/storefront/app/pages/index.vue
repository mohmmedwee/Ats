<script setup lang="ts">
import type { Category, Envelope, ProductCard } from '~/types/api'

const { request, country } = useApi()
const localePath = useLocalePath()
const currency = computed(() => (country.value === 'SA' ? 'SAR' : 'AED'))

const load = (query: Record<string, string | number | undefined>) => request<Envelope<ProductCard[]>>('/products', { query }).then(r => r.data)
const { data: fresh } = await useAsyncData('home-new', () => load({ limit: 12, sort: 'newest' }), { server: false, watch: [country], default: () => [] })
const { data: categories } = await useAsyncData('home-cats', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })
const { data: featuredBrands } = await useAsyncData('home-brands', () => request<Envelope<{ id: string, slug: string, name: string, logo_url: string | null }[]>>('/brands', { query: { featured: 1 } }).then(r => r.data), { server: false, default: () => [] })

/** Tabbed feed: one list at a time, "load more" with the cursor. */
const tabs = computed(() => [
  { key: 'for_you', query: { sort: 'price_desc' } },
  { key: 'new', query: { sort: 'newest' } },
  { key: 'under', query: { sort: 'price_asc', price_max: 100 } },
  ...(categories.value ?? []).slice(0, 4).map(c => ({ key: c.slug, label: c.name, query: { category: c.slug, sort: 'newest' } })),
])
const tab = ref('for_you')
const feed = ref<ProductCard[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
async function loadFeed(reset: boolean) {
  const t = tabs.value.find(x => x.key === tab.value) ?? tabs.value[0]!
  loading.value = true
  try {
    const res = await request<Envelope<ProductCard[]>>('/products', { query: { ...t.query, limit: 20, cursor: reset ? undefined : cursor.value ?? undefined } })
    feed.value = reset ? res.data : [...feed.value, ...res.data]
    cursor.value = res.meta.pagination?.next_cursor ?? null
  }
  finally {
    loading.value = false
  }
}
onMounted(() => loadFeed(true))
watch([tab, country], () => loadFeed(true))

const tiles = computed(() => [
  { key: 'new', to: { path: '/products', query: { sort: 'newest' } }, cls: 'bg-[#efe7df] text-ink-900' },
  { key: 'under', to: { path: '/products', query: { price_max: '100', sort: 'price_asc' } }, cls: 'bg-brand-50 text-brand-700' },
  { key: 'tech', to: { path: '/products', query: { category: 'electronics' } }, cls: 'bg-ink-900 text-white' },
  { key: 'brands', to: { path: '/brands' }, cls: 'bg-gold-100 text-ink-900' },
])
</script>

<template>
  <div class="-mx-3 -mt-4 space-y-7 md:-mx-4 md:-mt-6 md:space-y-10">
    <HeroCarousel :products="fresh ?? []" />

    <!-- category circles with photos -->
    <section class="px-3 md:px-4" data-test="home-categories">
      <div class="no-scrollbar flex gap-4 overflow-x-auto md:justify-center md:gap-7">
        <NuxtLink v-for="c in categories" :key="c.id" :to="localePath({ path: '/products', query: { category: c.slug } })" class="group flex w-[72px] shrink-0 flex-col items-center gap-1.5 text-center md:w-20">
          <span class="h-[68px] w-[68px] overflow-hidden rounded-full bg-ink-100 ring-1 ring-ink-100 transition group-hover:ring-2 group-hover:ring-ink-900 md:h-20 md:w-20">
            <img v-if="c.image_url" :src="c.image_url" :alt="c.name" class="h-full w-full object-cover">
            <span v-else class="grid h-full w-full place-items-center text-lg font-bold text-ink-500">{{ c.name.slice(0, 1) }}</span>
          </span>
          <span class="line-clamp-1 text-[11px] font-medium text-ink-700 md:text-xs">{{ c.name }}</span>
        </NuxtLink>
      </div>
    </section>

    <!-- promo tiles: only real offers -->
    <section class="grid grid-cols-2 gap-2 px-3 md:grid-cols-4 md:gap-3 md:px-4">
      <NuxtLink v-for="t in tiles" :key="t.key" :to="localePath(t.to)" class="flex min-h-[92px] flex-col justify-between rounded-md p-3.5 md:min-h-[120px] md:p-5" :class="t.cls">
        <span class="text-[11px] font-bold uppercase tracking-widest opacity-75">{{ $t(`tiles.${t.key}_kicker`) }}</span>
        <span class="text-base font-black leading-tight md:text-xl">{{ $t(`tiles.${t.key}`, { amount: `100 ${currency}` }) }}</span>
      </NuxtLink>
    </section>

    <!-- trending row -->
    <section v-if="fresh?.length" class="px-3 md:px-4">
      <div class="mb-3 flex items-end justify-between">
        <h2 class="section-title">{{ $t('home.trending') }}</h2>
        <NuxtLink :to="localePath('/products?sort=newest')" class="text-xs font-bold underline underline-offset-4">{{ $t('home.see_all') }}</NuxtLink>
      </div>
      <div class="no-scrollbar -mx-3 flex gap-2 overflow-x-auto px-3 md:mx-0 md:grid md:grid-cols-6 md:gap-3 md:px-0">
        <div v-for="p in fresh" :key="p.id" class="w-[132px] shrink-0 md:w-auto"><ProductCardItem :product="p" /></div>
      </div>
    </section>

    <!-- brands -->
    <section v-if="featuredBrands?.length" class="bg-ink-100/60 px-3 py-5 md:px-4" data-test="home-brands">
      <div class="mb-3 flex items-end justify-between">
        <h2 class="section-title">{{ $t('brands.featured') }}</h2>
        <NuxtLink :to="localePath('/brands')" class="text-xs font-bold underline underline-offset-4">{{ $t('brands.all') }}</NuxtLink>
      </div>
      <div class="no-scrollbar flex gap-2.5 overflow-x-auto">
        <NuxtLink v-for="b in featuredBrands" :key="b.id" :to="localePath({ path: '/products', query: { brand: b.slug } })" class="flex h-16 w-28 shrink-0 items-center justify-center rounded-md bg-white px-3 transition hover:shadow-md md:h-20 md:w-36">
          <img v-if="b.logo_url" :src="b.logo_url" :alt="b.name" class="max-h-full max-w-full object-contain">
          <span v-else class="text-sm font-black uppercase tracking-wider text-ink-900">{{ b.name }}</span>
        </NuxtLink>
      </div>
    </section>

    <!-- tabbed feed -->
    <section class="px-3 md:px-4">
      <div class="no-scrollbar sticky top-[104px] z-20 -mx-3 mb-3 flex gap-5 overflow-x-auto border-b border-ink-100 bg-white px-3 md:top-[120px] md:mx-0 md:px-0" data-test="feed-tabs">
        <button
          v-for="t in tabs" :key="t.key" type="button" class="whitespace-nowrap border-b-2 py-2.5 text-sm transition"
          :class="tab === t.key ? 'border-ink-900 font-bold text-ink-900' : 'border-transparent text-ink-500'"
          @click="tab = t.key"
        >
          {{ 'label' in t ? t.label : $t(`home.tabs.${t.key}`, { amount: `100 ${currency}` }) }}
        </button>
      </div>
      <ProductGrid :products="feed" :loading="loading" />
      <div class="mt-6 text-center">
        <button v-if="cursor" :disabled="loading" class="btn-outline min-w-48" @click="loadFeed(false)">{{ $t('common.load_more') }}</button>
      </div>
    </section>
  </div>
</template>
