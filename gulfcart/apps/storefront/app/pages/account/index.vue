<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const auth = useAuthStore()
const localePath = useLocalePath()
</script>

<template>
  <section class="space-y-3">
    <h1 class="text-2xl font-semibold">{{ $t('account.title') }}</h1>
    <p>{{ $t('account.welcome', { name: auth.user?.name ?? '' }) }}</p>
    <NuxtLink :to="localePath('/account/orders')" class="underline">{{ $t('nav.orders') }}</NuxtLink>
  </section>
</template>
