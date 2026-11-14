<script setup lang="ts">
import type { IconName } from '~/components/AppIcon.vue'

interface Item { to: string, label: string, icon: IconName, perm?: string[] }
const auth = useAuthStore()
const localePath = useLocalePath()
const route = useRoute()
const open = ref(false)
watch(() => route.fullPath, () => (open.value = false))

const all: { title: string, items: Item[] }[] = [
  { title: 'admin.nav.overview', items: [{ to: '/admin', label: 'admin.dashboard', icon: 'chart', perm: ['orders.view_any'] }] },
  { title: 'admin.nav.sales', items: [
    { to: '/admin/orders', label: 'admin.orders', icon: 'bag', perm: ['orders.view_any'] },
    { to: '/admin/procurement', label: 'admin.procurement', icon: 'truck', perm: ['procurement.view'] },
    { to: '/admin/refunds', label: 'admin.refunds', icon: 'coins', perm: ['refunds.request', 'refunds.approve'] },
    { to: '/admin/returns', label: 'admin.returns', icon: 'return', perm: ['orders.view_any'] },
  ] },
  { title: 'admin.nav.catalog', items: [
    { to: '/admin/products', label: 'admin.products', icon: 'box', perm: ['catalog.manage'] },
    { to: '/admin/cj', label: 'admin.cj.title', icon: 'download', perm: ['catalog.manage'] },
    { to: '/admin/imports', label: 'admin.imports', icon: 'file', perm: ['catalog.manage'] },
    { to: '/admin/categories', label: 'admin.categories.title', icon: 'layers', perm: ['catalog.manage'] },
    { to: '/admin/suppliers', label: 'admin.suppliers', icon: 'store', perm: ['suppliers.manage'] },
    { to: '/admin/reviews', label: 'admin.reviews.title', icon: 'heart', perm: ['catalog.manage'] },
    { to: '/admin/content', label: 'admin.content.title', icon: 'file', perm: ['catalog.manage'] },
  ] },
  { title: 'admin.nav.settings', items: [
    { to: '/admin/pricing', label: 'admin.pricing.title', icon: 'percent', perm: ['pricing.manage'] },
    { to: '/admin/coupons', label: 'admin.coupons.title', icon: 'tag', perm: ['pricing.manage'] },
    { to: '/admin/shipping', label: 'admin.shipping.title', icon: 'truck', perm: ['countries.manage'] },
    { to: '/admin/tax', label: 'admin.tax.title', icon: 'receipt', perm: ['countries.manage'] },
    { to: '/admin/countries', label: 'admin.countries.title', icon: 'globe', perm: ['countries.manage'] },
  ] },
  { title: 'admin.nav.team', items: [
    { to: '/admin/users', label: 'admin.users_page.title', icon: 'users', perm: ['users.manage'] },
    { to: '/admin/audit', label: 'admin.audit.title', icon: 'list', perm: ['audit.view'] },
  ] },
]
const groups = computed(() => all.map(g => ({ ...g, items: g.items.filter(i => !i.perm || i.perm.some(p => auth.can(p))) })).filter(g => g.items.length))

function active(to: string) {
  const path = route.path.replace(/^\/(ar|en)(?=\/|$)/, '') || '/'
  return to === '/admin' ? path === '/admin' : path.startsWith(to)
}

async function onLogout() {
  await auth.logout()
  await navigateTo(localePath('/login'))
}
</script>

<template>
  <div class="min-h-screen bg-ink-100">
    <!-- mobile top bar -->
    <header class="sticky top-0 z-30 flex items-center justify-between bg-ink-900 px-4 py-3 text-white lg:hidden">
      <button :aria-label="$t('admin.nav.menu')" @click="open = !open"><AppIcon :name="open ? 'x' : 'menu'" /></button>
      <span class="text-sm font-semibold">{{ $t('admin.panel') }}</span>
      <LanguageSwitcher />
    </header>

    <div class="flex">
      <aside
        class="fixed inset-y-0 start-0 z-40 w-64 shrink-0 overflow-y-auto bg-ink-900 text-ink-300 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
        :class="open ? 'translate-x-0' : 'max-lg:ltr:-translate-x-full max-lg:rtl:translate-x-full'"
      >
        <div class="flex items-center justify-between px-5 py-5">
          <NuxtLink :to="localePath('/admin')" class="rounded bg-white px-2 py-1"><BrandLogo /></NuxtLink>
          <button class="text-white lg:hidden" :aria-label="$t('admin.nav.close')" @click="open = false"><AppIcon name="x" /></button>
        </div>
        <ClientOnly>
        <nav class="space-y-5 px-3 pb-6" data-test="admin-nav">
          <div v-for="g in groups" :key="g.title">
            <p class="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-ink-500">{{ $t(g.title) }}</p>
            <NuxtLink
              v-for="i in g.items" :key="i.to" :to="localePath(i.to)"
              class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition hover:bg-white/10 hover:text-white"
              :class="active(i.to) ? 'bg-white/10 font-semibold text-white' : ''"
            >
              <AppIcon :name="i.icon" :size="18" /> {{ $t(i.label) }}
            </NuxtLink>
          </div>
        </nav>
        </ClientOnly>
        <div class="border-t border-white/10 px-5 py-4 text-xs">
          <p class="truncate text-white">{{ auth.user?.name }}</p>
          <p class="truncate text-ink-500" dir="ltr">{{ auth.user?.email }}</p>
          <div class="mt-3 flex items-center gap-4">
            <NuxtLink :to="localePath('/')" class="inline-flex items-center gap-1 hover:text-white"><AppIcon name="store" :size="14" /> {{ $t('admin.nav.storefront') }}</NuxtLink>
            <button class="inline-flex items-center gap-1 hover:text-white" @click="onLogout"><AppIcon name="logout" :size="14" /> {{ $t('nav.logout') }}</button>
          </div>
          <div class="mt-3 hidden lg:block [&_a]:text-ink-300"><LanguageSwitcher /></div>
        </div>
      </aside>
      <div v-if="open" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="open = false" />

      <main class="min-w-0 flex-1 px-4 py-6 lg:px-8">
        <slot />
      </main>
    </div>
  </div>
</template>
