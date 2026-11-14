<script setup lang="ts">
const auth = useAuthStore()
const route = useRoute()
const { t } = useI18n()
const localePath = useLocalePath()
const code = ref('')
const error = ref('')
const setup = computed(() => route.query.setup === '1')

async function submit() {
  error.value = ''
  try {
    await auth.mfaChallenge(code.value)
    await navigateTo(localePath('/admin'))
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}
</script>

<template>
  <div class="mx-auto max-w-sm space-y-4">
    <h1 class="text-2xl font-semibold">{{ $t('auth.mfa_title') }}</h1>
    <p v-if="setup" class="rounded bg-amber-50 p-3 text-sm">{{ $t('auth.mfa_setup_required') }}</p>
    <form v-else class="space-y-4" @submit.prevent="submit">
      <label class="block">{{ $t('auth.mfa_code') }}
        <input v-model="code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required class="mt-1 w-full rounded border px-3 py-2">
      </label>
      <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
      <button class="w-full rounded bg-gray-900 py-2 text-white">{{ $t('auth.mfa_submit') }}</button>
    </form>
  </div>
</template>
