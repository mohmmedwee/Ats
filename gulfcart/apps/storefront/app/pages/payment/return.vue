<script setup lang="ts">
import type { Envelope, Payment } from '~/types/api'

definePageMeta({ middleware: 'auth' })
const route = useRoute()
const { request, csrf } = useApi()
const { locale, t } = useI18n()
const localePath = useLocalePath()

const payment = ref<Payment | null>(null)
const error = ref('')
const busy = ref(false)
const id = computed(() => String(route.query.payment ?? ''))

async function load() {
  payment.value = (await request<Envelope<Payment>>(`/payments/${id.value}`)).data
}

async function complete(outcome: 'success' | 'failure') {
  busy.value = true
  error.value = ''
  try {
    await csrf()
    payment.value = (await request<Envelope<Payment>>(`/payments/${id.value}/sandbox-complete`, { method: 'POST', body: { outcome } })).data
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
  finally {
    busy.value = false
  }
}

const tracking = useTracking()
watch(payment, (p) => {
  // Purchase is reported once per payment (survives reloads of this page).
  if (!p || p.status !== 'captured' || !import.meta.client) return
  const key = `gc_tracked_${p.id}`
  try {
    if (localStorage.getItem(key)) return
    localStorage.setItem(key, '1')
  }
  catch {
    // storage unavailable: report anyway
  }
  tracking.track('purchase', { value: Number(p.amount.amount), currency: p.amount.currency, orderId: p.order_number ?? p.order_id })
})

// Real providers confirm by webhook a few seconds after the redirect: poll until final (max ~2 min).
let polls = 0
let confirmed = false
let timer: ReturnType<typeof setTimeout> | undefined
async function poll() {
  await load().catch(() => undefined)
  // PayPal: the buyer approved, so we capture now (once). Cancelled on PayPal: nothing to capture.
  if (['paypal', 'tabby', 'tamara'].includes(payment.value?.provider ?? '') && payment.value?.status === 'requires_action' && !confirmed && !route.query.cancelled && !route.query.failed) {
    confirmed = true
    await csrf()
    payment.value = (await request<Envelope<Payment>>(`/payments/${id.value}/confirm`, { method: 'POST' }).catch(() => ({ data: payment.value! }))).data
  }
  const p = payment.value
  if (p && p.provider !== 'sandbox' && !['captured', 'failed', 'cancelled'].includes(p.status) && polls++ < 40) {
    timer = setTimeout(poll, 3000)
  }
}
onMounted(poll)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <section class="mx-auto max-w-md space-y-4 text-center">
    <h1 class="text-2xl font-semibold">{{ $t('payment.title') }}</h1>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <template v-if="payment">
      <p class="text-gray-600">{{ payment.order_number }}</p>
      <template v-if="payment.status === 'captured'">
        <p class="text-green-700" data-test="payment-success">{{ $t('payment.success') }}</p>
        <NuxtLink :to="localePath(`/account/orders/${payment.order_id}`)" class="inline-block rounded bg-gray-900 px-6 py-2 text-white">{{ $t('payment.view_order') }}</NuxtLink>
      </template>
      <template v-else-if="payment.status === 'failed'">
        <p class="text-red-600">{{ $t('payment.failed') }}</p>
        <NuxtLink :to="localePath(`/account/orders/${payment.order_id}`)" class="underline">{{ $t('payment.view_order') }}</NuxtLink>
      </template>
      <template v-else-if="payment.provider === 'sandbox'">
        <p class="rounded bg-amber-50 p-2 text-sm">{{ $t('payment.sandbox_notice') }}</p>
        <button :disabled="busy" class="w-full rounded bg-gray-900 py-2 text-white" data-test="sandbox-pay" @click="complete('success')">
          {{ $t('payment.pay', { amount: formatMoney(payment.amount, locale) }) }}
        </button>
        <button :disabled="busy" class="w-full rounded border py-2" @click="complete('failure')">{{ $t('payment.fail') }}</button>
      </template>
      <template v-else-if="route.query.cancelled">
        <p class="text-ink-700">{{ $t('pay_method.cancelled') }}</p>
        <NuxtLink :to="localePath(`/account/orders/${payment.order_id}`)" class="btn-primary">{{ $t('orders.pay_now') }}</NuxtLink>
      </template>
      <p v-else class="flex items-center justify-center gap-2 text-ink-700"><span class="h-4 w-4 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" /> {{ $t('payment.processing') }}</p>
    </template>
  </section>
</template>
