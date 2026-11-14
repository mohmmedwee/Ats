<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const auth = useAuthStore()
</script>

<template>
  <section>
    <h1 class="text-2xl font-semibold">{{ $t('account.title') }}</h1>
    <p class="mt-2">{{ $t('account.welcome', { name: auth.user?.name ?? '' }) }}</p>
  </section>
</template>
