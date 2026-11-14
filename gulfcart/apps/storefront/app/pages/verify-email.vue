<script setup lang="ts">
const route = useRoute()
const ok = ref<boolean | null>(null)

onMounted(async () => {
  // The link is a relative, signed API path (/api/v1/auth/email/verify/...); only accept that shape.
  const link = String(route.query.link ?? '')
  if (!/^\/api\/v1\/auth\/email\/verify\/[\w-]+\/[a-f0-9]{40}\?/.test(link)) {
    ok.value = false
    return
  }
  try {
    await $fetch(link, { credentials: 'include', headers: { Accept: 'application/json' } })
    ok.value = true
  }
  catch {
    ok.value = false
  }
})
</script>

<template>
  <section class="mx-auto max-w-md space-y-3 text-center">
    <h1 class="text-2xl font-semibold">{{ $t('auth.verify_title') }}</h1>
    <p v-if="ok === null">{{ $t('common.loading') }}</p>
    <p v-else-if="ok" class="text-green-700" data-test="verify-ok">{{ $t('auth.verify_ok') }}</p>
    <p v-else class="text-red-600">{{ $t('auth.verify_failed') }}</p>
  </section>
</template>
