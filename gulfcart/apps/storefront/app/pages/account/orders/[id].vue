<script setup lang="ts">
import type { Envelope, OrderDetail, Payment } from '~/types/api'

definePageMeta({ middleware: 'auth' })
const route = useRoute()
const { request, csrf, idempotencyKey } = useApi()
const { locale, t } = useI18n()

const order = ref<OrderDetail | null>(null)
const invoices = ref<{ id: string, number: string, type: 'invoice' | 'credit_note' }[]>([])
const config = useRuntimeConfig()
const error = ref('')
const notice = ref('')
const returnMode = ref(false)
const returnQty = reactive<Record<string, number>>({})
const returnReason = ref('size_fit')
const reasons = ['damaged', 'wrong_item', 'not_as_described', 'size_fit', 'changed_mind', 'other']

async function load() {
  order.value = (await request<Envelope<OrderDetail>>(`/orders/${route.params.id}`)).data
  invoices.value = (await request<Envelope<typeof invoices.value>>(`/orders/${route.params.id}/invoices`)).data
}

async function run(fn: () => Promise<void>) {
  error.value = ''
  try {
    await csrf()
    await fn()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

const cancel = () => run(async () => {
  if (!confirm(t('orders.cancel_confirm'))) return
  order.value = (await request<Envelope<OrderDetail>>(`/orders/${route.params.id}/cancel`, { method: 'POST' })).data
})

const pay = () => run(async () => {
  const payment = (await request<Envelope<Payment>>('/payments/intent', { method: 'POST', body: { order_id: route.params.id }, headers: { 'Idempotency-Key': idempotencyKey('pay') } })).data
  if (payment.redirect_url) window.location.href = payment.redirect_url
})

const submitReturn = () => run(async () => {
  const items = Object.entries(returnQty).filter(([, q]) => q > 0).map(([order_item_id, quantity]) => ({ order_item_id, quantity, reason_code: returnReason.value }))
  if (!items.length) return
  await request(`/orders/${route.params.id}/return`, { method: 'POST', body: { items } })
  returnMode.value = false
  notice.value = t('orders.returned')
})

const delivered = computed(() => order.value?.packages.flatMap(p => p.items).filter(i => i.status === 'delivered' && i.quantity > i.refunded_quantity) ?? [])

onMounted(load)
</script>

<template>
  <section v-if="order" class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-semibold">{{ $t('orders.order', { number: order.order_number }) }}</h1>
      <StatusBadge :status="order.status" scope="orders.status" />
      <span v-if="order.refund_state !== 'none'" class="text-sm text-gray-600">{{ $t(`orders.refund_state.${order.refund_state}`) }}</span>
    </div>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <p v-if="notice" role="status" class="text-green-700">{{ notice }}</p>

    <div class="flex flex-wrap gap-2">
      <button v-if="['pending_payment', 'payment_failed'].includes(order.status)" class="rounded bg-gray-900 px-4 py-2 text-white" @click="pay">{{ $t('orders.pay_now') }}</button>
      <button v-if="order.can_cancel" class="rounded border px-4 py-2" data-test="cancel-order" @click="cancel">{{ $t('orders.cancel') }}</button>
      <button v-if="delivered.length" class="rounded border px-4 py-2" @click="returnMode = !returnMode">{{ $t('orders.return') }}</button>
    </div>

    <form v-if="returnMode" class="space-y-2 rounded border p-3" @submit.prevent="submitReturn">
      <label v-for="i in delivered" :key="i.id" class="flex items-center gap-2">
        <input v-model.number="returnQty[i.id]" type="number" min="0" :max="i.quantity - i.refunded_quantity" class="w-16 rounded border px-1"> {{ i.title }}
      </label>
      <label class="block">{{ $t('orders.return_reason') }}
        <select v-model="returnReason" class="ms-2 rounded border px-2">
          <option v-for="r in reasons" :key="r" :value="r">{{ $t(`orders.reasons.${r}`) }}</option>
        </select>
      </label>
      <button class="rounded bg-gray-900 px-4 py-1 text-white">{{ $t('orders.return_submit') }}</button>
    </form>

    <div v-for="p in order.packages" :key="p.number" class="rounded border p-3" data-test="package">
      <div class="mb-2 flex flex-wrap items-center gap-2">
        <h2 class="font-medium">{{ $t('orders.package', { n: p.number }) }}</h2>
        <StatusBadge :status="p.status" scope="orders.package_status" />
        <template v-for="tr in p.tracking" :key="tr.tracking_number ?? ''">
          <a v-if="tr.tracking_url" :href="tr.tracking_url" target="_blank" rel="noopener" class="text-sm underline" dir="ltr">{{ tr.carrier }} {{ tr.tracking_number }}</a>
        </template>
      </div>
      <ul class="text-sm">
        <li v-for="i in p.items" :key="i.id" class="flex justify-between py-1">
          <span>{{ i.quantity }} × {{ i.title }} <span class="text-gray-500">{{ Object.values(i.attributes).join(' / ') }}</span></span>
          <span>{{ formatMoney(i.line_total, locale) }}</span>
        </li>
      </ul>
      <ol v-for="tr in p.tracking" :key="`ev-${tr.tracking_number}`" class="mt-2 border-s ps-3 text-xs text-gray-600">
        <li v-for="e in tr.events" :key="e.code + e.occurred_at">{{ new Date(e.occurred_at).toLocaleString(locale) }} — {{ e.description }}</li>
      </ol>
    </div>

    <div v-if="invoices.length" class="text-sm">
      <h2 class="font-medium">{{ $t('orders.invoices') }}</h2>
      <a v-for="inv in invoices" :key="inv.id" :href="`${config.public.apiBase}/invoices/${inv.id}/html`" target="_blank" rel="noopener" class="me-4 underline" dir="ltr" data-test="invoice-link">
        {{ $t(`orders.${inv.type}`) }} {{ inv.number }}
      </a>
    </div>

    <dl class="ms-auto max-w-sm space-y-1 text-sm">
      <div class="flex justify-between"><dt>{{ $t('cart.subtotal') }}</dt><dd>{{ formatMoney(order.totals.subtotal, locale) }}</dd></div>
      <div class="flex justify-between"><dt>{{ $t('checkout.shipping_fee') }}</dt><dd>{{ formatMoney(order.totals.shipping, locale) }}</dd></div>
      <div v-if="Number(order.totals.customs.amount) > 0" class="flex justify-between"><dt>{{ $t('checkout.customs') }}</dt><dd>{{ formatMoney(order.totals.customs, locale) }}</dd></div>
      <div class="flex justify-between font-semibold"><dt>{{ $t('common.total') }}</dt><dd>{{ formatMoney(order.totals.total, locale) }}</dd></div>
      <div class="flex justify-between text-gray-500"><dt>{{ $t('checkout.vat_included') }}</dt><dd>{{ formatMoney(order.totals.tax, locale) }}</dd></div>
    </dl>
  </section>
  <p v-else>{{ $t('common.loading') }}</p>
</template>
