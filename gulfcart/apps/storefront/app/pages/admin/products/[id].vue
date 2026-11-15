<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
type T = { en?: string | null, ar?: string | null }
interface Product {
  id: string, slug: string, status: string, title: T, description: T | null, category_id: string | null, brand: string | null, weight_grams: number | null, hs_code: string | null
  variants: { id: string, sku: string, attributes: Record<string, string>, weight_grams: number | null, is_active: boolean, prices: Record<string, { amount: string, currency: string }> }[]
  images: { id: string, url: string | null, source_url: string | null, rights_status: string, rights_basis: string | null, downloaded: boolean }[]
  availability: { country: string, is_available: boolean, min_days: number | null, max_days: number | null, is_sellable: boolean, unsellable_reason: string | null, from_price: { amount: string, currency: string } | null }[]
}
const route = useRoute()
const localePath = useLocalePath()
const auth = useAuthStore()
const { locale, t } = useI18n()
const { request, send, run, error, notice, tr } = useAdmin()
const product = ref<Product | null>(null)
const categories = ref<{ id: string, slug: string, name: T }[]>([])
const form = reactive({ title_en: '', title_ar: '', description_en: '', description_ar: '', category_id: '', brand: '', weight_grams: '' as string | number, hs_code: '' })
const availability = ref<{ country: string, is_available: boolean, min_days: number | null, max_days: number | null }[]>([])

async function load() {
  await run(async () => {
    const p = (await request<{ data: Product }>(`/admin/products/${route.params.id}`)).data
    product.value = p
    Object.assign(form, {
      title_en: p.title.en ?? '', title_ar: p.title.ar ?? '', description_en: p.description?.en ?? '', description_ar: p.description?.ar ?? '',
      category_id: p.category_id ?? '', brand: p.brand ?? '', weight_grams: p.weight_grams ?? '', hs_code: p.hs_code ?? '',
    })
    availability.value = ['AE', 'SA'].map((c) => {
      const a = p.availability.find(x => x.country === c)
      return { country: c, is_available: a?.is_available ?? false, min_days: a?.min_days ?? 7, max_days: a?.max_days ?? 14 }
    })
  })
}

async function save() {
  await run(async () => {
    await send('PATCH', `/admin/products/${route.params.id}`, {
      title: { en: form.title_en, ar: form.title_ar || null },
      description: { en: form.description_en || null, ar: form.description_ar || null },
      category_id: form.category_id || null, brand: form.brand || null,
      weight_grams: form.weight_grams === '' ? null : Number(form.weight_grams), hs_code: form.hs_code || null,
    })
    await send('PUT', `/admin/products/${route.params.id}/availability`, { countries: availability.value })
  }, t('admin.common.saved'))
  await load()
}

async function transition(action: string) {
  error.value = ''
  try {
    await send('POST', `/admin/products/${route.params.id}/${action}`)
    notice.value = t('admin.common.done')
    await load()
  }
  catch (e) {
    const data = (e as { data?: { error?: { details?: { problems?: string[] } } } }).data
    error.value = data?.error?.details?.problems?.join(', ') ?? apiErrorMessage(e, t('errors.generic'))
  }
}
async function imageRights(id: string, rights_status: 'approved' | 'rejected') {
  await run(() => send('PATCH', `/admin/images/${id}`, { rights_status }), t('admin.common.saved'))
  await load()
}
async function removeImage(id: string) {
  if (!confirm(t('admin.common.confirm'))) return
  await run(() => send('DELETE', `/admin/images/${id}`), t('admin.common.done'))
  await load()
}
const editingOptions = ref<string | null>(null)
const optionsText = ref('')
function editOptions(v: Product['variants'][number]) {
  editingOptions.value = v.id
  optionsText.value = Object.entries(v.attributes).map(([k, val]) => `${k}=${val}`).join('\n')
}
async function saveOptions(id: string) {
  const attributes = Object.fromEntries(optionsText.value.split('\n').map(l => l.split('=').map(x => x.trim())).filter(p => p.length === 2 && p[0] && p[1]).map(([k, val]) => [k!.toLowerCase(), val!]))
  const ok = await run(() => send('PATCH', `/admin/variants/${id}`, { attributes }), t('admin.common.saved'))
  if (ok !== undefined) {
    editingOptions.value = null
    await load()
  }
}

async function toggleVariant(v: Product['variants'][number]) {
  await run(() => send('PATCH', `/admin/variants/${v.id}`, { is_active: !v.is_active }), t('admin.common.saved'))
  await load()
}

onMounted(async () => {
  await load()
  categories.value = (await request<{ data: typeof categories.value }>('/admin/categories').catch(() => ({ data: [] }))).data
})
const actions = computed(() => {
  const s = product.value?.status
  const list: { action: string, label: string, primary?: boolean }[] = []
  if (s === 'draft' || s === 'unpublished') list.push({ action: 'submit', label: 'admin.actions.submit', primary: true })
  if (s === 'pending_review' && auth.can('catalog.publish')) list.push({ action: 'publish', label: 'admin.actions.publish', primary: true }, { action: 'return-to-draft', label: 'admin.products_page.return_to_draft' })
  if (s === 'published' && auth.can('catalog.publish')) list.push({ action: 'unpublish', label: 'admin.actions.unpublish' })
  if (s && s !== 'archived' && s !== 'published') list.push({ action: 'archive', label: 'admin.products_page.archive' })
  return list
})
</script>

<template>
  <section v-if="product">
    <NuxtLink :to="localePath('/admin/products')" class="mb-2 inline-block text-sm text-ink-500 hover:text-ink-900">← {{ $t('admin.products') }}</NuxtLink>
    <AdminHeader :title="tr(product.title as never)" :subtitle="product.slug">
      <StatusBadge :status="product.status" />
      <button v-for="a in actions" :key="a.action" class="btn-sm" :class="a.primary ? 'btn-primary' : 'btn-outline'" @click="transition(a.action)">{{ $t(a.label) }}</button>
      <NuxtLink v-if="product.status === 'published'" :to="localePath(`/products/${product.slug}`)" target="_blank" class="btn-outline btn-sm">{{ $t('admin.products_page.view_in_store') }}</NuxtLink>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />

    <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="save">
      <div class="card space-y-4 p-5 lg:col-span-2">
        <div class="grid gap-4 md:grid-cols-2">
          <label class="block"><span class="label">{{ $t('admin.products_page.title_en') }}</span><input v-model="form.title_en" class="input" dir="ltr" required maxlength="255"></label>
          <label class="block"><span class="label">{{ $t('admin.products_page.title_ar') }}</span><input v-model="form.title_ar" class="input" dir="rtl" maxlength="255" data-test="title-ar"></label>
          <label class="block"><span class="label">{{ $t('admin.products_page.description_en') }}</span><textarea v-model="form.description_en" class="input" dir="ltr" rows="6" /></label>
          <label class="block"><span class="label">{{ $t('admin.products_page.description_ar') }}</span><textarea v-model="form.description_ar" class="input" dir="rtl" rows="6" /></label>
        </div>
        <div class="grid gap-4 md:grid-cols-4">
          <label class="block md:col-span-2"><span class="label">{{ $t('admin.categories.title') }}</span>
            <select v-model="form.category_id" class="input"><option value="">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ tr(c.name as never) }}</option></select>
          </label>
          <label class="block"><span class="label">{{ $t('admin.products_page.brand') }}</span><input v-model="form.brand" class="input"></label>
          <label class="block"><span class="label">{{ $t('admin.products_page.weight') }}</span><input v-model="form.weight_grams" class="input" inputmode="numeric" dir="ltr"></label>
          <label class="block"><span class="label">{{ $t('admin.products_page.hs_code') }}</span><input v-model="form.hs_code" class="input" dir="ltr"></label>
        </div>

        <div>
          <h2 class="mb-2 font-bold">{{ $t('admin.products_page.availability') }}</h2>
          <div v-for="a in availability" :key="a.country" class="mb-2 flex flex-wrap items-center gap-3 rounded-xl border border-ink-100 p-3 text-sm">
            <label class="flex w-48 items-center gap-2 font-medium"><input v-model="a.is_available" type="checkbox"> {{ $t(`country.${a.country}`) }}</label>
            <label class="flex items-center gap-1">{{ $t('admin.products_page.days') }} <input v-model.number="a.min_days" class="input w-16" dir="ltr"> – <input v-model.number="a.max_days" class="input w-16" dir="ltr"></label>
            <template v-for="live in product.availability.filter(x => x.country === a.country)" :key="live.country">
              <span v-if="live.is_sellable" class="text-green-700">{{ formatMoney(live.from_price, locale) }}</span>
              <span v-else class="text-xs text-red-600">{{ live.unsellable_reason ? $t(`admin.products_page.reasons.${live.unsellable_reason}`) : '' }}</span>
            </template>
          </div>
        </div>
        <div class="flex justify-end"><button class="btn-primary" data-test="product-save">{{ $t('common.save') }}</button></div>
      </div>

      <div class="space-y-6">
        <div class="card p-5">
          <h2 class="mb-3 font-bold">{{ $t('admin.products_page.images') }}</h2>
          <div class="grid grid-cols-2 gap-3">
            <div v-for="img in product.images" :key="img.id" class="overflow-hidden rounded-xl border border-ink-100">
              <img v-if="img.url" :src="img.url" alt="" class="aspect-[3/4] w-full object-cover">
              <div v-else class="grid aspect-[3/4] place-items-center bg-ink-100 text-xs text-ink-500">{{ $t('admin.products_page.downloading') }}</div>
              <div class="space-y-1 p-2 text-xs">
                <StatusBadge :status="img.rights_status" />
                <div class="flex gap-2">
                  <button v-if="img.rights_status !== 'approved' && auth.can('catalog.publish')" type="button" class="link" @click="imageRights(img.id, 'approved')">{{ $t('admin.products_page.approve') }}</button>
                  <button v-if="img.rights_status !== 'rejected' && auth.can('catalog.publish')" type="button" class="link" @click="imageRights(img.id, 'rejected')">{{ $t('admin.products_page.reject') }}</button>
                  <button type="button" class="text-red-600 hover:underline" @click="removeImage(img.id)">{{ $t('common.remove') }}</button>
                </div>
              </div>
            </div>
          </div>
          <p v-if="!product.images.length" class="text-sm text-ink-500">{{ $t('common.none') }}</p>
        </div>
        <div class="card p-5">
          <h2 class="mb-3 font-bold">{{ $t('admin.products_page.variants_title') }}</h2>
          <p class="mb-2 text-xs text-ink-500">{{ $t('admin.products_page.attributes_hint') }}</p>
          <div v-for="v in product.variants" :key="v.id" class="border-b border-ink-100 py-2 text-sm last:border-0" data-test="admin-variant">
            <div class="flex items-center justify-between gap-2">
              <button type="button" class="min-w-0 text-start" @click="editOptions(v)">
                <span class="block font-mono text-xs" dir="ltr">{{ v.sku }}</span>
                <span class="text-ink-700">{{ Object.entries(v.attributes).map(([k, val]) => `${k}: ${val}`).join(' · ') }}</span>
                <span class="block text-xs text-ink-500" dir="ltr">{{ Object.values(v.prices ?? {}).map(p => formatMoney(p, locale)).join(' · ') || '—' }}</span>
              </button>
              <button type="button" class="chip shrink-0 text-xs" :class="v.is_active ? 'chip-active' : ''" @click="toggleVariant(v)">{{ v.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</button>
            </div>
            <div v-if="editingOptions === v.id" class="mt-2 space-y-2">
              <textarea v-model="optionsText" class="input font-mono text-xs" rows="3" dir="ltr" />
              <button type="button" class="btn-primary btn-sm" @click="saveOptions(v.id)">{{ $t('admin.products_page.save_options') }}</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </section>
  <AdminAlerts v-else :error="error" />
</template>
