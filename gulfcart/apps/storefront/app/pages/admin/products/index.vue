<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface AdminProduct {
  id: string
  slug: string
  title: { en?: string | null, ar?: string | null }
  status: string
  brand: string | null
  variants: { id: string }[]
  images: { url: string | null, rights_status: string }[]
  availability: { country: string, is_sellable: boolean, unsellable_reason: string | null, from_price: { amount: string, currency: string } | null }[]
}
const { request, send, run, error, notice, tr } = useAdmin()
const { locale, t } = useI18n()
const localePath = useLocalePath()
const auth = useAuthStore()
const products = ref<AdminProduct[]>([])
const filters = reactive({ status: '', q: '', page: 1 })
const pager = ref({ page: 1, last_page: 1, total: 0 })
const statuses = ['draft', 'pending_review', 'published', 'unpublished', 'archived']

async function load() {
  await run(async () => {
    const res = await request<{ data: AdminProduct[], meta: { pagination: typeof pager.value } }>('/admin/products', { query: { status: filters.status || undefined, q: filters.q || undefined, page: filters.page } })
    products.value = res.data
    pager.value = res.meta.pagination
  })
}
async function transition(p: AdminProduct, action: 'submit' | 'publish' | 'unpublish') {
  error.value = ''
  try {
    await send('POST', `/admin/products/${p.id}/${action}`)
    notice.value = t('admin.common.done')
    await load()
  }
  catch (e) {
    const data = (e as { data?: { error?: { details?: { problems?: string[] } } } }).data
    error.value = data?.error?.details?.problems?.map(c => t(`admin.publish_problems.${c}`)).join(' · ') ?? apiErrorMessage(e, t('errors.generic'))
  }
}
const thumb = (p: AdminProduct) => p.images.find(i => i.url)?.url ?? undefined
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.products')" :subtitle="$t('admin.products_page.subtitle')">
      <NuxtLink :to="localePath('/admin/cj')" class="btn-primary btn-sm"><AppIcon name="download" :size="14" /> {{ $t('admin.cj.title') }}</NuxtLink>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <form class="card mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="filters.page = 1; load()">
      <label class="min-w-48 flex-1"><span class="label">{{ $t('admin.common.search') }}</span><input v-model="filters.q" class="input" :placeholder="$t('admin.products_page.search_hint')"></label>
      <div class="no-scrollbar flex gap-2 overflow-x-auto">
        <button type="button" class="chip" :class="!filters.status ? 'chip-active' : ''" @click="filters.status = ''; filters.page = 1; load()">{{ $t('admin.common.all') }}</button>
        <button v-for="s in statuses" :key="s" type="button" class="chip whitespace-nowrap" :class="filters.status === s ? 'chip-active' : ''" @click="filters.status = s; filters.page = 1; load()">{{ $t(`admin.products_page.status.${s}`) }}</button>
      </div>
    </form>
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.orders_page.product') }}</th><th>{{ $t('admin.status') }}</th><th>{{ $t('admin.products_page.price_by_country') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="p in products" :key="p.id" data-test="product-row">
            <td>
              <NuxtLink :to="localePath(`/admin/products/${p.id}`)" class="flex items-center gap-3">
                <img v-if="thumb(p)" :src="thumb(p)" alt="" class="h-12 w-10 rounded-lg object-cover">
                <span v-else class="grid h-12 w-10 place-items-center rounded-lg bg-ink-100 text-ink-300"><AppIcon name="box" :size="16" /></span>
                <span class="min-w-0">
                  <span class="block max-w-xs truncate font-medium text-ink-900 hover:text-brand-700">{{ tr(p.title as never) }}</span>
                  <span class="text-xs text-ink-500">{{ $t('admin.products_page.variants', { n: p.variants.length }) }}<span v-if="!p.title.ar" class="ms-2 text-gold-600">· {{ $t('admin.products_page.missing_ar') }}</span></span>
                </span>
              </NuxtLink>
            </td>
            <td><StatusBadge :status="p.status" /></td>
            <td class="text-xs">
              <span v-for="a in p.availability" :key="a.country" class="me-3 inline-block" :title="a.unsellable_reason ?? ''">
                <b>{{ a.country }}</b> {{ a.is_sellable ? formatMoney(a.from_price, locale) : (a.unsellable_reason ? $t(`admin.products_page.reasons.${a.unsellable_reason}`) : '—') }}
              </span>
            </td>
            <td class="whitespace-nowrap text-end">
              <button v-if="['draft', 'unpublished'].includes(p.status)" class="btn-outline btn-sm" @click="transition(p, 'submit')">{{ $t('admin.actions.submit') }}</button>
              <button v-if="p.status === 'pending_review' && auth.can('catalog.publish')" class="btn-primary btn-sm" @click="transition(p, 'publish')">{{ $t('admin.actions.publish') }}</button>
              <button v-if="p.status === 'published' && auth.can('catalog.publish')" class="btn-outline btn-sm" @click="transition(p, 'unpublish')">{{ $t('admin.actions.unpublish') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!products.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
    <AdminPager :page="pager.page" :last-page="pager.last_page" :total="pager.total" @go="p => { filters.page = p; load() }" />
  </section>
</template>
