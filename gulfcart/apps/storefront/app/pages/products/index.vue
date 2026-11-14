<script setup lang="ts">
import type { Category, Envelope, ProductCard } from '~/types/api'

const { request, country } = useApi()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()

const items = ref<ProductCard[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const currency = computed(() => (country.value === 'SA' ? 'SAR' : 'AED'))
const q = computed(() => String(route.query.q ?? ''))
const category = computed(() => String(route.query.category ?? ''))
const sort = computed(() => String(route.query.sort ?? 'newest'))
const priceMax = computed(() => String(route.query.price_max ?? ''))

const { data: categories } = await useAsyncData('list-categories', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })
const flat = computed(() => (categories.value ?? []).flatMap(c => [c, ...c.children]))
const currentCategory = computed(() => flat.value.find(c => c.slug === category.value))

async function load(reset: boolean) {
  loading.value = true
  try {
    const res = await request<Envelope<ProductCard[]>>('/products', {
      query: { q: q.value || undefined, category: category.value || undefined, sort: sort.value, price_max: priceMax.value || undefined, limit: 30, cursor: reset ? undefined : cursor.value ?? undefined },
    })
    items.value = reset ? res.data : [...items.value, ...res.data]
    cursor.value = res.meta.pagination?.next_cursor ?? null
  }
  finally {
    loading.value = false
  }
}

function setQuery(patch: Record<string, string | undefined>) {
  router.replace(localePath({ path: '/products', query: { ...route.query, ...patch } }))
}

onMounted(() => load(true))
watch(() => route.fullPath, () => load(true))
watch(country, () => load(true))

const prices = [50, 100, 200]
const sorts = ['newest', 'price_asc', 'price_desc']
</script>

<template>
  <div class="md:grid md:grid-cols-[220px_1fr] md:gap-6">
    <aside class="hidden space-y-6 md:block">
      <div class="card p-4">
        <p class="mb-2 text-sm font-bold">{{ $t('filters.category') }}</p>
        <button class="block w-full py-1 text-start text-sm" :class="!category ? 'font-semibold text-brand-600' : 'text-ink-700'" @click="setQuery({ category: undefined })">{{ $t('home.all') }}</button>
        <template v-for="c in categories" :key="c.id">
          <button class="block w-full py-1 text-start text-sm" :class="category === c.slug ? 'font-semibold text-brand-600' : 'text-ink-700'" @click="setQuery({ category: c.slug })">{{ c.name }}</button>
          <button v-for="ch in c.children" :key="ch.id" class="block w-full py-1 ps-4 text-start text-sm" :class="category === ch.slug ? 'font-semibold text-brand-600' : 'text-ink-500'" @click="setQuery({ category: ch.slug })">{{ ch.name }}</button>
        </template>
      </div>
      <div class="card p-4">
        <p class="mb-2 text-sm font-bold">{{ $t('filters.price') }}</p>
        <div class="flex flex-wrap gap-2">
          <button class="chip" :class="{ 'chip-active': !priceMax }" @click="setQuery({ price_max: undefined })">{{ $t('home.all') }}</button>
          <button v-for="p in prices" :key="p" class="chip" :class="{ 'chip-active': priceMax === String(p) }" @click="setQuery({ price_max: String(p) })">≤ {{ p }} {{ currency }}</button>
        </div>
      </div>
    </aside>

    <section>
      <div class="mb-3 flex flex-wrap items-center gap-2">
        <h1 class="me-auto text-lg font-bold">
          {{ q ? $t('filters.results_for', { q }) : (currentCategory?.name ?? $t('home.all')) }}
          <span class="text-sm font-normal text-ink-500">({{ items.length }}{{ cursor ? '+' : '' }})</span>
        </h1>
        <div class="no-scrollbar flex gap-2 overflow-x-auto">
          <button v-for="s in sorts" :key="s" class="chip whitespace-nowrap" :class="{ 'chip-active': sort === s }" @click="setQuery({ sort: s })">{{ $t(`catalog.sort.${s}`) }}</button>
          <button v-for="p in prices" :key="`m${p}`" class="chip whitespace-nowrap md:hidden" :class="{ 'chip-active': priceMax === String(p) }" @click="setQuery({ price_max: priceMax === String(p) ? undefined : String(p) })">≤ {{ p }}</button>
        </div>
      </div>
      <p v-if="!loading && !items.length" class="card p-10 text-center text-ink-500">{{ $t('catalog.empty') }}</p>
      <ProductGrid :products="items" :loading="loading" :skeletons="15" />
      <div class="mt-6 text-center">
        <button v-if="cursor" :disabled="loading" class="btn-outline" @click="load(false)">{{ $t('common.load_more') }}</button>
      </div>
    </section>
  </div>
</template>
