<script setup lang="ts">
import type { Category, Envelope, ProductCard } from '~/types/api'

interface Facets { brands: { slug: string, name: string, count: number }[], price: { min: string | null, max: string | null, currency: string } }
interface BrandInfo { slug: string, name: string, logo_url: string | null, authorized: boolean, description: string | null }

const { request, country } = useApi()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()

const items = ref<ProductCard[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const facets = ref<Facets | null>(null)
const brandInfo = ref<BrandInfo | null>(null)
const filtersOpen = ref(false)
const currency = computed(() => currencyOf(country.value))
const q = computed(() => String(route.query.q ?? ''))
const category = computed(() => String(route.query.category ?? ''))
const brands = computed(() => String(route.query.brand ?? '').split(',').filter(Boolean))
const sort = computed(() => String(route.query.sort ?? 'newest'))
const priceMax = computed(() => String(route.query.price_max ?? ''))

const { data: categories } = await useAsyncData('list-categories', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })
const flat = computed(() => {
  const out: Category[] = []
  const walk = (list: Category[]) => list.forEach((c) => {
    out.push(c)
    walk(c.children)
  })
  walk(categories.value ?? [])
  return out
})
const currentCategory = computed(() => flat.value.find(c => c.slug === category.value))
const parentOfCurrent = computed(() => flat.value.find(c => c.children.some(ch => ch.slug === category.value)))
/** Chips under the title: children of the current category, or its siblings when it has none. */
const subChips = computed(() => currentCategory.value?.children.length ? currentCategory.value.children : (parentOfCurrent.value?.children ?? []))

async function load(reset: boolean) {
  loading.value = true
  try {
    const res = await request<Envelope<ProductCard[]>>('/products', {
      query: { q: q.value || undefined, category: category.value || undefined, brand: brands.value.join(',') || undefined, sort: sort.value, price_max: priceMax.value || undefined, limit: 30, cursor: reset ? undefined : cursor.value ?? undefined },
    })
    items.value = reset ? res.data : [...items.value, ...res.data]
    cursor.value = res.meta.pagination?.next_cursor ?? null
  }
  finally {
    loading.value = false
  }
}
async function loadFacets() {
  facets.value = (await request<Envelope<Facets>>('/products/facets', { query: { q: q.value || undefined, category: category.value || undefined } }).catch(() => null))?.data ?? null
}
async function loadBrand() {
  brandInfo.value = brands.value.length === 1
    ? (await request<Envelope<BrandInfo>>(`/brands/${brands.value[0]}`).catch(() => null))?.data ?? null
    : null
}

function setQuery(patch: Record<string, string | undefined>) {
  router.replace(localePath({ path: '/products', query: { ...route.query, ...patch } }))
}
function toggleBrand(slug: string) {
  const next = brands.value.includes(slug) ? brands.value.filter(b => b !== slug) : [...brands.value, slug]
  setQuery({ brand: next.join(',') || undefined })
}

function refresh() {
  load(true)
  loadFacets()
  loadBrand()
}
onMounted(refresh)
watch(() => route.fullPath, refresh)
watch(country, refresh)

const prices = [50, 100, 200, 500, 1000]
const sorts = ['newest', 'price_asc', 'price_desc']
const title = computed(() => q.value ? null : (brandInfo.value?.name ?? currentCategory.value?.name))
useHead(() => ({ title: `${title.value ?? 'GulfCart'} · GulfCart` }))
</script>

<template>
  <div>
    <!-- brand header -->
    <div v-if="brandInfo" class="card mb-4 flex items-center gap-4 p-4" data-test="brand-header">
      <span class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-xl border border-ink-100 bg-white">
        <img v-if="brandInfo.logo_url" :src="brandInfo.logo_url" :alt="brandInfo.name" class="h-full w-full object-contain p-1">
        <span v-else class="text-lg font-bold text-ink-700">{{ brandInfo.name.slice(0, 2) }}</span>
      </span>
      <div class="min-w-0">
        <h1 class="text-xl font-bold">{{ brandInfo.name }}</h1>
        <p v-if="brandInfo.authorized" class="text-xs text-green-700">✓ {{ $t('brands.authorized') }}</p>
        <p v-if="brandInfo.description" class="mt-1 line-clamp-2 text-sm text-ink-500">{{ brandInfo.description }}</p>
      </div>
    </div>

    <div class="md:grid md:grid-cols-[220px_1fr] md:gap-6">
      <aside class="space-y-4" :class="filtersOpen ? 'mb-4 block' : 'hidden md:block'">
        <div class="card p-4">
          <p class="mb-2 text-sm font-bold">{{ $t('filters.category') }}</p>
          <button class="block w-full py-1 text-start text-sm" :class="!category ? 'font-semibold text-brand-600' : 'text-ink-700'" @click="setQuery({ category: undefined })">{{ $t('home.all') }}</button>
          <template v-for="c in categories" :key="c.id">
            <button class="block w-full py-1 text-start text-sm" :class="category === c.slug ? 'font-semibold text-brand-600' : 'text-ink-700'" @click="setQuery({ category: c.slug })">{{ c.name }}</button>
            <template v-if="category === c.slug || c.children.some(ch => ch.slug === category)">
              <button v-for="ch in c.children" :key="ch.id" class="block w-full py-1 ps-4 text-start text-sm" :class="category === ch.slug ? 'font-semibold text-brand-600' : 'text-ink-500'" @click="setQuery({ category: ch.slug })">{{ ch.name }}</button>
            </template>
          </template>
        </div>
        <div v-if="facets?.brands.length" class="card p-4" data-test="brand-filter">
          <p class="mb-2 text-sm font-bold">{{ $t('brands.title') }}</p>
          <label v-for="b in facets.brands" :key="b.slug" class="flex cursor-pointer items-center gap-2 py-1 text-sm">
            <input type="checkbox" :checked="brands.includes(b.slug)" @change="toggleBrand(b.slug)">
            <span class="flex-1">{{ b.name }}</span><span class="text-xs text-ink-500">{{ b.count }}</span>
          </label>
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
          <h1 v-if="!brandInfo" class="me-auto text-lg font-bold">
            {{ q ? $t('filters.results_for', { q }) : (currentCategory?.name ?? $t('home.all')) }}
            <span class="text-sm font-normal text-ink-500">({{ items.length }}{{ cursor ? '+' : '' }})</span>
          </h1>
          <span v-else class="me-auto text-sm text-ink-500">{{ items.length }}{{ cursor ? '+' : '' }} {{ $t('brands.products') }}</span>
          <div class="no-scrollbar flex gap-2 overflow-x-auto">
            <button class="chip whitespace-nowrap md:hidden" :class="{ 'chip-active': filtersOpen }" @click="filtersOpen = !filtersOpen">{{ $t('filters.title') }}<span v-if="brands.length || priceMax"> •</span></button>
            <button v-for="s in sorts" :key="s" class="chip whitespace-nowrap" :class="{ 'chip-active': sort === s }" @click="setQuery({ sort: s })">{{ $t(`catalog.sort.${s}`) }}</button>
          </div>
        </div>

        <div v-if="subChips.length" class="no-scrollbar mb-4 flex gap-2 overflow-x-auto" data-test="subcategories">
          <button v-if="parentOfCurrent && !currentCategory?.children.length" class="chip whitespace-nowrap" @click="setQuery({ category: parentOfCurrent.slug })">{{ $t('home.all') }} {{ parentOfCurrent.name }}</button>
          <button v-for="c in subChips" :key="c.id" class="chip gap-2 whitespace-nowrap" :class="{ 'chip-active': category === c.slug }" @click="setQuery({ category: c.slug })">
            <img v-if="c.image_url" :src="c.image_url" alt="" class="h-6 w-6 rounded-full object-cover">{{ c.name }}
          </button>
        </div>

        <div v-if="brands.length" class="mb-3 flex flex-wrap gap-2">
          <button v-for="b in brands" :key="b" class="chip text-xs" @click="toggleBrand(b)">{{ facets?.brands.find(x => x.slug === b)?.name ?? b }} ✕</button>
        </div>

        <p v-if="!loading && !items.length" class="card p-10 text-center text-ink-500">{{ $t('catalog.empty') }}</p>
        <ProductGrid :products="items" :loading="loading" :skeletons="15" />
        <div class="mt-6 text-center">
          <button v-if="cursor" :disabled="loading" class="btn-outline" @click="load(false)">{{ $t('common.load_more') }}</button>
        </div>
      </section>
    </div>
  </div>
</template>
