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
  async () => product.value ? (await request<Envelope<ProductCard[]>>(`/products/${product.value.id}/related`, { query: { limit: 12 } })).data : [],
  { server: false, watch: [product] },
)
const recent = useRecentlyViewed()
const recentOthers = computed(() => recent.items.value.filter(p => p.id !== product.value?.id))

const selected = ref<string | null>(null)
const qty = ref(1)
const message = ref('')
const imageIndex = ref(0)
const sizeGuideOpen = ref(false)
/** "Label: value" lines → table rows. */
const specRows = computed(() => (product.value?.specifications ?? '').split('\n').map(l => l.trim()).filter(Boolean)
  .map(l => (l.includes(':') ? [l.slice(0, l.indexOf(':')).trim(), l.slice(l.indexOf(':') + 1).trim()] : ['', l])))
const tracking = useTracking()
const config = useRuntimeConfig()
onMounted(() => {
  if (product.value) tracking.track('view_item', { id: product.value.id, name: product.value.title, value: Number(product.value.price?.amount ?? 0), currency: product.value.price?.currency })
})
// Search engines and social previews: title, description, image and structured product data.
useSeoMeta({
  title: () => (product.value ? `${product.value.title} · GulfCart` : 'GulfCart'),
  description: () => product.value?.description?.slice(0, 160) ?? undefined,
  ogTitle: () => product.value?.title,
  ogDescription: () => product.value?.description?.slice(0, 160) ?? undefined,
  ogImage: () => product.value?.image ?? undefined,
  ogType: 'website',
  twitterCard: 'summary_large_image',
})
useHead(() => ({
  script: product.value?.price
    ? [{
        type: 'application/ld+json',
        innerHTML: JSON.stringify({
          '@context': 'https://schema.org',
          '@type': 'Product',
          'name': product.value.title,
          'image': product.value.images.map(i => i.url),
          'description': product.value.description ?? undefined,
          'brand': product.value.brand ? { '@type': 'Brand', 'name': product.value.brand } : undefined,
          'sku': product.value.variants[0]?.sku,
          'offers': {
            '@type': 'Offer',
            'price': product.value.price.amount,
            'priceCurrency': product.value.price.currency,
            'availability': product.value.availability === 'out_of_stock' ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
            'url': `${config.public.siteUrl}/products/${product.value.slug}`,
          },
        }),
      }]
    : [],
}))
const currentImage = computed(() => product.value?.images[imageIndex.value])
const variant = computed<Variant | undefined>(() => product.value?.variants.find(v => v.id === selected.value))
watch(product, (p) => {
  selected.value = p?.variants.find(v => v.purchasable)?.id ?? p?.variants[0]?.id ?? null
  imageIndex.value = 0
}, { immediate: true })

const hasOptions = computed(() => (product.value?.variants.length ?? 0) > 1 || Object.keys(product.value?.variants[0]?.attributes ?? {}).length > 0)
const priceRange = computed(() => {
  const prices = (product.value?.variants ?? []).map(v => v.price).filter((p): p is NonNullable<typeof p> => !!p)
  if (prices.length < 2) return null
  const sorted = [...prices].sort((a, b) => Number(a.amount) - Number(b.amount))
  return sorted[0]!.amount === sorted[sorted.length - 1]!.amount ? null : { min: sorted[0]!, max: sorted[sorted.length - 1]! }
})
function showImage(url: string) {
  const i = product.value?.images.findIndex(img => img.url === url) ?? -1
  if (i >= 0) imageIndex.value = i
}
watch(product, (p) => {
  if (p) recent.add({ id: p.id, slug: p.slug, title: p.title, brand: p.brand, category: p.category, price: p.price, image: p.image, delivery_estimate: p.delivery_estimate })
})

async function addToCart() {
  if (!variant.value) return
  message.value = ''
  try {
    await cart.add(variant.value.id, qty.value)
    message.value = t('catalog.added')
    tracking.track('add_to_cart', { id: product.value?.id, name: product.value?.title, value: Number(variant.value.price?.amount ?? 0) * qty.value, currency: variant.value.price?.currency, quantity: qty.value })
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

    <div class="grid gap-6 md:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] md:gap-10">
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
          <NuxtLink v-if="product.brand_info" :to="localePath({ path: '/products', query: { brand: product.brand_info.slug } })" class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-ink-500 hover:text-brand-600" data-test="product-brand">
            <img v-if="product.brand_info.logo_url" :src="product.brand_info.logo_url" alt="" class="h-5 w-auto">{{ product.brand_info.name }}
            <span v-if="product.brand_info.authorized" class="rounded-full bg-green-50 px-2 py-0.5 text-[10px] normal-case tracking-normal text-green-700">✓ {{ $t('brands.authorized_short') }}</span>
          </NuxtLink>
          <p v-else-if="product.brand" class="text-xs font-semibold uppercase tracking-wide text-ink-500">{{ product.brand }}</p>
          <div class="flex items-start justify-between gap-3">
            <h1 class="mt-1 text-xl font-semibold leading-snug md:text-2xl" data-test="product-title">{{ product.title }}</h1>
            <WishlistButton :product-id="product.id" />
          </div>
        </div>
        <div class="rounded-2xl bg-brand-50 p-4">
          <p class="text-3xl font-bold text-brand-600" data-test="product-price">
            <span v-if="!variant?.price && priceRange" class="text-base font-semibold">{{ $t('product.from') }} </span>{{ formatMoney(variant?.price ?? product.price, locale) }}
          </p>
          <p v-if="priceRange && variant?.price" class="text-xs text-ink-500">{{ $t('product.price_by_option', { min: formatMoney(priceRange.min, locale), max: formatMoney(priceRange.max, locale) }) }}</p>
          <p class="mt-1 text-xs text-ink-700">{{ $t('product.price_note') }}</p>
        </div>

        <div v-if="hasOptions" data-test="variant-options">
          <VariantPicker v-model="selected" :variants="product.variants" :images="product.images" @image="showImage">
            <template v-for="k in ['size', 'option']" :key="k" #[`after-${k}`]>
              <button v-if="product.size_guide" type="button" class="link text-xs" data-test="size-guide" @click="sizeGuideOpen = true">{{ $t('product.size_guide') }}</button>
            </template>
          </VariantPicker>
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
          <li v-if="product.warranty" class="flex items-start gap-3 p-3" data-test="warranty">
            <AppIcon name="shield" class="mt-0.5 shrink-0 text-brand-600" />
            <div><p class="font-semibold">{{ $t('product.warranty') }}</p><p class="text-xs text-ink-500">{{ product.warranty }}</p></div>
          </li>
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

        <div v-if="specRows.length" class="card overflow-hidden" data-test="specifications">
          <p class="p-4 pb-2 text-sm font-bold">{{ $t('product.specifications') }}</p>
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="(r, i) in specRows" :key="i" class="border-t border-ink-100">
                <th class="w-2/5 bg-ink-100/50 px-4 py-2 text-start font-medium text-ink-700">{{ r[0] }}</th>
                <td class="px-4 py-2">{{ r[1] }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="product.description" class="card p-4">
          <p class="mb-2 text-sm font-bold">{{ $t('product.description') }}</p>
          <p class="whitespace-pre-line text-sm leading-6 text-ink-700">{{ product.description }}</p>
        </div>
      </div>
    </div>

    <ProductReviews :product-id="product.id" />

    <AdminModal :open="sizeGuideOpen" :title="$t('product.size_guide')" @close="sizeGuideOpen = false">
      <RichText v-if="product.size_guide" :text="product.size_guide" />
    </AdminModal>

    <section v-if="related?.length" data-test="related">
      <h2 class="section-title mb-3">{{ $t('product.related') }}</h2>
      <div class="no-scrollbar -mx-3 flex snap-x gap-3 overflow-x-auto px-3 pb-2 md:mx-0 md:grid md:grid-cols-4 md:overflow-visible md:px-0 lg:grid-cols-6">
        <div v-for="p in related" :key="p.id" class="w-40 shrink-0 snap-start md:w-auto">
          <ProductCardItem :product="p" />
        </div>
      </div>
    </section>

    <section v-if="recentOthers.length" data-test="recently-viewed">
      <h2 class="section-title mb-3">{{ $t('product.recently_viewed') }}</h2>
      <div class="no-scrollbar -mx-3 flex gap-3 overflow-x-auto px-3 pb-2 md:mx-0 md:px-0">
        <div v-for="p in recentOthers" :key="p.id" class="w-36 shrink-0">
          <ProductCardItem :product="p" />
        </div>
      </div>
    </section>

    <!-- sticky mobile purchase bar -->
    <div class="fixed inset-x-0 bottom-14 z-20 flex items-center gap-3 border-t bg-white p-3 md:hidden">
      <p class="text-lg font-bold text-brand-600">{{ formatMoney(variant?.price ?? product.price, locale) }}</p>
      <button :disabled="!variant?.purchasable || cart.busy" class="btn-primary flex-1 py-3" data-test="add-to-cart-mobile" @click="addToCart">{{ $t('catalog.add_to_cart') }}</button>
    </div>
  </div>
  <div v-else class="grid animate-pulse gap-6 md:grid-cols-2"><div class="aspect-[3/4] rounded-2xl bg-white" /><div class="space-y-3"><div class="h-8 rounded bg-white" /><div class="h-20 rounded bg-white" /></div></div>
</template>
