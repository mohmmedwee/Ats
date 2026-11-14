<script setup lang="ts">
import type { Envelope, ProductDetail, Variant } from '~/types/api'

const route = useRoute()
const { request, country } = useApi()
const { locale, t } = useI18n()
const cart = useCartStore()
const localePath = useLocalePath()

const { data: product, error } = await useAsyncData(
  () => `product-${route.params.slug}-${country.value}`,
  () => request<Envelope<ProductDetail>>(`/products/${route.params.slug}`).then(r => r.data),
  { server: false, watch: [country] },
)

const selected = ref<string | null>(null)
const qty = ref(1)
const message = ref('')
const variant = computed<Variant | undefined>(() => product.value?.variants.find(v => v.id === selected.value))
watch(product, (p) => {
  selected.value = p?.variants.find(v => v.purchasable)?.id ?? p?.variants[0]?.id ?? null
}, { immediate: true })

const label = (v: Variant) => Object.values(v.attributes).join(' / ') || v.sku

async function addToCart() {
  if (!variant.value) return
  message.value = ''
  try {
    await cart.add(variant.value.id, qty.value)
    message.value = t('catalog.added')
  }
  catch (e) {
    message.value = apiErrorMessage(e, t('errors.generic'))
  }
}
</script>

<template>
  <div v-if="error" class="text-gray-500">{{ $t('catalog.empty') }}</div>
  <div v-else-if="product" class="grid gap-8 md:grid-cols-2">
    <div class="space-y-2">
      <img v-if="product.images[0]" :src="product.images[0].url" :alt="product.images[0].alt ?? product.title" class="w-full rounded">
      <div class="flex gap-2">
        <img v-for="img in product.images.slice(1, 5)" :key="img.url" :src="img.url" :alt="img.alt ?? ''" class="h-16 w-16 rounded object-cover">
      </div>
    </div>
    <div>
      <p v-if="product.brand" class="text-sm uppercase text-gray-500">{{ product.brand }}</p>
      <h1 class="text-2xl font-semibold" data-test="product-title">{{ product.title }}</h1>
      <p class="mt-2 text-xl font-bold" data-test="product-price">{{ formatMoney(variant?.price ?? product.price, locale) }}</p>
      <p class="text-xs text-gray-500">{{ $t('common.prices_include_vat') }}</p>
      <p v-if="product.delivery_estimate" class="mt-2 text-sm">{{ $t('catalog.delivery', { min: product.delivery_estimate.min_days, max: product.delivery_estimate.max_days }) }}</p>

      <label v-if="product.variants.length > 1" class="mt-4 block">{{ $t('catalog.choose_variant') }}
        <select v-model="selected" class="mt-1 w-full rounded border px-2 py-2" data-test="variant-select">
          <option v-for="v in product.variants" :key="v.id" :value="v.id" :disabled="!v.purchasable">
            {{ label(v) }}{{ v.purchasable ? '' : ` — ${$t('catalog.out_of_stock')}` }}
          </option>
        </select>
      </label>
      <p v-if="variant" class="mt-2 text-sm" :class="variant.availability === 'out_of_stock' ? 'text-red-600' : 'text-green-700'">{{ $t(`catalog.${variant.availability}`) }}</p>

      <div class="mt-4 flex items-center gap-2">
        <input v-model.number="qty" type="number" min="1" max="20" class="w-20 rounded border px-2 py-2" :aria-label="$t('common.quantity')">
        <button :disabled="!variant?.purchasable || cart.busy" class="flex-1 rounded bg-gray-900 py-2 text-white disabled:opacity-50" data-test="add-to-cart" @click="addToCart">
          {{ $t('catalog.add_to_cart') }}
        </button>
      </div>
      <p v-if="message" role="status" class="mt-2 text-sm" data-test="cart-message">{{ message }} <NuxtLink :to="localePath('/cart')" class="underline">{{ $t('nav.cart') }}</NuxtLink></p>
      <p v-if="product.description" class="mt-6 whitespace-pre-line text-gray-700">{{ product.description }}</p>
    </div>
  </div>
  <p v-else>{{ $t('common.loading') }}</p>
</template>
