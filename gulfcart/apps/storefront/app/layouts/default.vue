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

    <header class="sticky top-0 z-30 bg-white shadow-sm">
      <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 md:gap-6">
        <NuxtLink :to="localePath('/')" class="shrink-0" :aria-label="$t('brand')"><BrandLogo /></NuxtLink>
        <form class="relative flex-1" role="search" @submit.prevent="search">
          <input v-model="q" type="search" :placeholder="$t('catalog.search_placeholder')" class="w-full rounded-full border-2 border-ink-900 bg-white py-2 pe-12 ps-4 text-sm outline-none" data-test="search">
          <button class="absolute end-1 top-1/2 flex h-8 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-ink-900 text-white" :aria-label="$t('common.search')">
            <AppIcon name="search" :size="18" />
          </button>
        </form>
        <div class="hidden items-center gap-4 md:flex">
          <div class="flex flex-col items-end gap-0.5">
            <CountrySelect />
            <LanguageSwitcher />
          </div>
          <NuxtLink v-if="auth.user" :to="localePath(auth.isStaff ? '/admin' : '/account')" class="flex flex-col items-center text-[11px] text-ink-700 hover:text-ink-900">
            <AppIcon name="user" /><span>{{ auth.isStaff ? $t('nav.admin') : $t('nav.account') }}</span>
          </NuxtLink>
          <NuxtLink v-else :to="localePath('/login')" class="flex flex-col items-center text-[11px] text-ink-700 hover:text-ink-900">
            <AppIcon name="user" /><span>{{ $t('nav.login') }}</span>
          </NuxtLink>
          <NuxtLink :to="localePath('/account/orders')" class="flex flex-col items-center text-[11px] text-ink-700 hover:text-ink-900">
            <AppIcon name="receipt" /><span>{{ $t('nav.orders') }}</span>
          </NuxtLink>
          <NuxtLink :to="localePath('/cart')" class="relative flex flex-col items-center text-[11px] text-ink-700 hover:text-ink-900" data-test="nav-cart">
            <AppIcon name="cart" /><span>{{ $t('nav.cart') }}</span>
            <span v-if="cart.count" class="absolute -top-1 end-0 min-w-5 rounded-full bg-brand-600 px-1 text-center text-[10px] font-bold leading-5 text-white">{{ cart.count }}</span>
          </NuxtLink>
          <button v-if="auth.user" type="button" class="text-[11px] text-ink-500 hover:text-ink-900" @click="onLogout">{{ $t('nav.logout') }}</button>
        </div>
      </div>
      <CategoryBar v-if="!isAdmin" />
    </header>

    <main class="mx-auto max-w-7xl px-3 py-4 md:px-4 md:py-6">
      <slot />
    </main>

    <footer class="mt-10 bg-white">
      <div class="mx-auto grid max-w-7xl grid-cols-2 gap-8 px-4 py-10 text-sm text-ink-700 md:grid-cols-4">
        <div class="col-span-2 space-y-2 md:col-span-1"><BrandLogo /><p class="text-ink-500">{{ $t('home.subtitle') }}</p></div>
        <div class="space-y-2">
          <p class="font-semibold text-ink-900">{{ $t('footer.shop') }}</p>
          <NuxtLink :to="localePath('/products')" class="block hover:text-brand-600">{{ $t('home.all') }}</NuxtLink>
          <NuxtLink :to="localePath('/products?sort=price_asc')" class="block hover:text-brand-600">{{ $t('home.best_value') }}</NuxtLink>
          <NuxtLink :to="localePath('/account/orders')" class="block hover:text-brand-600">{{ $t('nav.orders') }}</NuxtLink>
          <NuxtLink :to="localePath('/account/wishlist')" class="block hover:text-brand-600">{{ $t('wishlist.title') }}</NuxtLink>
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
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t bg-white text-[11px] md:hidden">
      <NuxtLink :to="localePath('/')" class="flex flex-col items-center gap-0.5 py-2" exact-active-class="text-brand-600"><AppIcon name="home" /><span>{{ $t('nav.home') }}</span></NuxtLink>
      <NuxtLink :to="localePath('/products')" class="flex flex-col items-center gap-0.5 py-2" active-class="text-brand-600"><AppIcon name="grid" /><span>{{ $t('nav.categories') }}</span></NuxtLink>
      <NuxtLink :to="localePath('/cart')" class="relative flex flex-col items-center gap-0.5 py-2" active-class="text-brand-600">
        <AppIcon name="cart" /><span>{{ $t('nav.cart') }}</span>
        <span v-if="cart.count" class="absolute end-1/4 top-1 min-w-4 rounded-full bg-brand-600 px-1 text-center text-[10px] font-bold leading-4 text-white">{{ cart.count }}</span>
      </NuxtLink>
      <NuxtLink :to="localePath(auth.user ? (auth.isStaff ? '/admin' : '/account') : '/login')" class="flex flex-col items-center gap-0.5 py-2" active-class="text-brand-600"><AppIcon name="user" /><span>{{ $t('nav.me') }}</span></NuxtLink>
    </nav>
  </div>
</template>
