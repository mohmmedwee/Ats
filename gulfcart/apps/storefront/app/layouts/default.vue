<script setup lang="ts">
const auth = useAuthStore()
const cart = useCartStore()
const localePath = useLocalePath()
const route = useRoute()
const router = useRouter()
const q = ref(String(route.query.q ?? ''))
watch(() => route.query.q, v => (q.value = String(v ?? '')))

const { site, load: loadSite } = useSite()
const { locale } = useI18n()
watch(locale, () => loadSite())

onMounted(() => {
  useReferral().capture(route.query.ref)
  loadSite()
  if (!auth.loaded) auth.fetchMe()
  cart.load().catch(() => undefined)
})

function search() {
  router.push(localePath({ path: '/products', query: q.value ? { q: q.value } : {} }))
}

async function onLogout() {
  await auth.logout()
  await navigateTo(localePath('/'))
}

const isAdmin = computed(() => route.path.includes('/admin'))
const { country } = useApi()
const currency = computed(() => (country.value === 'SA' ? 'SAR' : 'AED'))
</script>

<template>
  <div class="min-h-screen pb-16 md:pb-0">
    <!-- promo strip: only real promises (VAT-inclusive prices, duties shown, free-delivery threshold) -->
    <div class="bg-ink-900 text-center text-xs text-white">
      <div class="no-scrollbar mx-auto flex max-w-7xl justify-center gap-6 overflow-x-auto px-4 py-2">
        <span class="inline-flex items-center gap-1 whitespace-nowrap"><AppIcon name="truck" :size="14" /> {{ $t('promo.free_delivery', { amount: `200 ${currency}` }) }}</span>
        <span class="hidden items-center gap-1 whitespace-nowrap sm:inline-flex"><AppIcon name="receipt" :size="14" /> {{ $t('promo.all_in_price') }}</span>
        <span class="hidden items-center gap-1 whitespace-nowrap md:inline-flex"><AppIcon name="return" :size="14" /> {{ $t('promo.returns') }}</span>
      </div>
    </div>

    <header class="sticky top-0 z-30 border-b border-ink-100 bg-white">
      <div class="mx-auto flex max-w-7xl items-center gap-3 px-3 py-2.5 md:gap-8 md:px-4 md:py-3">
        <NuxtLink :to="localePath('/')" class="shrink-0" :aria-label="$t('brand')"><BrandLogo /></NuxtLink>
        <form class="relative flex-1 md:max-w-2xl md:mx-auto" role="search" @submit.prevent="search">
          <AppIcon name="search" :size="17" class="pointer-events-none absolute start-3.5 top-1/2 -translate-y-1/2 text-ink-500" />
          <input v-model="q" type="search" :placeholder="$t('catalog.search_placeholder')" class="w-full rounded-full border border-transparent bg-ink-100 py-2.5 pe-4 ps-10 text-sm outline-none transition focus:border-ink-900 focus:bg-white" data-test="search">
          <button class="sr-only">{{ $t('common.search') }}</button>
        </form>
        <div class="hidden items-center gap-5 md:flex">
          <div class="flex flex-col items-end gap-0.5 text-xs">
            <CountrySelect />
            <LanguageSwitcher />
          </div>
          <NuxtLink :to="localePath(auth.user ? (auth.isStaff ? '/admin' : '/account') : '/login')" class="text-ink-900 hover:opacity-70" :title="auth.user ? (auth.isStaff ? $t('nav.admin') : $t('nav.account')) : $t('nav.login')">
            <AppIcon name="user" :size="23" />
          </NuxtLink>
          <NuxtLink :to="localePath('/account/orders')" class="text-ink-900 hover:opacity-70" :title="$t('nav.orders')"><AppIcon name="receipt" :size="23" /></NuxtLink>
          <NuxtLink :to="localePath('/account/wishlist')" class="text-ink-900 hover:opacity-70" :title="$t('wishlist.title')"><AppIcon name="heart" :size="23" /></NuxtLink>
          <NuxtLink :to="localePath('/cart')" class="relative text-ink-900 hover:opacity-70" :title="$t('nav.cart')" data-test="nav-cart">
            <AppIcon name="bag" :size="23" />
            <span v-if="cart.count" class="absolute -end-2 -top-1.5 min-w-[18px] rounded-full bg-brand-600 px-1 text-center text-[10px] font-bold leading-[18px] text-white">{{ cart.count }}</span>
          </NuxtLink>
          <button v-if="auth.user" type="button" class="text-ink-500 hover:text-ink-900" :title="$t('nav.logout')" :aria-label="$t('nav.logout')" data-test="nav-logout" @click="onLogout"><AppIcon name="logout" :size="20" /></button>
        </div>
        <NuxtLink :to="localePath('/account/wishlist')" class="text-ink-900 md:hidden" :aria-label="$t('wishlist.title')"><AppIcon name="heart" :size="22" /></NuxtLink>
      </div>
      <CategoryBar v-if="!isAdmin" />
    </header>

    <main class="mx-auto max-w-7xl px-3 py-4 md:px-4 md:py-6">
      <slot />
    </main>

    <footer class="mt-14 border-t border-ink-100 bg-ink-100/50">
      <div class="mx-auto grid max-w-7xl grid-cols-2 gap-8 px-4 py-10 text-sm text-ink-700 md:grid-cols-4">
        <div class="col-span-2 space-y-2 md:col-span-1"><BrandLogo /><p class="text-ink-500">{{ $t('home.subtitle') }}</p></div>
        <div class="space-y-2">
          <p class="font-semibold text-ink-900">{{ $t('footer.shop') }}</p>
          <NuxtLink :to="localePath('/products')" class="block hover:text-brand-600">{{ $t('home.all') }}</NuxtLink>
          <NuxtLink :to="localePath('/categories')" class="block hover:text-brand-600">{{ $t('categories.title') }}</NuxtLink>
          <NuxtLink :to="localePath('/brands')" class="block hover:text-brand-600">{{ $t('brands.title') }}</NuxtLink>
          <NuxtLink :to="localePath('/products?sort=price_asc')" class="block hover:text-brand-600">{{ $t('home.best_value') }}</NuxtLink>
          <NuxtLink :to="localePath('/account/orders')" class="block hover:text-brand-600">{{ $t('nav.orders') }}</NuxtLink>
          <NuxtLink :to="localePath('/track')" class="block hover:text-brand-600" data-test="footer-track">{{ $t('track.title') }}</NuxtLink>
          <NuxtLink :to="localePath('/account/wishlist')" class="block hover:text-brand-600">{{ $t('wishlist.title') }}</NuxtLink>
          <NuxtLink :to="localePath('/account/rewards')" class="block hover:text-brand-600">{{ $t('rewards.title') }}</NuxtLink>
        </div>
        <div class="space-y-2" data-test="footer-pages">
          <p class="font-semibold text-ink-900">{{ $t('footer.help') }}</p>
          <NuxtLink v-for="p in site?.pages ?? []" :key="p.slug" :to="localePath(`/info/${p.slug}`)" class="block hover:text-brand-600">{{ p.title }}</NuxtLink>
        </div>
        <div class="col-span-2 space-y-2 md:col-span-1">
          <p class="font-semibold text-ink-900">{{ $t('footer.pay_safely') }}</p>
          <p class="flex items-center gap-2"><AppIcon name="shield" :size="18" /> {{ $t('footer.secure') }}</p>
          <p v-if="site?.support.email" class="text-ink-500" dir="ltr">{{ site.support.email }}</p>
          <CountrySelect />
          <LanguageSwitcher />
        </div>
      </div>
      <p class="border-t py-4 text-center text-xs text-ink-500">© {{ new Date().getFullYear() }} GulfCart</p>
    </footer>
    <WhatsAppButton />
    <ConsentBanner />

    <!-- mobile app-style bottom navigation -->
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-ink-100 bg-white text-[11px] text-ink-500 md:hidden">
      <NuxtLink :to="localePath('/')" class="flex flex-col items-center gap-0.5 py-2" exact-active-class="text-ink-900 font-bold"><AppIcon name="home" /><span>{{ $t('nav.home') }}</span></NuxtLink>
      <NuxtLink :to="localePath('/categories')" class="flex flex-col items-center gap-0.5 py-2" active-class="text-ink-900 font-bold"><AppIcon name="grid" /><span>{{ $t('nav.categories') }}</span></NuxtLink>
      <NuxtLink :to="localePath('/cart')" class="relative flex flex-col items-center gap-0.5 py-2" active-class="text-ink-900 font-bold">
        <AppIcon name="bag" /><span>{{ $t('nav.cart') }}</span>
        <span v-if="cart.count" class="absolute end-1/4 top-1 min-w-4 rounded-full bg-brand-600 px-1 text-center text-[10px] font-bold leading-4 text-white">{{ cart.count }}</span>
      </NuxtLink>
      <NuxtLink :to="localePath(auth.user ? (auth.isStaff ? '/admin' : '/account') : '/login')" class="flex flex-col items-center gap-0.5 py-2" active-class="text-ink-900 font-bold"><AppIcon name="user" /><span>{{ $t('nav.me') }}</span></NuxtLink>
    </nav>
  </div>
</template>
