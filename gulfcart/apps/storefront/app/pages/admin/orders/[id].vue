<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Item { id: string, title: Record<string, string> | string | null, sku: string | null, quantity: number, unit_gross_price: string, line_total: string, unit_supplier_cost: string, unit_supplier_shipping: string, supplier_currency: string, fulfillment_status: string, refunded_quantity: number }
interface Detail {
  id: string, order_number: string, status: string, refund_state: string, country_code: string | null, currency: string
  grand_total: string, paid_total: string, refunded_total: string, estimated_landed_cost: string, estimated_contribution_before_fees: string
  placed_at: string, paid_at: string | null
  customer: { name: string, email: string | null, phone: string | null } | null
  shipping_address: Record<string, string | null>
  shipping_method: { code?: string, name?: Record<string, string> | string } | null
  totals: Record<string, string>
  items: Item[]
  shipments: { shipment_group: number | null, carrier: string | null, tracking_number: string | null, tracking_url: string | null, status: string }[]
}
interface Task { id: string, status: string, supplier_order: { reference: string, status: string, external_order_id: string | null, expected_cost: string, actual_cost: string | null, currency: string, exception_reason: string | null } }
interface Refund { id: string, amount: string, currency: string, reason_code: string, status: string, created_at: string }

const route = useRoute()
const config = useRuntimeConfig()
const { locale } = useI18n()
const localePath = useLocalePath()
const auth = useAuthStore()
const { request, send, run, error, notice, tr } = useAdmin()
const nuxtApp = useNuxtApp()
const order = ref<Detail | null>(null)
const tasks = ref<Task[]>([])
const refunds = ref<Refund[]>([])
const invoices = ref<{ id: string, number: string, type: string }[]>([])
const refundOpen = ref(false)
const refundForm = reactive({ amount: '', reason_code: 'customer_request', reason: '' })
const reasonCodes = ['customer_request', 'procurement_failed', 'damaged', 'not_delivered', 'goodwill', 'cancellation', 'price_adjustment']
const m = (amount: string | null | undefined) => formatMoney(amount == null ? null : { amount, currency: order.value?.currency ?? 'AED' }, locale.value)
const refundable = computed(() => order.value ? (Number(order.value.paid_total) - Number(order.value.refunded_total)).toFixed(2) : '0')

async function load() {
  const id = String(route.params.id)
  await run(async () => {
    order.value = (await request<{ data: Detail }>(`/admin/orders/${id}`)).data
    const extra = await Promise.allSettled([
      request<{ data: Task[] }>('/admin/procurement', { query: { order_id: id, per_page: 50 } }),
      request<{ data: Refund[] }>('/admin/refunds', { query: { order_id: id } }),
      request<{ data: typeof invoices.value }>(`/orders/${id}/invoices`),
    ])
    tasks.value = extra[0].status === 'fulfilled' ? extra[0].value.data : []
    refunds.value = extra[1].status === 'fulfilled' ? extra[1].value.data : []
    invoices.value = extra[2].status === 'fulfilled' ? extra[2].value.data : []
  })
}

async function submitRefund() {
  if (!order.value) return
  const ok = await run(() => send('POST', '/admin/refunds', { order_id: order.value!.id, ...refundForm, reason: refundForm.reason || undefined }, 'refund'), String(nuxtApp.$i18n.t('admin.orders_page.refund_requested')))
  if (ok !== undefined) {
    refundOpen.value = false
    await load()
  }
}

onMounted(load)
</script>

<template>
  <section v-if="order">
    <NuxtLink :to="localePath('/admin/orders')" class="mb-2 inline-block text-sm text-ink-500 hover:text-ink-900">← {{ $t('admin.orders') }}</NuxtLink>
    <AdminHeader :title="order.order_number" :subtitle="formatDateTime(order.placed_at, locale)">
      <StatusBadge :status="order.status" scope="orders.status" />
      <button v-if="auth.can('refunds.request') && Number(refundable) > 0" class="btn-outline btn-sm" @click="refundForm.amount = refundable; refundOpen = true">{{ $t('admin.refund') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
      <AdminStat :label="$t('admin.orders_page.total')" :value="m(order.grand_total)" icon="receipt" />
      <AdminStat :label="$t('admin.orders_page.paid')" :value="m(order.paid_total)" icon="check" tone="good" />
      <AdminStat :label="$t('admin.orders_page.landed_cost')" :value="m(order.estimated_landed_cost)" icon="truck" tone="warn" />
      <AdminStat :label="$t('admin.orders_page.margin')" :value="m(order.estimated_contribution_before_fees)" icon="chart" :tone="Number(order.estimated_contribution_before_fees) < 0 ? 'danger' : 'good'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <div class="card overflow-x-auto">
          <h2 class="px-4 pt-4 font-bold">{{ $t('admin.orders_page.items') }}</h2>
          <table class="admin-table mt-2">
            <thead><tr><th>{{ $t('admin.orders_page.product') }}</th><th>{{ $t('common.quantity') }}</th><th class="text-end">{{ $t('admin.orders_page.price') }}</th><th class="text-end">{{ $t('admin.orders_page.supplier_cost') }}</th><th>{{ $t('admin.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="i in order.items" :key="i.id">
                <td><span class="block font-medium">{{ tr(i.title as never) }}</span><span class="text-xs text-ink-500" dir="ltr">{{ i.sku }}</span></td>
                <td>{{ i.quantity }}<span v-if="i.refunded_quantity" class="text-xs text-red-600"> (−{{ i.refunded_quantity }})</span></td>
                <td class="whitespace-nowrap text-end">{{ m(i.line_total) }}</td>
                <td class="whitespace-nowrap text-end text-ink-500" dir="ltr">{{ i.unit_supplier_cost }} + {{ i.unit_supplier_shipping }} {{ i.supplier_currency }}</td>
                <td><StatusBadge :status="i.fulfillment_status" /></td>
              </tr>
            </tbody>
          </table>
          <dl class="grid grid-cols-2 gap-1 p-4 text-sm sm:ms-auto sm:w-72">
            <template v-for="k in ['subtotal', 'shipping_total', 'customs_total', 'tax_total', 'discount_total', 'grand_total']" :key="k">
              <dt class="text-ink-500" :class="k === 'grand_total' ? 'font-bold text-ink-900' : ''">{{ $t(`admin.orders_page.totals.${k}`) }}</dt>
              <dd class="text-end" :class="k === 'grand_total' ? 'font-bold' : ''">{{ m(order.totals[k]) }}</dd>
            </template>
          </dl>
        </div>

        <div class="card p-4">
          <h2 class="mb-3 font-bold">{{ $t('admin.orders_page.supplier_orders') }}</h2>
          <div v-for="t in tasks" :key="t.id" class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-ink-100 p-3 text-sm">
            <span dir="ltr" class="font-mono">{{ t.supplier_order.reference }}</span>
            <span><StatusBadge :status="t.supplier_order.status" /> <StatusBadge :status="t.status" /></span>
            <span class="text-ink-500" dir="ltr">{{ t.supplier_order.external_order_id ?? '—' }}</span>
            <span dir="ltr">{{ t.supplier_order.expected_cost }} → {{ t.supplier_order.actual_cost ?? '…' }} {{ t.supplier_order.currency }}</span>
            <span v-if="t.supplier_order.exception_reason" class="w-full text-xs text-red-600">{{ t.supplier_order.exception_reason }}</span>
          </div>
          <p v-if="!tasks.length" class="text-sm text-ink-500">{{ $t('common.none') }}</p>
          <NuxtLink v-if="tasks.some(t => ['exception', 'manual'].includes(t.status))" :to="localePath('/admin/procurement')" class="link text-sm">{{ $t('admin.orders_page.resolve_procurement') }} →</NuxtLink>
        </div>

        <div class="card p-4">
          <h2 class="mb-3 font-bold">{{ $t('admin.orders_page.shipments') }}</h2>
          <div v-for="(s, idx) in order.shipments" :key="idx" class="mb-2 flex flex-wrap items-center gap-3 text-sm">
            <StatusBadge :status="s.status" />
            <span>{{ s.carrier ?? '—' }}</span>
            <a v-if="s.tracking_url" :href="s.tracking_url" target="_blank" rel="noopener" class="link" dir="ltr">{{ s.tracking_number }}</a>
            <span v-else dir="ltr">{{ s.tracking_number ?? '' }}</span>
          </div>
          <p v-if="!order.shipments.length" class="text-sm text-ink-500">{{ $t('admin.orders_page.no_shipments') }}</p>
        </div>
      </div>

      <div class="space-y-6">
        <div class="card p-4 text-sm">
          <h2 class="mb-2 font-bold">{{ $t('admin.orders_page.customer') }}</h2>
          <p class="font-medium">{{ order.customer?.name ?? order.shipping_address.full_name }}</p>
          <p v-if="order.customer?.email" dir="ltr" class="text-ink-500">{{ order.customer.email }}</p>
          <p v-if="order.customer?.phone || order.shipping_address.phone" dir="ltr" class="text-ink-500">{{ order.customer?.phone ?? order.shipping_address.phone }}</p>
          <h3 class="mb-1 mt-4 font-semibold">{{ $t('admin.orders_page.ship_to') }}</h3>
          <p>{{ order.shipping_address.address_line_1 }}<span v-if="order.shipping_address.address_line_2">, {{ order.shipping_address.address_line_2 }}</span></p>
          <p>{{ [order.shipping_address.district, order.shipping_address.city, order.shipping_address.state].filter(Boolean).join(', ') }}</p>
          <p>{{ order.country_code ? $t(`country.${order.country_code}`) : '' }}</p>
          <p v-if="order.shipping_method" class="mt-3 text-ink-500">{{ tr(order.shipping_method.name as never) || order.shipping_method.code }}</p>
        </div>

        <div class="card p-4 text-sm">
          <h2 class="mb-2 font-bold">{{ $t('admin.refunds') }}</h2>
          <div v-for="r in refunds" :key="r.id" class="mb-2 flex items-center justify-between">
            <span>{{ $t(`admin.refunds_page.reasons.${r.reason_code}`) }}</span>
            <span class="flex items-center gap-2"><StatusBadge :status="r.status" /> {{ formatMoney({ amount: r.amount, currency: r.currency }, locale) }}</span>
          </div>
          <p v-if="!refunds.length" class="text-ink-500">{{ $t('common.none') }}</p>
        </div>

        <div class="card p-4 text-sm">
          <h2 class="mb-2 font-bold">{{ $t('orders.invoices') }}</h2>
          <a v-for="inv in invoices" :key="inv.id" :href="`${config.public.apiBase}/invoices/${inv.id}/html`" target="_blank" rel="noopener" class="link me-3 block" dir="ltr">{{ inv.number }} · {{ inv.type === 'credit_note' ? $t('orders.credit_note') : $t('orders.invoice') }}</a>
          <p v-if="!invoices.length" class="text-ink-500">{{ $t('common.none') }}</p>
        </div>
      </div>
    </div>

    <AdminModal :open="refundOpen" :title="$t('admin.refund')" @close="refundOpen = false">
      <form class="space-y-3" @submit.prevent="submitRefund">
        <label class="block"><span class="label">{{ $t('admin.amount') }} ({{ order.currency }}) · {{ $t('admin.orders_page.max', { amount: refundable }) }}</span><input v-model="refundForm.amount" class="input" inputmode="decimal" required dir="ltr"></label>
        <label class="block"><span class="label">{{ $t('admin.reason') }}</span>
          <select v-model="refundForm.reason_code" class="input"><option v-for="c in reasonCodes" :key="c" :value="c">{{ $t(`admin.refunds_page.reasons.${c}`) }}</option></select>
        </label>
        <label class="block"><span class="label">{{ $t('admin.common.note') }}</span><textarea v-model="refundForm.reason" class="input" rows="2" /></label>
        <p class="text-xs text-ink-500">{{ $t('admin.orders_page.refund_hint') }}</p>
        <div class="flex justify-end gap-2"><button type="button" class="btn-outline" @click="refundOpen = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('admin.refund') }}</button></div>
      </form>
    </AdminModal>
  </section>
  <AdminAlerts v-else :error="error" />
</template>
