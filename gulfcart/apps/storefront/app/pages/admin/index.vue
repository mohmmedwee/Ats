<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
interface Dashboard {
  paid_today: { currency: string, orders: number, revenue: string }[]
  procurement: Record<string, number>
  refunds_awaiting_approval: number
  returns_open: number
  sellable_products: Record<string, number>
  exchange_rates_stale: boolean
}
const { request } = useApi()
const { locale, t } = useI18n()
const data = ref<Dashboard | null>(null)
const error = ref('')
onMounted(async () => {
  try {
    data.value = (await request<{ data: Dashboard }>('/admin/dashboard')).data
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('admin.forbidden'))
  }
})
</script>

<template>
  <section>
    <AdminNav />
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('admin.title') }}</h1>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <p v-if="data?.exchange_rates_stale" class="mb-4 rounded bg-red-50 p-2 text-red-700">{{ $t('admin.fx_stale') }}</p>
    <div v-if="data" class="grid grid-cols-2 gap-4 md:grid-cols-4">
      <div v-for="p in data.paid_today" :key="p.currency" class="rounded border p-4">
        <p class="text-sm text-gray-500">{{ $t('admin.paid_today') }}</p>
        <p class="text-2xl font-semibold">{{ formatMoney({ amount: p.revenue, currency: p.currency }, locale) }}</p>
        <p class="text-sm">{{ p.orders }}</p>
      </div>
      <div class="rounded border p-4"><p class="text-sm text-gray-500">{{ $t('admin.exceptions') }}</p><p class="text-2xl font-semibold" data-test="kpi-exceptions">{{ data.procurement.exception ?? 0 }}</p></div>
      <div class="rounded border p-4"><p class="text-sm text-gray-500">{{ $t('admin.manual') }}</p><p class="text-2xl font-semibold">{{ data.procurement.manual ?? 0 }}</p></div>
      <div class="rounded border p-4"><p class="text-sm text-gray-500">{{ $t('admin.awaiting_approval') }}</p><p class="text-2xl font-semibold">{{ data.refunds_awaiting_approval }}</p></div>
      <div class="rounded border p-4"><p class="text-sm text-gray-500">{{ $t('admin.open_returns') }}</p><p class="text-2xl font-semibold">{{ data.returns_open }}</p></div>
      <div v-for="(n, code) in data.sellable_products" :key="code" class="rounded border p-4">
        <p class="text-sm text-gray-500">{{ $t('admin.sellable') }} · {{ code }}</p><p class="text-2xl font-semibold">{{ n }}</p>
      </div>
    </div>
  </section>
</template>
