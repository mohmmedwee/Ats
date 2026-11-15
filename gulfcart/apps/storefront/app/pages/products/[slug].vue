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
const shownPrice = computed(() => variant.value?.price ?? product.value?.price ?? null)
const regularPrice = computed(() => (variant.value ? variant.value.compare_at_price : product.value?.compare_at_price) ?? null)
const off = computed(() => salePercent(shownPrice.value, regularPrice.value))
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
  if (i >= 0) {
    imageIndex.value = i
    const el = swipe.value
    if (el) el.scrollTo({ left: el.clientWidth * i * (document.dir === 'rtl' ? -1 : 1), behavior: 'smooth' })
  }
}
/** Phone gallery: which photo is in view. */
const swipe = ref<HTMLElement | null>(null)
function onSwipe() {
  const el = swipe.value
  if (el) imageIndex.value = Math.round(Math.abs(el.scrollLeft) / el.clientWidth)
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
  <div v-if="error" class="py-20 text-center text-ink-500">{{ $t('catalog.empty') }}</div>
  <div v-else-if="product" class="space-y-10">
    <nav class="hidden items-center gap-1 text-xs text-ink-500 md:flex">
      <NuxtLink :to="localePath('/')" class="hover:text-ink-900">{{ $t('nav.home') }}</NuxtLink>
      <AppIcon name="chevron" :size="12" class="rtl:rotate-180" />
      <NuxtLink v-if="product.category" :to="localePath({ path: '/products', query: { category: product.category.slug } })" class="hover:text-ink-900">{{ product.category.name }}</NuxtLink>
    </nav>

    <div class="grid gap-5 md:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] md:gap-12">
      <!-- gallery: swipe on phones, thumbnails + large photo on desktop -->
      <div class="-mx-3 -mt-4 md:mx-0 md:mt-0">
        <div class="relative md:hidden">
          <div ref="swipe" class="no-scrollbar flex snap-x snap-mandatory overflow-x-auto" @scroll.passive="onSwipe">
            <div v-for="img in product.images" :key="img.url" class="aspect-[3/4] w-full shrink-0 snap-start bg-ink-100">
              <img :src="img.url" :alt="img.alt ?? product.title" class="h-full w-full object-cover">
            </div>
          </div>
          <span v-if="product.images.length > 1" class="absolute bottom-3 end-3 rounded-full bg-black/60 px-2.5 py-1 text-[11px] font-semibold text-white" dir="ltr">{{ imageIndex + 1 }} / {{ product.images.length }}</span>
        </div>
        <div class="hidden gap-3 md:flex">
          <div class="no-scrollbar flex max-h-[640px] w-[72px] shrink-0 flex-col gap-2 overflow-y-auto">
            <button v-for="(img, i) in product.images" :key="img.url" class="aspect-[3/4] w-full shrink-0 overflow-hidden rounded-md border-2 transition" :class="i === imageIndex ? 'border-ink-900' : 'border-transparent opacity-70 hover:opacity-100'" @mouseenter="imageIndex = i" @click="imageIndex = i">
              <img :src="img.url" :alt="img.alt ?? ''" class="h-full w-full object-cover">
            </button>
          </div>
          <div class="aspect-[3/4] flex-1 overflow-hidden rounded-md bg-ink-100">
            <img v-if="currentImage" :src="currentImage.url" :alt="currentImage.alt ?? product.title" class="h-full w-full object-cover">
          </div>
        </div>
      </div>

      <div class="space-y-5 md:sticky md:top-32 md:self-start">
        <div class="space-y-1.5">
          <NuxtLink v-if="product.brand_info" :to="localePath({ path: '/products', query: { brand: product.brand_info.slug } })" class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-ink-900 hover:underline" data-test="product-brand">
            <img v-if="product.brand_info.logo_url" :src="product.brand_info.logo_url" alt="" class="h-5 w-auto">{{ product.brand_info.name }}
            <span v-if="product.brand_info.authorized" class="rounded bg-green-50 px-1.5 py-0.5 text-[10px] font-semibold normal-case tracking-normal text-green-700">✓ {{ $t('brands.authorized_short') }}</span>
          </NuxtLink>
          <p v-else-if="product.brand" class="text-xs font-bold uppercase tracking-widest text-ink-900">{{ product.brand }}</p>
          <h1 class="text-lg leading-snug text-ink-700 md:text-xl" data-test="product-title">{{ product.title }}</h1>
        </div>

        <div class="space-y-1">
          <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
            <p class="text-[28px] font-extrabold leading-none" :class="off ? 'text-brand-600' : 'text-ink-900'" data-test="product-price">
              <span v-if="!variant?.price && priceRange" class="text-sm font-semibold text-ink-500">{{ $t('product.from') }} </span>{{ formatMoney(shownPrice, locale) }}
            </p>
            <template v-if="off && regularPrice">
              <s class="text-base text-ink-500" data-test="regular-price">{{ formatMoney(regularPrice, locale) }}</s>
              <span class="rounded bg-brand-600 px-1.5 py-0.5 text-xs font-black text-white" dir="ltr">−{{ off }}%</span>
            </template>
          </div>
          <div v-if="off && product.sale_ends_at" class="flex items-center gap-2 rounded-md bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700" data-test="sale-timer">
            <span>⚡ {{ $t('flash.ends_in') }}</span><SaleCountdown :ends="product.sale_ends_at" />
          </div>
          <p v-if="priceRange && variant?.price" class="text-xs text-ink-500">{{ $t('product.price_by_option', { min: formatMoney(priceRange.min, locale), max: formatMoney(priceRange.max, locale) }) }}</p>
          <p class="text-xs text-ink-500">{{ $t('product.price_note') }}</p>
        </div>

        <div v-if="hasOptions" class="border-t border-ink-100 pt-5" data-test="variant-options">
          <VariantPicker v-model="selected" :variants="product.variants" :images="product.images" @image="showImage">
            <template v-for="k in ['size', 'option']" :key="k" #[`after-${k}`]>
              <button v-if="product.size_guide" type="button" class="text-xs font-semibold underline underline-offset-4" data-test="size-guide" @click="sizeGuideOpen = true">{{ $t('product.size_guide') }}</button>
            </template>
          </VariantPicker>
        </div>
        <p v-if="variant" class="flex items-center gap-1.5 text-sm font-medium" :class="variant.availability === 'out_of_stock' ? 'text-red-600' : variant.availability === 'low_stock' ? 'text-gold-600' : 'text-green-700'">
          <span class="h-2 w-2 rounded-full bg-current" />{{ $t(`catalog.${variant.availability}`) }}
        </p>

        <div class="hidden items-stretch gap-2 md:flex">
          <div class="flex items-center rounded-md border border-ink-300">
            <button class="h-12 w-10 text-lg" :aria-label="'-1'" @click="qty = Math.max(1, qty - 1)">−</button>
            <span class="w-8 text-center font-semibold" :aria-label="$t('common.quantity')">{{ qty }}</span>
            <button class="h-12 w-10 text-lg" :aria-label="'+1'" @click="qty = Math.min(20, qty + 1)">+</button>
          </div>
          <button :disabled="!variant?.purchasable || cart.busy" class="btn-primary flex-1 text-base font-bold uppercase tracking-wide" data-test="add-to-cart" @click="addToCart">
            {{ $t('catalog.add_to_cart') }}
          </button>
          <span class="flex w-12 items-center justify-center rounded-md border border-ink-300"><WishlistButton :product-id="product.id" /></span>
        </div>
        <p v-if="message" role="status" class="flex items-center gap-2 rounded-md bg-green-50 px-3 py-2 text-sm text-green-800" data-test="cart-message">
          <AppIcon name="check" :size="16" /> {{ message }}
          <NuxtLink :to="localePath('/cart')" class="ms-auto font-bold underline">{{ $t('cart.title') }}</NuxtLink>
        </p>

        <ul class="divide-y divide-ink-100 border-y border-ink-100 text-sm">
          <li v-if="product.delivery_estimate" class="flex items-start gap-3 py-3">
            <AppIcon name="truck" class="mt-0.5 shrink-0" />
            <div><p class="font-semibold">{{ $t('catalog.delivery', { min: product.delivery_estimate.min_days, max: product.delivery_estimate.max_days }) }}</p><p class="text-xs text-ink-500">{{ $t('product.ships_from') }}</p></div>
          </li>
          <li class="flex items-start gap-3 py-3">
            <AppIcon name="receipt" class="mt-0.5 shrink-0" />
            <div><p class="font-semibold">{{ $t('product.all_in') }}</p><p class="text-xs text-ink-500">{{ $t('promo.all_in_price') }}</p></div>
          </li>
          <li v-if="countryInfo" class="flex items-start gap-3 py-3">
            <AppIcon name="return" class="mt-0.5 shrink-0" />
            <div><p class="font-semibold">{{ $t('product.returns', { days: countryInfo.return_window_days }) }}</p><p class="text-xs text-ink-500">{{ $t('product.returns_note') }}</p></div>
          </li>
          <li v-if="product.warranty" class="flex items-start gap-3 py-3" data-test="warranty">
            <AppIcon name="shield" class="mt-0.5 shrink-0" />
            <div><p class="font-semibold">{{ $t('product.warranty') }}</p><p class="text-xs text-ink-500">{{ product.warranty }}</p></div>
          </li>
          <li class="flex items-center gap-3 py-3">
            <AppIcon name="shield" class="shrink-0" /><p class="font-semibold">{{ $t('footer.secure') }}</p>
          </li>
        </ul>

        <!-- details as accordions -->
        <div class="divide-y divide-ink-100 border-b border-ink-100">
          <details v-if="product.description" open class="group py-1">
            <summary class="flex cursor-pointer list-none items-center justify-between py-3 text-sm font-bold uppercase tracking-wide">
              {{ $t('product.description') }}<AppIcon name="plus" :size="16" class="transition group-open:rotate-45" />
            </summary>
            <p class="whitespace-pre-line pb-4 text-sm leading-7 text-ink-700">{{ product.description }}</p>
          </details>
          <details v-if="specRows.length" class="group py-1" data-test="specifications" :open="!product.description">
            <summary class="flex cursor-pointer list-none items-center justify-between py-3 text-sm font-bold uppercase tracking-wide">
              {{ $t('product.specifications') }}<AppIcon name="plus" :size="16" class="transition group-open:rotate-45" />
            </summary>
            <table class="mb-4 w-full text-sm">
              <tbody>
                <tr v-for="(r, i) in specRows" :key="i" class="border-t border-ink-100 first:border-t-0">
                  <th class="w-2/5 py-2 pe-3 text-start font-medium text-ink-500">{{ r[0] }}</th>
                  <td class="py-2">{{ r[1] }}</td>
                </tr>
              </tbody>
            </table>
          </details>
        </div>
      </div>
    </div>

    <ProductReviews :product-id="product.id" />

    <AdminModal :open="sizeGuideOpen" :title="$t('product.size_guide')" @close="sizeGuideOpen = false">
      <RichText v-if="product.size_guide" :text="product.size_guide" />
    </AdminModal>

    <section v-if="related?.length" data-test="related">
      <h2 class="section-title mb-3">{{ $t('product.related') }}</h2>
      <div class="no-scrollbar -mx-3 flex snap-x gap-2 overflow-x-auto px-3 pb-2 md:mx-0 md:grid md:grid-cols-4 md:gap-3 md:overflow-visible md:px-0 lg:grid-cols-6">
        <div v-for="p in related" :key="p.id" class="w-[140px] shrink-0 snap-start md:w-auto">
          <ProductCardItem :product="p" />
        </div>
      </div>
    </section>

    <section v-if="recentOthers.length" data-test="recently-viewed">
      <h2 class="section-title mb-3">{{ $t('product.recently_viewed') }}</h2>
      <div class="no-scrollbar -mx-3 flex gap-2 overflow-x-auto px-3 pb-2 md:mx-0 md:gap-3 md:px-0">
        <div v-for="p in recentOthers" :key="p.id" class="w-32 shrink-0 md:w-40">
          <ProductCardItem :product="p" />
        </div>
      </div>
    </section>

    <!-- sticky mobile purchase bar -->
    <div class="fixed inset-x-0 bottom-14 z-20 flex items-center gap-2 border-t border-ink-100 bg-white px-3 py-2.5 md:hidden">
      <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md border border-ink-300"><WishlistButton :product-id="product.id" /></span>
      <button :disabled="!variant?.purchasable || cart.busy" class="btn-primary h-11 flex-1 font-bold" data-test="add-to-cart-mobile" @click="addToCart">
        {{ $t('catalog.add_to_cart') }} · <span dir="auto">{{ formatMoney(shownPrice, locale) }}</span>
      </button>
    </div>
  </div>
  <div v-else class="grid animate-pulse gap-6 md:grid-cols-2"><div class="aspect-[3/4] rounded-md bg-ink-100" /><div class="space-y-3"><div class="h-8 rounded bg-ink-100" /><div class="h-20 rounded bg-ink-100" /></div></div>
</template>
