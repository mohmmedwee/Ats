<script setup lang="ts">
import type { Envelope, ProductCard, ProductDetail, Variant } from '~/types/api'

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
const { data: countryInfo } = await useAsyncData(() => `country-${country.value}`, () => request<Envelope<{ return_window_days: number }>>('/countries/current').then(r => r.data), { server: false, watch: [country] })
const { data: related } = await useAsyncData(
  () => `related-${route.params.slug}-${country.value}`,
  async () => product.value?.category ? (await request<Envelope<ProductCard[]>>('/products', { query: { category: product.value.category.slug, limit: 11 } })).data.filter(p => p.slug !== route.params.slug).slice(0, 10) : [],
  { server: false, watch: [product] },
)

const selected = ref<string | null>(null)
const qty = ref(1)
const message = ref('')
const imageIndex = ref(0)
const currentImage = computed(() => product.value?.images[imageIndex.value])
const variant = computed<Variant | undefined>(() => product.value?.variants.find(v => v.id === selected.value))
watch(product, (p) => {
  selected.value = p?.variants.find(v => v.purchasable)?.id ?? p?.variants[0]?.id ?? null
  imageIndex.value = 0
}, { immediate: true })

const optionName = computed(() => Object.keys(product.value?.variants[0]?.attributes ?? {})[0] ?? 'option')
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
  <div v-if="error" class="card p-10 text-center text-ink-500">{{ $t('catalog.empty') }}</div>
  <div v-else-if="product" class="space-y-8">
    <nav class="flex items-center gap-1 text-xs text-ink-500">
      <NuxtLink :to="localePath('/')">{{ $t('nav.home') }}</NuxtLink>
      <AppIcon name="chevron" :size="12" class="rtl:rotate-180" />
      <NuxtLink v-if="product.category" :to="localePath({ path: '/products', query: { category: product.category.slug } })">{{ product.category.name }}</NuxtLink>
    </nav>

    <div class="grid gap-6 md:grid-cols-[1.1fr_1fr] md:gap-10">
      <div class="flex flex-col-reverse gap-3 md:flex-row">
        <div class="no-scrollbar flex gap-2 overflow-x-auto md:flex-col">
          <button v-for="(img, i) in product.images" :key="img.url" class="h-20 w-16 shrink-0 overflow-hidden rounded-lg border-2" :class="i === imageIndex ? 'border-ink-900' : 'border-transparent'" @click="imageIndex = i">
            <img :src="img.url" :alt="img.alt ?? ''" class="h-full w-full object-cover">
          </button>
        </div>
        <div class="aspect-[3/4] flex-1 overflow-hidden rounded-2xl bg-white">
          <img v-if="currentImage" :src="currentImage.url" :alt="currentImage.alt ?? product.title" class="h-full w-full object-cover">
        </div>
      </div>

      <div class="space-y-5">
        <div>
          <p v-if="product.brand" class="text-xs font-semibold uppercase tracking-wide text-ink-500">{{ product.brand }}</p>
          <h1 class="mt-1 text-xl font-semibold leading-snug md:text-2xl" data-test="product-title">{{ product.title }}</h1>
        </div>
        <div class="rounded-2xl bg-brand-50 p-4">
          <p class="text-3xl font-bold text-brand-600" data-test="product-price">{{ formatMoney(variant?.price ?? product.price, locale) }}</p>
          <p class="mt-1 text-xs text-ink-700">{{ $t('product.price_note') }}</p>
        </div>

        <div v-if="product.variants.length > 1 || optionName === 'size'">
          <p class="mb-2 text-sm font-semibold">{{ $t(`product.option.${optionName}`, optionName) }}: <span class="font-normal text-ink-700">{{ variant ? label(variant) : '' }}</span></p>
          <div class="flex flex-wrap gap-2" data-test="variant-options">
            <button v-for="v in product.variants" :key="v.id" type="button" class="chip" :class="{ 'chip-active': v.id === selected, 'cursor-not-allowed text-ink-300 line-through': !v.purchasable }" :disabled="!v.purchasable" @click="selected = v.id">
              {{ label(v) }}
            </button>
          </div>
        </div>
        <p v-if="variant" class="text-sm font-medium" :class="variant.availability === 'out_of_stock' ? 'text-red-600' : variant.availability === 'low_stock' ? 'text-gold-600' : 'text-green-700'">
          {{ $t(`catalog.${variant.availability}`) }}
        </p>

        <div class="hidden items-center gap-3 md:flex">
          <div class="flex items-center rounded-full border border-ink-300">
            <button class="h-10 w-10 text-lg" :aria-label="'-1'" @click="qty = Math.max(1, qty - 1)">−</button>
            <span class="w-8 text-center" :aria-label="$t('common.quantity')">{{ qty }}</span>
            <button class="h-10 w-10 text-lg" :aria-label="'+1'" @click="qty = Math.min(20, qty + 1)">+</button>
          </div>
          <button :disabled="!variant?.purchasable || cart.busy" class="btn-primary flex-1 py-3 text-base" data-test="add-to-cart" @click="addToCart">
            <AppIcon name="cart" :size="18" /> {{ $t('catalog.add_to_cart') }}
          </button>
        </div>
        <p v-if="message" role="status" class="flex items-center gap-2 text-sm text-green-700" data-test="cart-message">
          <AppIcon name="check" :size="16" /> {{ message }}
          <NuxtLink :to="localePath('/cart')" class="font-semibold underline">{{ $t('cart.title') }}</NuxtLink>
        </p>

        <ul class="card divide-y text-sm">
          <li v-if="product.delivery_estimate" class="flex items-start gap-3 p-3">
            <AppIcon name="truck" class="mt-0.5 shrink-0 text-brand-600" />
            <div><p class="font-semibold">{{ $t('catalog.delivery', { min: product.delivery_estimate.min_days, max: product.delivery_estimate.max_days }) }}</p><p class="text-xs text-ink-500">{{ $t('product.ships_from') }}</p></div>
          </li>
          <li class="flex items-start gap-3 p-3">
            <AppIcon name="receipt" class="mt-0.5 shrink-0 text-brand-600" />
            <div><p class="font-semibold">{{ $t('product.all_in') }}</p><p class="text-xs text-ink-500">{{ $t('promo.all_in_price') }}</p></div>
          </li>
          <li v-if="countryInfo" class="flex items-start gap-3 p-3">
            <AppIcon name="return" class="mt-0.5 shrink-0 text-brand-600" />
            <div><p class="font-semibold">{{ $t('product.returns', { days: countryInfo.return_window_days }) }}</p><p class="text-xs text-ink-500">{{ $t('product.returns_note') }}</p></div>
          </li>
          <li class="flex items-start gap-3 p-3">
            <AppIcon name="shield" class="mt-0.5 shrink-0 text-brand-600" />
            <div><p class="font-semibold">{{ $t('footer.secure') }}</p></div>
          </li>
        </ul>

        <div v-if="product.description" class="card p-4">
          <p class="mb-2 text-sm font-bold">{{ $t('product.description') }}</p>
          <p class="whitespace-pre-line text-sm leading-6 text-ink-700">{{ product.description }}</p>
        </div>
      </div>
    </div>

    <section v-if="related?.length">
      <h2 class="section-title mb-3">{{ $t('product.related') }}</h2>
      <ProductGrid :products="related" />
    </section>

    <!-- sticky mobile purchase bar -->
    <div class="fixed inset-x-0 bottom-14 z-20 flex items-center gap-3 border-t bg-white p-3 md:hidden">
      <p class="text-lg font-bold text-brand-600">{{ formatMoney(variant?.price ?? product.price, locale) }}</p>
      <button :disabled="!variant?.purchasable || cart.busy" class="btn-primary flex-1 py-3" data-test="add-to-cart-mobile" @click="addToCart">{{ $t('catalog.add_to_cart') }}</button>
    </div>
  </div>
  <div v-else class="grid animate-pulse gap-6 md:grid-cols-2"><div class="aspect-[3/4] rounded-2xl bg-white" /><div class="space-y-3"><div class="h-8 rounded bg-white" /><div class="h-20 rounded bg-white" /></div></div>
</template>
