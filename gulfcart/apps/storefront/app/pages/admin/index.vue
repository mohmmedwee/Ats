<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Dashboard {
  paid_today: { currency: string, orders: number, revenue: string }[]
  procurement: Record<string, number>
  refunds_awaiting_approval: number
  returns_open: number
  sellable_products: Record<string, number>
  exchange_rates_stale: boolean
}
interface FinanceRow { country: string, currency: string, orders: number, units: number, gross_collected: string, refunds: string, vat_collected: string, customs_collected: string, net_revenue_ex_vat: string, landed_cost: string, estimated_payment_fees: string, estimated_contribution: string, contribution_margin_percent: string | null }
interface RecentOrder { id: string, order_number: string, status: string, currency: string, grand_total: string, placed_at: string }

const { request, run, error } = useAdmin()
const { locale } = useI18n()
const localePath = useLocalePath()
const auth = useAuthStore()
const data = ref<Dashboard | null>(null)
const finance = ref<FinanceRow[]>([])
const recent = ref<RecentOrder[]>([])
const days = ref(30)

async function loadFinance() {
  if (!(auth.can('pricing.manage') || auth.can('audit.view'))) return
  const to = new Date()
  const from = new Date(Date.now() - days.value * 86400000)
  const iso = (d: Date) => d.toISOString().slice(0, 10)
  const res = await request<{ data: { by_country: FinanceRow[] } }>('/admin/finance/reports', { query: { from: iso(from), to: iso(to) } }).catch(() => null)
  finance.value = res?.data.by_country ?? []
}

onMounted(() => run(async () => {
  data.value = (await request<{ data: Dashboard }>('/admin/dashboard')).data
  recent.value = (await request<{ data: RecentOrder[] }>('/admin/orders', { query: { per_page: 8 } })).data
  await loadFinance()
}))
const fm = (amount: string, currency: string) => formatMoney({ amount, currency }, locale.value)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.title')" :subtitle="$t('admin.dashboard_page.subtitle', { name: auth.user?.name ?? '' })" />
    <AdminAlerts :error="error" />
    <p v-if="data?.exchange_rates_stale" class="mb-4 flex items-center gap-2 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
      <AppIcon name="alert" :size="18" /> {{ $t('admin.fx_stale') }} <NuxtLink :to="localePath('/admin/pricing')" class="link">{{ $t('admin.pricing.title') }}</NuxtLink>
    </p>

    <div v-if="data" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <AdminStat v-for="p in data.paid_today" :key="p.currency" :label="`${$t('admin.paid_today')} · ${p.orders} ${$t('admin.dashboard_page.orders_word')}`" :value="fm(p.revenue, p.currency)" icon="coins" tone="good" :to="localePath('/admin/orders')" />
      <AdminStat :label="$t('admin.exceptions')" :value="data.procurement.exception ?? 0" icon="alert" :tone="(data.procurement.exception ?? 0) > 0 ? 'danger' : 'good'" :to="localePath('/admin/procurement')" data-test="kpi-exceptions" />
      <AdminStat :label="$t('admin.manual')" :value="data.procurement.manual ?? 0" icon="truck" :tone="(data.procurement.manual ?? 0) > 0 ? 'warn' : 'good'" :to="localePath('/admin/procurement')" />
      <AdminStat :label="$t('admin.awaiting_approval')" :value="data.refunds_awaiting_approval" icon="coins" :tone="data.refunds_awaiting_approval ? 'warn' : 'good'" :to="localePath('/admin/refunds')" />
      <AdminStat :label="$t('admin.open_returns')" :value="data.returns_open" icon="return" :to="localePath('/admin/returns')" />
      <AdminStat v-for="(n, code) in data.sellable_products" :key="code" :label="`${$t('admin.sellable')} · ${$t(`country.${code}`)}`" :value="n" icon="box" :to="localePath('/admin/products')" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
      <div class="card overflow-x-auto xl:col-span-2">
        <div class="flex items-center justify-between px-4 pt-4">
          <h2 class="font-bold">{{ $t('admin.dashboard_page.finance') }}</h2>
          <select v-model.number="days" class="input w-auto" @change="loadFinance">
            <option :value="7">{{ $t('admin.dashboard_page.last_days', { n: 7 }) }}</option>
            <option :value="30">{{ $t('admin.dashboard_page.last_days', { n: 30 }) }}</option>
            <option :value="90">{{ $t('admin.dashboard_page.last_days', { n: 90 }) }}</option>
          </select>
        </div>
        <table class="admin-table mt-3">
          <thead><tr><th>{{ $t('admin.common.country') }}</th><th>{{ $t('admin.orders') }}</th><th class="text-end">{{ $t('admin.dashboard_page.gross') }}</th><th class="text-end">{{ $t('admin.dashboard_page.net') }}</th><th class="text-end">{{ $t('admin.orders_page.landed_cost') }}</th><th class="text-end">{{ $t('admin.dashboard_page.profit') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in finance" :key="r.country">
              <td>{{ $t(`country.${r.country}`) }}</td>
              <td>{{ r.orders }}</td>
              <td class="whitespace-nowrap text-end">{{ fm(r.gross_collected, r.currency) }}</td>
              <td class="whitespace-nowrap text-end">{{ fm(r.net_revenue_ex_vat, r.currency) }}</td>
              <td class="whitespace-nowrap text-end">{{ fm(r.landed_cost, r.currency) }}</td>
              <td class="whitespace-nowrap text-end font-semibold" :class="Number(r.estimated_contribution) < 0 ? 'text-red-600' : 'text-green-700'">{{ fm(r.estimated_contribution, r.currency) }} <span v-if="r.contribution_margin_percent" class="text-xs text-ink-500">({{ r.contribution_margin_percent }}%)</span></td>
            </tr>
          </tbody>
        </table>
        <p v-if="!finance.length" class="p-6 text-center text-sm text-ink-500">{{ $t('admin.dashboard_page.no_sales') }}</p>
      </div>

      <div class="card p-4">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="font-bold">{{ $t('admin.dashboard_page.recent') }}</h2>
          <NuxtLink :to="localePath('/admin/orders')" class="link text-sm">{{ $t('admin.dashboard_page.view_all') }}</NuxtLink>
        </div>
        <NuxtLink v-for="o in recent" :key="o.id" :to="localePath(`/admin/orders/${o.id}`)" class="flex items-center justify-between border-b border-ink-100 py-2 text-sm last:border-0 hover:bg-ink-100/40">
          <span><span class="block font-medium" dir="ltr">{{ o.order_number }}</span><span class="text-xs text-ink-500">{{ formatDateTime(o.placed_at, locale) }}</span></span>
          <span class="text-end"><span class="block font-semibold">{{ fm(o.grand_total, o.currency) }}</span><StatusBadge :status="o.status" scope="orders.status" /></span>
        </NuxtLink>
        <p v-if="!recent.length" class="text-sm text-ink-500">{{ $t('common.none') }}</p>
      </div>
    </div>
  </section>
</template>
