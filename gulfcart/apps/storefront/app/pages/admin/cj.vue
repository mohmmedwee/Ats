<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface CjProduct { pid: string, title: string, sku: string | null, image: string | null, price_usd: string | null, inventory: number | null, listed: number | null, imported: boolean }
interface ImportResult { id: string, status: string, created_count: number, updated_count: number, error_count: number, errors: { row: number, errors: string[] }[] }
const { request, send, run, error, notice, busy, tr } = useAdmin()
const { t } = useI18n()
const localePath = useLocalePath()
const search = reactive({ q: '', min_price: '', max_price: '', page: 1 })
const results = ref<CjProduct[]>([])
const pager = ref({ page: 1, last_page: 1, total: 0 })
const searched = ref(false)
const selected = ref<string[]>([])
const manual = ref('')
const categories = ref<{ slug: string, name: Record<string, string> }[]>([])
const options = reactive({ category_slug: '', countries: ['AE', 'SA'] })
const job = ref<ImportResult | null>(null)

async function find() {
  await run(async () => {
    const res = await request<{ data: CjProduct[], meta: { pagination: typeof pager.value } }>('/admin/cj/products', { query: { q: search.q || undefined, min_price: search.min_price || undefined, max_price: search.max_price || undefined, page: search.page } })
    results.value = res.data
    pager.value = res.meta.pagination
    searched.value = true
  })
}
function toggle(pid: string) {
  selected.value = selected.value.includes(pid) ? selected.value.filter(p => p !== pid) : [...selected.value, pid]
}
/** Accepts CJ product URLs or bare IDs, one per line or comma separated. */
function parseManual(): string[] {
  return manual.value.split(/[\s,]+/).map((s) => {
    const m = s.match(/p-([A-Za-z0-9-]+)\.html/) ?? s.match(/[?&]id=([A-Za-z0-9-]+)/)
    return (m?.[1] ?? s).trim()
  }).filter(s => /^[A-Za-z0-9-]{6,64}$/.test(s))
}
async function startImport() {
  const pids = [...new Set([...selected.value, ...parseManual()])]
  if (!pids.length) return
  const res = await run(() => send<{ data: { id: string } }>('POST', '/admin/cj/imports', { pids, countries: options.countries, category_slug: options.category_slug || null }))
  if (!res) return
  notice.value = t('admin.cj.queued', { n: pids.length })
  selected.value = []
  manual.value = ''
  for (let i = 0; i < 120; i++) {
    job.value = (await request<{ data: ImportResult }>(`/admin/imports/${res.data.id}`)).data
    if (['completed', 'failed'].includes(job.value.status)) break
    await new Promise(r => setTimeout(r, 2000))
  }
  if (searched.value) await find()
}
onMounted(async () => {
  categories.value = (await request<{ data: typeof categories.value }>('/admin/categories').catch(() => ({ data: [] }))).data
})
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.cj.title')" :subtitle="$t('admin.cj.subtitle')" />
    <AdminAlerts :error="error" :notice="notice" />

    <div v-if="job" class="card mb-4 p-4 text-sm" data-test="cj-job">
      <p class="flex flex-wrap items-center gap-3">
        <StatusBadge :status="job.status" />
        <span>{{ $t('admin.created') }}: <b>{{ job.created_count }}</b></span>
        <span>{{ $t('admin.updated') }}: <b>{{ job.updated_count }}</b></span>
        <span :class="job.error_count ? 'text-red-600' : ''">{{ $t('admin.errors') }}: <b>{{ job.error_count }}</b></span>
        <NuxtLink v-if="job.status === 'completed'" :to="localePath({ path: '/admin/products', query: { status: 'draft' } })" class="link">{{ $t('admin.cj.review_drafts') }} →</NuxtLink>
      </p>
      <ul v-if="job.errors.length" class="mt-2 list-disc ps-6 text-xs text-red-700"><li v-for="(e, i) in job.errors" :key="i" dir="ltr">{{ e.errors.join('; ') }}</li></ul>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
      <div class="xl:col-span-2">
        <form class="card mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="search.page = 1; find()">
          <label class="min-w-48 flex-1"><span class="label">{{ $t('admin.cj.keyword') }}</span><input v-model="search.q" class="input" dir="ltr" :placeholder="$t('admin.cj.keyword_hint')"></label>
          <label class="w-28"><span class="label">{{ $t('admin.cj.min_price') }}</span><input v-model="search.min_price" class="input" inputmode="decimal" dir="ltr"></label>
          <label class="w-28"><span class="label">{{ $t('admin.cj.max_price') }}</span><input v-model="search.max_price" class="input" inputmode="decimal" dir="ltr"></label>
          <button class="btn-dark" :disabled="busy"><AppIcon name="search" :size="16" /> {{ $t('common.search') }}</button>
        </form>

        <div v-if="results.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          <button v-for="p in results" :key="p.pid" type="button" class="card overflow-hidden text-start transition" :class="selected.includes(p.pid) ? 'ring-2 ring-brand-600' : 'hover:shadow-md'" :disabled="p.imported" @click="toggle(p.pid)">
            <div class="relative aspect-square bg-ink-100">
              <img v-if="p.image" :src="p.image" alt="" class="h-full w-full object-cover" loading="lazy">
              <span v-if="p.imported" class="absolute start-2 top-2 rounded-full bg-green-600 px-2 py-0.5 text-[11px] font-semibold text-white">{{ $t('admin.cj.imported') }}</span>
              <span v-else-if="selected.includes(p.pid)" class="absolute end-2 top-2 grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-white"><AppIcon name="check" :size="14" /></span>
            </div>
            <div class="p-2 text-xs">
              <p class="line-clamp-2 min-h-8 font-medium text-ink-900" dir="ltr">{{ p.title }}</p>
              <p class="mt-1 flex justify-between text-ink-500"><span class="font-semibold text-ink-900" dir="ltr">${{ p.price_usd }}</span><span v-if="p.inventory !== null">{{ $t('admin.cj.stock', { n: p.inventory }) }}</span></p>
            </div>
          </button>
        </div>
        <p v-else-if="searched" class="card p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
        <div v-else class="card p-8 text-center text-sm text-ink-500">
          <AppIcon name="search" :size="32" class="mx-auto mb-2 text-ink-300" />
          {{ $t('admin.cj.start_hint') }}
        </div>
        <AdminPager v-if="results.length" :page="pager.page" :last-page="pager.last_page" :total="pager.total" @go="p => { search.page = p; find() }" />
      </div>

      <aside class="card h-fit space-y-4 p-5 xl:sticky xl:top-6">
        <h2 class="font-bold">{{ $t('admin.cj.import_title') }}</h2>
        <p class="text-sm">{{ $t('admin.cj.selected', { n: selected.length }) }}</p>
        <label class="block"><span class="label">{{ $t('admin.cj.paste') }}</span><textarea v-model="manual" class="input text-xs" rows="3" dir="ltr" placeholder="https://cjdropshipping.com/product/…-p-1234567890.html" /></label>
        <label class="block"><span class="label">{{ $t('admin.categories.title') }}</span>
          <select v-model="options.category_slug" class="input"><option value="">—</option><option v-for="c in categories" :key="c.slug" :value="c.slug">{{ tr(c.name as never) }}</option></select>
        </label>
        <div class="flex gap-4 text-sm">
          <label v-for="c in ['AE', 'SA']" :key="c" class="flex items-center gap-2"><input v-model="options.countries" type="checkbox" :value="c"> {{ $t(`country.${c}`) }}</label>
        </div>
        <button class="btn-primary w-full" :disabled="busy || (!selected.length && !manual.trim()) || !options.countries.length" data-test="cj-import" @click="startImport">
          <AppIcon name="download" :size="16" /> {{ $t('admin.cj.import_button') }}
        </button>
        <ul class="space-y-1 text-xs text-ink-500">
          <li>• {{ $t('admin.cj.tip_drafts') }}</li>
          <li>• {{ $t('admin.cj.tip_translate') }}</li>
          <li>• {{ $t('admin.cj.tip_pricing') }}</li>
        </ul>
      </aside>
    </div>
  </section>
</template>
