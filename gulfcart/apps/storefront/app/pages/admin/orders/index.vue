<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Row { id: string, order_number: string, status: string, refund_state: string, country_code: string | null, currency: string, grand_total: string, estimated_contribution_before_fees: string, items_count: number, placed_at: string }
interface Page { data: Row[], meta: { pagination: { page: number, last_page: number, total: number } } }

const { request, run, error } = useAdmin()
const { locale } = useI18n()
const localePath = useLocalePath()
const route = useRoute()
const statuses = ['pending_payment', 'paid', 'processing', 'partially_shipped', 'shipped', 'delivered', 'completed', 'cancelled', 'payment_failed', 'expired']
const filters = reactive({ q: String(route.query.q ?? ''), status: String(route.query.status ?? ''), country: '', page: 1 })
const rows = ref<Row[]>([])
const pager = ref({ page: 1, last_page: 1, total: 0 })

async function load() {
  await run(async () => {
    const res = await request<Page>('/admin/orders', { query: { q: filters.q || undefined, status: filters.status || undefined, country: filters.country || undefined, page: filters.page } })
    rows.value = res.data
    pager.value = res.meta.pagination
  })
}
function apply() {
  filters.page = 1
  load()
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.orders')" :subtitle="$t('admin.orders_page.subtitle')" />
    <AdminAlerts :error="error" />
    <form class="card mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="apply">
      <label class="min-w-48 flex-1"><span class="label">{{ $t('admin.common.search') }}</span><input v-model="filters.q" class="input" :placeholder="$t('admin.orders_page.search_hint')"></label>
      <label><span class="label">{{ $t('admin.status') }}</span>
        <select v-model="filters.status" class="input" @change="apply">
          <option value="">{{ $t('admin.common.all') }}</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ $t(`orders.status.${s}`) }}</option>
        </select>
      </label>
      <label><span class="label">{{ $t('admin.common.country') }}</span>
        <select v-model="filters.country" class="input" @change="apply">
          <option value="">{{ $t('admin.common.all') }}</option>
          <option value="AE">{{ $t('country.AE') }}</option>
          <option value="SA">{{ $t('country.SA') }}</option>
        </select>
      </label>
      <button class="btn-dark">{{ $t('admin.common.filter') }}</button>
    </form>

    <div class="card overflow-x-auto">
      <table class="admin-table" data-test="orders-table">
        <thead><tr><th>{{ $t('admin.order') }}</th><th>{{ $t('admin.common.date') }}</th><th>{{ $t('admin.common.country') }}</th><th>{{ $t('admin.status') }}</th><th>{{ $t('admin.orders_page.items') }}</th><th class="text-end">{{ $t('admin.orders_page.total') }}</th><th class="text-end">{{ $t('admin.orders_page.margin') }}</th></tr></thead>
        <tbody>
          <tr v-for="o in rows" :key="o.id">
            <td><NuxtLink :to="localePath(`/admin/orders/${o.id}`)" class="link" dir="ltr">{{ o.order_number }}</NuxtLink></td>
            <td class="whitespace-nowrap text-ink-500">{{ formatDateTime(o.placed_at, locale) }}</td>
            <td>{{ o.country_code }}</td>
            <td><StatusBadge :status="o.status" scope="orders.status" /> <StatusBadge v-if="o.refund_state !== 'none'" :status="o.refund_state" /></td>
            <td>{{ o.items_count }}</td>
            <td class="whitespace-nowrap text-end font-semibold">{{ formatMoney({ amount: o.grand_total, currency: o.currency }, locale) }}</td>
            <td class="whitespace-nowrap text-end" :class="Number(o.estimated_contribution_before_fees) < 0 ? 'text-red-600' : 'text-green-700'">{{ formatMoney({ amount: o.estimated_contribution_before_fees, currency: o.currency }, locale) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="!rows.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
    <AdminPager :page="pager.page" :last-page="pager.last_page" :total="pager.total" @go="p => { filters.page = p; load() }" />
  </section>
</template>
