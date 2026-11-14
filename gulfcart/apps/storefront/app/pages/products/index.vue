<script setup lang="ts">
import type { Category, Envelope, ProductCard } from '~/types/api'

const { request, country } = useApi()
const route = useRoute()
const router = useRouter()

const q = ref(String(route.query.q ?? ''))
const category = ref(String(route.query.category ?? ''))
const sort = ref(String(route.query.sort ?? 'newest'))
const items = ref<ProductCard[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)

const { data: categories } = await useAsyncData('categories', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })

async function load(reset: boolean) {
  loading.value = true
  try {
    const res = await request<Envelope<ProductCard[]>>('/products', {
      query: { q: q.value || undefined, category: category.value || undefined, sort: sort.value, limit: 24, cursor: reset ? undefined : cursor.value ?? undefined },
    })
    items.value = reset ? res.data : [...items.value, ...res.data]
    cursor.value = res.meta.pagination?.next_cursor ?? null
  }
  finally {
    loading.value = false
  }
}

function apply() {
  router.replace({ query: { q: q.value || undefined, category: category.value || undefined, sort: sort.value } })
  load(true)
}

onMounted(() => load(true))
watch(country, () => load(true))
</script>

<template>
  <section>
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('catalog.title') }}</h1>
    <form class="mb-6 flex flex-wrap gap-2" @submit.prevent="apply">
      <input v-model="q" type="search" :placeholder="$t('catalog.search_placeholder')" class="min-w-48 flex-1 rounded border px-3 py-2" data-test="search">
      <select v-model="category" class="rounded border px-2" @change="apply">
        <option value="">{{ $t('catalog.all_categories') }}</option>
        <template v-for="c in categories" :key="c.id">
          <option :value="c.slug">{{ c.name }}</option>
          <option v-for="child in c.children" :key="child.id" :value="child.slug">— {{ child.name }}</option>
        </template>
      </select>
      <select v-model="sort" class="rounded border px-2" @change="apply">
        <option v-for="s in ['newest', 'price_asc', 'price_desc']" :key="s" :value="s">{{ $t(`catalog.sort.${s}`) }}</option>
      </select>
      <button class="rounded bg-gray-900 px-4 text-white">{{ $t('common.search') }}</button>
    </form>

    <p v-if="!loading && !items.length" class="text-gray-500">{{ $t('catalog.empty') }}</p>
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
      <ProductCardItem v-for="p in items" :key="p.id" :product="p" />
    </div>
    <div class="mt-6 text-center">
      <button v-if="cursor" :disabled="loading" class="rounded border px-4 py-2" @click="load(false)">{{ $t('common.load_more') }}</button>
    </div>
  </section>
</template>
