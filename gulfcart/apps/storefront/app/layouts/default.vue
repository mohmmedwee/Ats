<script setup lang="ts">
const auth = useAuthStore()
const localePath = useLocalePath()
onMounted(() => { if (!auth.loaded) auth.fetchMe() })

async function onLogout() {
  await auth.logout()
  await navigateTo(localePath('/'))
}
</script>

<template>
  <div class="min-h-screen bg-white text-gray-900">
    <header class="border-b">
      <nav class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
        <NuxtLink :to="localePath('/')" class="text-xl font-bold">{{ $t('brand') }}</NuxtLink>
        <CountrySelect class="ms-auto" />
        <LanguageSwitcher />
        <template v-if="auth.user">
          <NuxtLink v-if="auth.isStaff" :to="localePath('/admin')">{{ $t('nav.admin') }}</NuxtLink>
          <NuxtLink :to="localePath('/account')">{{ $t('nav.account') }}</NuxtLink>
          <button type="button" @click="onLogout">{{ $t('nav.logout') }}</button>
        </template>
        <template v-else>
          <NuxtLink :to="localePath('/login')">{{ $t('nav.login') }}</NuxtLink>
          <NuxtLink :to="localePath('/register')" class="rounded bg-gray-900 px-3 py-1 text-white">{{ $t('nav.register') }}</NuxtLink>
        </template>
      </nav>
    </header>
    <main class="mx-auto max-w-6xl px-4 py-8">
      <slot />
    </main>
  </div>
</template>
