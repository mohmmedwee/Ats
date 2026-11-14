<script setup lang="ts">
import type { Address, CheckoutSession, Envelope, Payment, Quote, ShippingOption } from '~/types/api'

definePageMeta({ middleware: 'auth' })
const { request, csrf, country, idempotencyKey } = useApi()
const { locale, t } = useI18n()

const addresses = ref<Address[]>([])
const regions = ref<{ code: string, name: string }[]>([])
const addressId = ref<string | null>(null)
const options = ref<ShippingOption[]>([])
const method = ref('standard')
const quote = ref<Quote | null>(null)
const error = ref('')
const busy = ref(false)
const showForm = ref(false)
const form = reactive({ full_name: '', phone: '', address_line_1: '', address_line_2: '', district: '', city: '', state: '', postal_code: '', national_address_code: '' })

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
    quote.value = (await request<Envelope<Quote>>('/checkout/quote', { method: 'POST', body: { address_id: addressId.value, shipping_method: method.value } })).data
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

async function saveAddress() {
  error.value = ''
  try {
    await csrf()
    const body = Object.fromEntries(Object.entries({ ...form, country: country.value }).filter(([, v]) => v !== ''))
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
    const session = (await request<Envelope<CheckoutSession>>('/checkout', { method: 'POST', body: { address_id: addressId.value, shipping_method: method.value } })).data
    const order = (await request<Envelope<{ id: string }>>('/orders', { method: 'POST', body: { checkout_session_id: session.id }, headers: { 'Idempotency-Key': idempotencyKey('order') } })).data
    const payment = (await request<Envelope<Payment>>('/payments/intent', { method: 'POST', body: { order_id: order.id }, headers: { 'Idempotency-Key': idempotencyKey('pay') } })).data
    useCartStore().cart = null
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
  <section class="grid gap-8 md:grid-cols-2">
    <div class="space-y-6">
      <h1 class="text-2xl font-semibold">{{ $t('checkout.title') }}</h1>

      <fieldset class="space-y-2">
        <legend class="font-medium">{{ $t('checkout.address') }}</legend>
        <label v-for="a in addresses" :key="a.id" class="flex gap-2 rounded border p-2">
          <input v-model="addressId" type="radio" name="address" :value="a.id">
          <span>{{ a.full_name }} — {{ a.address_line_1 }}, {{ a.city }} <span dir="ltr">{{ a.phone }}</span></span>
        </label>
        <button v-if="!showForm" type="button" class="text-sm underline" @click="showForm = true">{{ $t('checkout.new_address') }}</button>
        <form v-if="showForm" class="space-y-2 rounded border p-3" data-test="address-form" @submit.prevent="saveAddress">
          <label v-for="f in fieldNames" :key="f" class="block text-sm">{{ $t(`checkout.fields.${f}`) }}
            <input v-model="form[f]" :required="!['address_line_2', 'district'].includes(f)" :dir="f === 'phone' ? 'ltr' : undefined" class="mt-1 w-full rounded border px-2 py-1" :name="f">
          </label>
          <label class="block text-sm">{{ $t('checkout.fields.state') }}
            <select v-model="form.state" required class="mt-1 w-full rounded border px-2 py-1" name="state">
              <option v-for="r in regions" :key="r.code" :value="r.code">{{ r.name }}</option>
            </select>
          </label>
          <template v-if="country === 'SA'">
            <label class="block text-sm">{{ $t('checkout.fields.postal_code') }}<input v-model="form.postal_code" class="mt-1 w-full rounded border px-2 py-1" dir="ltr"></label>
            <label class="block text-sm">{{ $t('checkout.fields.national_address_code') }}<input v-model="form.national_address_code" class="mt-1 w-full rounded border px-2 py-1" dir="ltr"></label>
          </template>
          <button class="rounded bg-gray-900 px-4 py-1 text-white">{{ $t('common.save') }}</button>
        </form>
      </fieldset>

      <fieldset v-if="options.length" class="space-y-2">
        <legend class="font-medium">{{ $t('checkout.shipping') }}</legend>
        <label v-for="o in options" :key="o.code" class="flex justify-between rounded border p-2">
          <span><input v-model="method" type="radio" name="method" :value="o.code"> {{ o.name }}</span>
          <span>{{ o.free ? $t('checkout.free') : formatMoney(o.price, locale) }}</span>
        </label>
      </fieldset>
    </div>

    <aside class="h-fit rounded border p-4">
      <h2 class="mb-3 font-medium">{{ $t('checkout.summary') }}</h2>
      <p v-if="error" role="alert" class="mb-3 text-red-600">{{ error }}</p>
      <template v-if="quote">
        <p v-if="quote.shipments.length > 1" class="mb-3 rounded bg-blue-50 p-2 text-sm">{{ $t('checkout.multi_package', { n: quote.shipments.length }) }}</p>
        <div v-for="s in quote.shipments" :key="s.number" class="mb-3">
          <p class="text-sm font-medium">{{ $t('checkout.shipment', { n: s.number }) }} · {{ $t('common.days', { min: s.estimate.min_days, max: s.estimate.max_days }) }}</p>
          <ul class="text-sm">
            <li v-for="i in quote.items.filter(x => x.shipment === s.number)" :key="i.variant_id" class="flex justify-between">
              <span>{{ i.quantity }} × {{ i.title }}</span><span>{{ formatMoney(i.line_total, locale) }}</span>
            </li>
          </ul>
        </div>
        <dl class="space-y-1 border-t pt-2 text-sm">
          <div class="flex justify-between"><dt>{{ $t('cart.subtotal') }}</dt><dd>{{ m(quote.subtotal) }}</dd></div>
          <div class="flex justify-between"><dt>{{ $t('checkout.shipping_fee') }}</dt><dd>{{ quote.shipping_method.free ? $t('checkout.free') : m(quote.shipping) }}</dd></div>
          <div v-if="Number(quote.customs) > 0" class="flex justify-between"><dt>{{ $t('checkout.customs') }}</dt><dd>{{ m(quote.customs) }}</dd></div>
          <div class="flex justify-between text-lg font-semibold"><dt>{{ $t('common.total') }}</dt><dd data-test="quote-total">{{ m(quote.total) }}</dd></div>
          <div class="flex justify-between text-gray-500"><dt>{{ $t('checkout.vat_included') }}</dt><dd>{{ m(quote.tax) }}</dd></div>
        </dl>
        <button :disabled="busy" class="mt-4 w-full rounded bg-gray-900 py-2 text-white disabled:opacity-50" data-test="place-order" @click="placeOrder">{{ $t('checkout.place_order') }}</button>
      </template>
    </aside>
  </section>
</template>
