<script setup lang="ts">
import type { Envelope, ProductCard } from '~/types/api'

definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Sale { id: string, name: { en: string, ar: string | null }, percent_off: string, scope: 'all' | 'category' | 'product', category_id: string | null, product_id: string | null, countries: string[], starts_at: string, ends_at: string, is_active: boolean, status: 'scheduled' | 'running' | 'ended' | 'paused' }
interface Cat { id: string, slug: string, name: { en: string, ar?: string } | string, parent_id: string | null }
const { request, send, run, error, notice } = useAdmin()
const { t, locale } = useI18n()
const sales = ref<Sale[]>([])
const categories = ref<Cat[]>([])
const open = ref(false)
const editing = ref<Sale | null>(null)
const productQuery = ref('')
const productResults = ref<ProductCard[]>([])
const productLabel = ref('')
const local = (iso: string) => {
  const d = new Date(iso)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}
const blank = () => ({ name_en: '', name_ar: '', percent_off: '20', scope: 'all' as Sale['scope'], category_id: '', product_id: '', countries: [] as string[], starts_at: local(new Date().toISOString()), ends_at: local(new Date(Date.now() + 86400000).toISOString()), is_active: true })
const f = reactive(blank())

const catName = (c: Cat) => (typeof c.name === 'string' ? c.name : (locale.value === 'ar' && c.name.ar) || c.name.en)
async function load() {
  await run(async () => {
    sales.value = (await request<{ data: Sale[] }>('/admin/flash-sales')).data
    categories.value = (await request<{ data: Cat[] }>('/admin/categories').catch(() => ({ data: [] }))).data
  })
}
async function searchProducts() {
  if (productQuery.value.trim().length < 2) return
  productResults.value = (await request<Envelope<ProductCard[]>>('/products', { query: { q: productQuery.value, limit: 10 } })).data
}
function edit(s: Sale | null) {
  editing.value = s
  Object.assign(f, s
    ? { name_en: s.name.en, name_ar: s.name.ar ?? '', percent_off: String(Number(s.percent_off)), scope: s.scope, category_id: s.category_id ?? '', product_id: s.product_id ?? '', countries: [...s.countries], starts_at: local(s.starts_at), ends_at: local(s.ends_at), is_active: s.is_active }
    : blank())
  productLabel.value = s?.product_id ? s.product_id : ''
  productResults.value = []
  open.value = true
}
async function save() {
  const body = {
    name: { en: f.name_en, ar: f.name_ar || null }, percent_off: Number(f.percent_off), scope: f.scope,
    category_id: f.scope === 'category' ? f.category_id : null, product_id: f.scope === 'product' ? f.product_id : null,
    countries: f.countries, starts_at: new Date(f.starts_at).toISOString(), ends_at: new Date(f.ends_at).toISOString(), is_active: f.is_active,
  }
  const ok = await run(() => editing.value ? send('PATCH', `/admin/flash-sales/${editing.value.id}`, body) : send('POST', '/admin/flash-sales', body), t('admin.common.saved'))
  if (ok !== undefined) {
    open.value = false
    await load()
  }
}
async function toggle(s: Sale) {
  await run(() => send('PATCH', `/admin/flash-sales/${s.id}`, { is_active: !s.is_active }), t('admin.common.saved'))
  await load()
}
const when = (iso: string) => new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-AE' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
const target = (s: Sale) => s.scope === 'all' ? t('flash.scope.all') : s.scope === 'category' ? (categories.value.find(c => c.id === s.category_id) ? catName(categories.value.find(c => c.id === s.category_id)!) : t('flash.scope.category')) : t('flash.scope.product')
const tone: Record<Sale['status'], string> = { running: 'bg-green-50 text-green-700', scheduled: 'bg-blue-50 text-blue-700', ended: 'bg-ink-100 text-ink-500', paused: 'bg-gold-100 text-gold-600' }
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('flash.admin_title')" :subtitle="$t('flash.admin_subtitle')">
      <button class="btn-primary btn-sm" data-test="flash-add" @click="edit(null)"><AppIcon name="plus" :size="14" /> {{ $t('flash.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('flash.name') }}</th><th>{{ $t('flash.discount') }}</th><th>{{ $t('flash.applies_to') }}</th><th>{{ $t('flash.window') }}</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="s in sales" :key="s.id">
            <td><b>{{ locale === 'ar' && s.name.ar ? s.name.ar : s.name.en }}</b><span class="block text-xs text-ink-500">{{ s.countries.length ? s.countries.join(' / ') : $t('admin.common.all') }}</span></td>
            <td class="text-lg font-black text-brand-600">−{{ Number(s.percent_off) }}%</td>
            <td class="text-sm">{{ target(s) }}</td>
            <td class="text-xs">{{ when(s.starts_at) }}<span class="block text-ink-500">→ {{ when(s.ends_at) }}</span></td>
            <td>
              <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="tone[s.status]">{{ $t(`flash.status.${s.status}`) }}</span>
              <SaleCountdown v-if="s.status === 'running'" :ends="s.ends_at" class="mt-1 block scale-90" />
            </td>
            <td class="whitespace-nowrap text-end">
              <button class="btn-outline btn-sm" @click="toggle(s)">{{ s.is_active ? $t('flash.pause') : $t('flash.resume') }}</button>
              <button class="btn-outline btn-sm ms-1" @click="edit(s)"><AppIcon name="edit" :size="14" /></button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!sales.length" class="p-6 text-center text-ink-500">{{ $t('flash.empty') }}</p>
    </div>

    <AdminModal :open="open" :title="editing ? (editing.name.en) : $t('flash.add')" wide @close="open = false">
      <form class="grid gap-3 sm:grid-cols-2" data-test="flash-form" @submit.prevent="save">
        <label><span class="label">{{ $t('flash.name') }} (EN)</span><input v-model="f.name_en" class="input" required dir="ltr" placeholder="Weekend Flash Sale"></label>
        <label><span class="label">{{ $t('flash.name') }} (AR)</span><input v-model="f.name_ar" class="input" dir="rtl" placeholder="تخفيضات الويكند"></label>
        <label><span class="label">{{ $t('flash.discount') }} %</span><input v-model="f.percent_off" type="number" min="1" max="90" class="input" required dir="ltr"></label>
        <label><span class="label">{{ $t('flash.applies_to') }}</span>
          <select v-model="f.scope" class="input">
            <option value="all">{{ $t('flash.scope.all') }}</option><option value="category">{{ $t('flash.scope.category') }}</option><option value="product">{{ $t('flash.scope.product') }}</option>
          </select>
        </label>
        <label v-if="f.scope === 'category'" class="sm:col-span-2"><span class="label">{{ $t('flash.scope.category') }}</span>
          <select v-model="f.category_id" class="input" required>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.parent_id ? '— ' : '' }}{{ catName(c) }}</option>
          </select>
        </label>
        <div v-if="f.scope === 'product'" class="space-y-2 sm:col-span-2">
          <span class="label">{{ $t('flash.scope.product') }}</span>
          <div class="flex gap-2"><input v-model="productQuery" class="input flex-1" :placeholder="$t('catalog.search_placeholder')" @keydown.enter.prevent="searchProducts"><button type="button" class="btn-outline" @click="searchProducts"><AppIcon name="search" :size="16" /></button></div>
          <p v-if="f.product_id && productLabel" class="text-xs text-ink-500">✓ {{ productLabel }}</p>
          <div v-if="productResults.length" class="max-h-48 divide-y overflow-y-auto rounded-md border">
            <button v-for="p in productResults" :key="p.id" type="button" class="flex w-full items-center gap-2 p-2 text-start text-sm hover:bg-ink-100" :class="f.product_id === p.id ? 'bg-ink-100 font-semibold' : ''" @click="f.product_id = p.id; productLabel = p.title; productResults = []">
              <img v-if="p.image" :src="p.image" alt="" class="h-10 w-8 rounded object-cover">{{ p.title }}
            </button>
          </div>
        </div>
        <label><span class="label">{{ $t('admin.common.from') }}</span><input v-model="f.starts_at" type="datetime-local" class="input" required></label>
        <label><span class="label">{{ $t('admin.common.until') }}</span><input v-model="f.ends_at" type="datetime-local" class="input" required></label>
        <div class="flex items-center gap-4 text-sm sm:col-span-2">
          <label v-for="c in ['AE', 'SA']" :key="c" class="flex items-center gap-1"><input v-model="f.countries" type="checkbox" :value="c"> {{ c }}</label>
          <label class="flex items-center gap-1"><input v-model="f.is_active" type="checkbox"> {{ $t('admin.common.active') }}</label>
        </div>
        <p class="rounded-md bg-ink-100 p-3 text-xs text-ink-700 sm:col-span-2">{{ $t('flash.hint') }}</p>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary" data-test="flash-save">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
