<script setup lang="ts">
import type { Address, CheckoutSession, Envelope, Payment, Quote, ShippingOption } from '~/types/api'

definePageMeta({ middleware: 'auth' })
const { request, csrf, country, idempotencyKey } = useApi()
const { locale, t } = useI18n()
const localePath = useLocalePath()

const addresses = ref<Address[]>([])
const regions = ref<{ code: string, name: string }[]>([])
const addressId = ref<string | null>(null)
const options = ref<ShippingOption[]>([])
const method = ref('standard')
const quote = ref<Quote | null>(null)
const couponInput = ref('')
const coupon = ref<string | null>(null)
const couponError = ref('')
// PayPal charges USD at the fixed peg (same rate as the API).
// Wrapped in left-to-right isolates so "$39.48" reads correctly inside Arabic text.
const usd = (amount: string, currency: string) => `⁦$${(Number(amount) / (currency === 'SAR' ? 3.75 : 3.6725)).toFixed(2)}⁩`
interface PayMethod { provider: string, currency_note: string | null, fee?: { amount: string, currency: string }, max_amount?: { amount: string, currency: string } | null, available?: boolean, reason?: string | null, requires_code?: boolean }
const payMethods = ref<PayMethod[]>([])
const payWith = ref('')
onMounted(async () => {
  payMethods.value = (await request<Envelope<PayMethod[]>>('/payments/methods').catch(() => null))?.data ?? []
  payWith.value = payMethods.value.find(m => m.available !== false)?.provider ?? ''
})
const error = ref('')
const busy = ref(false)
const points = ref<{ balance: number, min_redeem: number, balance_value: { amount: string, currency: string } } | null>(null)
onMounted(async () => {
  points.value = (await request<Envelope<{ balance: number, min_redeem: number, balance_value: { amount: string, currency: string } }>>('/loyalty').catch(() => null))?.data ?? null
})
const showForm = ref(false)
const form = reactive({ full_name: '', phone: '', address_line_1: '', address_line_2: '', district: '', city: '', state: '', postal_code: '', national_address_code: '' })
const pin = ref<{ lat: number, lng: number } | null>(null)

const m = (amount: string) => formatMoney({ amount, currency: quote.value?.currency ?? 'AED' }, locale.value)

async function loadAddresses() {
  const res = await request<Envelope<Address[]>>('/addresses')
  addresses.value = res.data.filter(a => a.country === country.value)
  addressId.value = addresses.value.find(a => a.is_default)?.id ?? addresses.value[0]?.id ?? null
  showForm.value = addresses.value.length === 0
  regions.value = (await request<Envelope<{ regions: { code: string, name: string }[] }>>(`/countries/${country.value}/address-format`)).data.regions
}

async function refreshQuote() {
  error.value = ''
  quote.value = null
  if (!addressId.value) return
  try {
    options.value = (await request<Envelope<ShippingOption[]>>('/checkout/shipping-options', { method: 'POST', body: { address_id: addressId.value } })).data
    if (!options.value.some(o => o.code === method.value)) method.value = options.value[0]?.code ?? 'standard'
    quote.value = await fetchQuote(coupon.value)
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

const tracking = useTracking()
let checkoutTracked = false
watch(quote, (q) => {
  if (q && !checkoutTracked) {
    checkoutTracked = true
    tracking.track('begin_checkout', { value: Number(q.total), currency: q.currency })
  }
})

const isCod = computed(() => payWith.value === 'cod')
async function fetchQuote(code: string | null) {
  return (await request<Envelope<Quote>>('/checkout/quote', { method: 'POST', body: { address_id: addressId.value, shipping_method: method.value, coupon_code: code || undefined, payment_method: isCod.value ? 'cod' : undefined } })).data
}
/** Cash on delivery adds its fee: re-quote when switching. Over the limit, fall back to online payment. */
watch(payWith, async () => {
  if (!addressId.value) return
  try {
    quote.value = await fetchQuote(coupon.value)
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
    if (isCod.value) payWith.value = payMethods.value.find(m => m.provider !== 'cod')?.provider ?? ''
  }
})
const codMethod = computed(() => payMethods.value.find(m => m.provider === 'cod'))
const codTooHigh = computed(() => !!(codMethod.value?.max_amount && quote.value && !isCod.value && Number(quote.value.total) + Number(codMethod.value.fee?.amount ?? 0) > Number(codMethod.value.max_amount.amount)))

// SMS confirmation step for cash on delivery
const codPayment = ref<Payment | null>(null)
const codCode = ref('')
const codMsg = ref('')
async function confirmCod() {
  if (!codPayment.value) return
  busy.value = true
  error.value = ''
  try {
    await csrf()
    const p = (await request<Envelope<Payment>>(`/payments/${codPayment.value.id}/cod-confirm`, { method: 'POST', body: { code: codCode.value } })).data
    await navigateTo(localePath(`/account/orders/${p.order_id}?placed=cod`))
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
  finally {
    busy.value = false
  }
}
async function resendCod() {
  if (!codPayment.value) return
  try {
    await request(`/payments/${codPayment.value.id}/cod-resend`, { method: 'POST' })
    codMsg.value = t('cod.resent')
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

async function applyCoupon() {
  couponError.value = ''
  const code = couponInput.value.trim()
  if (!code || !addressId.value) return
  try {
    quote.value = await fetchQuote(code)
    coupon.value = quote.value.coupon?.code ?? code
  }
  catch (e) {
    couponError.value = apiErrorMessage(e, t('errors.generic'))
  }
}

async function removeCoupon() {
  coupon.value = null
  couponInput.value = ''
  await refreshQuote()
}

async function saveAddress() {
  error.value = ''
  try {
    await csrf()
    const body = { ...Object.fromEntries(Object.entries({ ...form, country: country.value }).filter(([, v]) => v !== '')), ...(pin.value ? { latitude: pin.value.lat, longitude: pin.value.lng } : {}) }
    const res = await request<Envelope<Address>>('/addresses', { method: 'POST', body })
    await loadAddresses()
    addressId.value = res.data.id
    showForm.value = false
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

async function placeOrder() {
  if (!addressId.value) return
  busy.value = true
  error.value = ''
  try {
    await csrf()
    const session = (await request<Envelope<CheckoutSession>>('/checkout', { method: 'POST', body: { address_id: addressId.value, shipping_method: method.value, coupon_code: coupon.value || undefined, payment_method: isCod.value ? 'cod' : undefined } })).data
    const order = (await request<Envelope<{ id: string }>>('/orders', { method: 'POST', body: { checkout_session_id: session.id }, headers: { 'Idempotency-Key': idempotencyKey('order') } })).data
    const payment = (await request<Envelope<Payment>>('/payments/intent', { method: 'POST', body: { order_id: order.id, provider: payWith.value || undefined }, headers: { 'Idempotency-Key': idempotencyKey('pay') } })).data
    useCartStore().cart = null
    if (payment.provider === 'cod') {
      if (payment.status === 'requires_action') codPayment.value = payment
      else await navigateTo(localePath(`/account/orders/${payment.order_id}?placed=cod`))
      return
    }
    if (payment.redirect_url) window.location.href = payment.redirect_url
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
  finally {
    busy.value = false
  }
}

onMounted(async () => {
  await loadAddresses()
  await refreshQuote()
})
watch([addressId, method], refreshQuote)
watch(country, async () => {
  await loadAddresses()
  await refreshQuote()
})

const fieldNames = ['full_name', 'phone', 'address_line_1', 'address_line_2', 'district', 'city'] as const
</script>

<template>
  <section class="grid gap-6 md:grid-cols-[minmax(0,1fr)_400px] md:items-start md:gap-10">
    <div class="space-y-5">
      <h1 class="text-lg font-extrabold uppercase tracking-wide md:text-xl">{{ $t('checkout.title') }}</h1>

      <fieldset class="space-y-2 rounded-md border border-ink-100 p-4 md:p-5">
        <legend class="flex items-center gap-2 px-1 text-sm font-extrabold uppercase tracking-wide"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink-900 text-xs text-white">1</span>{{ $t('checkout.address') }}</legend>
        <label v-for="a in addresses" :key="a.id" class="flex cursor-pointer gap-3 rounded-md border p-3 text-sm transition" :class="addressId === a.id ? 'border-ink-900 bg-ink-100/50' : 'border-ink-100 hover:border-ink-300'">
          <input v-model="addressId" type="radio" name="address" :value="a.id" class="mt-1 accent-ink-900">
          <span><b class="block">{{ a.full_name }}</b><span class="text-ink-700">{{ a.address_line_1 }}, {{ a.city }}</span> · <span dir="ltr" class="text-ink-500">{{ a.phone }}</span></span>
        </label>
        <button v-if="!showForm" type="button" class="flex items-center gap-1 text-sm font-semibold underline underline-offset-4" @click="showForm = true"><AppIcon name="plus" :size="15" />{{ $t('checkout.new_address') }}</button>
        <form v-if="showForm" class="grid gap-3 pt-2 md:grid-cols-2" data-test="address-form" @submit.prevent="saveAddress">
          <label v-for="f in fieldNames" :key="f" class="block" :class="f.startsWith('address_line') ? 'md:col-span-2' : ''"><span class="label">{{ $t(`checkout.fields.${f}`) }}</span>
            <input v-model="form[f]" :required="!['address_line_2', 'district'].includes(f)" :dir="f === 'phone' ? 'ltr' : undefined" class="input" :name="f">
          </label>
          <label class="block"><span class="label">{{ $t('checkout.fields.state') }}</span>
            <select v-model="form.state" required class="input" name="state">
              <option v-for="r in regions" :key="r.code" :value="r.code">{{ r.name }}</option>
            </select>
          </label>
          <template v-if="country === 'SA'">
            <label class="block"><span class="label">{{ $t('checkout.fields.postal_code') }}</span><input v-model="form.postal_code" class="input" dir="ltr"></label>
            <label class="block"><span class="label">{{ $t('checkout.fields.national_address_code') }}</span><input v-model="form.national_address_code" class="input" dir="ltr"></label>
          </template>
          <div class="md:col-span-2">
            <span class="label">{{ $t('map.title') }}</span>
            <ClientOnly><MapPin v-model="pin" :country="country" /></ClientOnly>
          </div>
          <div class="md:col-span-2"><button class="btn-primary">{{ $t('common.save') }}</button></div>
        </form>
      </fieldset>

      <fieldset v-if="options.length" class="space-y-2 rounded-md border border-ink-100 p-4 md:p-5">
        <legend class="flex items-center gap-2 px-1 text-sm font-extrabold uppercase tracking-wide"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink-900 text-xs text-white">2</span>{{ $t('checkout.shipping') }}</legend>
        <label v-for="o in options" :key="o.code" class="flex cursor-pointer items-center justify-between gap-3 rounded-md border p-3 text-sm transition" :class="method === o.code ? 'border-ink-900 bg-ink-100/50' : 'border-ink-100 hover:border-ink-300'">
          <span class="flex items-center gap-3"><input v-model="method" type="radio" name="method" :value="o.code" class="accent-ink-900"><AppIcon name="truck" :size="18" />{{ o.name }}</span>
          <span class="font-bold" :class="o.free ? 'text-green-700' : ''">{{ o.free ? $t('checkout.free') : formatMoney(o.price, locale) }}</span>
        </label>
      </fieldset>

      <fieldset v-if="payMethods.length > 1 && quote" class="space-y-2 rounded-md border border-ink-100 p-4 md:p-5" data-test="pay-methods">
        <legend class="flex items-center gap-2 px-1 text-sm font-extrabold uppercase tracking-wide"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink-900 text-xs text-white">3</span>{{ $t('pay_method.title') }}</legend>
        <label v-for="pm in payMethods" :key="pm.provider" class="flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm transition" :class="payWith === pm.provider ? 'border-ink-900 bg-ink-100/50' : 'border-ink-100 hover:border-ink-300'">
          <input v-model="payWith" type="radio" name="pay-with" :value="pm.provider" class="accent-ink-900" :disabled="pm.available === false || (pm.provider === 'cod' && codTooHigh)" :data-test="`pay-${pm.provider}`">
          <span class="flex-1">
            <span class="block font-semibold">{{ $t(`pay_method.${pm.provider}`, pm.provider) }}</span>
            <span v-if="pm.provider === 'cod'" class="block text-xs text-ink-500" data-test="cod-note">
              <template v-if="pm.available === false">{{ $t(`cod.unavailable.${pm.reason ?? 'not_offered'}`) }}</template>
              <template v-else-if="codTooHigh">{{ $t('cod.too_high', { amount: formatMoney(pm.max_amount, locale) }) }}</template>
              <template v-else>{{ $t('cod.note', { fee: formatMoney(pm.fee, locale) }) }}</template>
            </span>
            <span v-if="pm.currency_note" class="block text-xs text-ink-500">{{ $t('pay_method.usd_note', { amount: usd(quote.total, quote.currency) }) }}</span>
          </span>
          <span v-if="pm.provider === 'paypal'" class="font-bold italic text-[#003087]" dir="ltr">Pay<span class="text-[#009cde]">Pal</span></span>
        </label>
      </fieldset>
    </div>

    <aside class="rounded-md bg-ink-100/60 p-5 md:sticky md:top-36">
      <h2 class="mb-4 text-sm font-extrabold uppercase tracking-wide">{{ $t('checkout.summary') }}</h2>
      <p v-if="error" role="alert" class="mb-3 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
      <div v-if="!quote && !error" class="space-y-2"><div class="h-4 animate-pulse rounded bg-white" /><div class="h-4 w-2/3 animate-pulse rounded bg-white" /></div>
      <template v-if="quote">
        <p v-if="quote.shipments.length > 1" class="mb-3 rounded-md bg-white p-2.5 text-xs">{{ $t('checkout.multi_package', { n: quote.shipments.length }) }}</p>
        <div v-for="s in quote.shipments" :key="s.number" class="mb-4">
          <p class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-ink-500"><AppIcon name="box" :size="14" />{{ $t('checkout.shipment', { n: s.number }) }} · {{ $t('common.days', { min: s.estimate.min_days, max: s.estimate.max_days }) }}</p>
          <ul class="space-y-1 text-sm">
            <li v-for="i in quote.items.filter(x => x.shipment === s.number)" :key="i.variant_id" class="flex justify-between gap-3">
              <span class="line-clamp-1 text-ink-700">{{ i.quantity }} × {{ i.title }}</span><span class="shrink-0">{{ formatMoney(i.line_total, locale) }}</span>
            </li>
          </ul>
        </div>
        <dl class="space-y-1.5 border-t border-ink-300/60 pt-3 text-sm">
          <div class="flex justify-between"><dt>{{ $t('cart.subtotal') }}</dt><dd>{{ m(quote.subtotal) }}</dd></div>
          <div class="flex justify-between"><dt>{{ $t('checkout.shipping_fee') }}</dt><dd :class="quote.shipping_method.free ? 'font-semibold text-green-700' : ''">{{ quote.shipping_method.free ? $t('checkout.free') : m(quote.shipping) }}</dd></div>
          <div v-if="Number(quote.cod_fee ?? 0) > 0" class="flex justify-between text-xs text-ink-500" data-test="cod-fee"><dt>{{ $t('cod.fee_included') }}</dt><dd>{{ m(quote.cod_fee!) }}</dd></div>
          <div v-if="Number(quote.customs) > 0" class="flex justify-between"><dt>{{ $t('checkout.customs') }}</dt><dd>{{ m(quote.customs) }}</dd></div>
          <div v-if="Number(quote.discount) > 0" class="flex justify-between text-brand-700"><dt>{{ $t('coupon.discount') }} <span dir="ltr">({{ quote.coupon?.code }})</span></dt><dd data-test="quote-discount">−{{ m(quote.discount) }}</dd></div>
          <div class="flex justify-between border-t border-ink-300/60 pt-2 text-lg font-extrabold"><dt>{{ $t('common.total') }}</dt><dd data-test="quote-total">{{ m(quote.total) }}</dd></div>
          <div class="flex justify-between text-xs text-ink-500"><dt>{{ $t('checkout.vat_included') }}</dt><dd>{{ m(quote.tax) }}</dd></div>
        </dl>
        <div class="mt-4" data-test="coupon-box">
          <div v-if="coupon" class="flex items-center justify-between rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">
            <span>{{ $t('coupon.applied') }} <b dir="ltr">{{ coupon }}</b></span>
            <button type="button" class="font-semibold underline" @click="removeCoupon">{{ $t('common.remove') }}</button>
          </div>
          <form v-else class="flex gap-2" @submit.prevent="applyCoupon">
            <input v-model="couponInput" class="input flex-1 bg-white uppercase" :placeholder="$t('coupon.placeholder')" dir="ltr" data-test="coupon-input">
            <button class="btn-outline btn-sm bg-white" data-test="coupon-apply">{{ $t('coupon.apply') }}</button>
          </form>
          <p v-if="couponError" role="alert" class="mt-1 text-xs text-red-600">{{ couponError }}</p>
          <NuxtLink v-if="!coupon && points && points.balance >= points.min_redeem" :to="localePath('/account/rewards')" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-brand-700 underline underline-offset-2" data-test="points-hint">
            <AppIcon name="coins" :size="14" />{{ $t('rewards.checkout_hint', { points: points.balance, amount: formatMoney(points.balance_value, locale) }) }}
          </NuxtLink>
        </div>
        <form v-if="codPayment" class="mt-5 space-y-2 rounded-md bg-white p-4" data-test="cod-confirm" @submit.prevent="confirmCod">
          <p class="text-sm font-bold">{{ $t('cod.enter_code') }}</p>
          <p class="text-xs text-ink-500">{{ $t('cod.code_sent') }}</p>
          <input v-model="codCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="input text-center font-mono text-lg tracking-[0.4em]" dir="ltr" required data-test="cod-code">
          <button :disabled="busy" class="btn-primary h-11 w-full font-bold">{{ $t('cod.confirm') }}</button>
          <button type="button" class="w-full text-xs underline" @click="resendCod">{{ $t('cod.resend') }}</button>
          <p v-if="codMsg" class="text-xs text-green-700">{{ codMsg }}</p>
        </form>
        <button v-else :disabled="busy" class="btn-primary mt-5 h-12 w-full text-base font-bold uppercase tracking-wide" data-test="place-order" @click="placeOrder">{{ isCod ? $t('cod.place') : $t('checkout.place_order') }}</button>
        <p class="mt-3 flex items-center justify-center gap-2 text-xs text-ink-500"><AppIcon name="shield" :size="15" /> {{ $t('footer.secure') }}</p>
      </template>
    </aside>
  </section>
</template>
