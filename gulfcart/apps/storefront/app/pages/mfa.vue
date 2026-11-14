<script setup lang="ts">
interface Envelope<T> { data: T }

const auth = useAuthStore()
const route = useRoute()
const { t } = useI18n()
const localePath = useLocalePath()
const { request, csrf } = useApi()
const code = ref('')
const error = ref('')
const setup = computed(() => route.query.setup === '1')
const secret = ref<{ secret: string, otpauth_url: string } | null>(null)
const recovery = ref<string[]>([])

async function start() {
  error.value = ''
  try {
    await csrf()
    secret.value = (await request<Envelope<{ secret: string, otpauth_url: string }>>('/auth/mfa/setup', { method: 'POST' })).data
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

async function submit() {
  error.value = ''
  try {
    if (setup.value) {
      recovery.value = (await request<Envelope<{ recovery_codes: string[] }>>('/auth/mfa/confirm', { method: 'POST', body: { code: code.value } })).data.recovery_codes
      auth.mfaPending = false
      await auth.fetchMe()
      return
    }
    await auth.mfaChallenge(code.value)
    await navigateTo(localePath('/admin'))
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}
</script>

<template>
  <div class="mx-auto max-w-md space-y-4">
    <h1 class="text-2xl font-semibold">{{ setup ? $t('mfa.setup_title') : $t('auth.mfa_title') }}</h1>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>

    <template v-if="recovery.length">
      <h2 class="font-medium">{{ $t('mfa.recovery_title') }}</h2>
      <p class="text-sm">{{ $t('mfa.recovery_hint') }}</p>
      <ul class="grid grid-cols-2 gap-1 rounded bg-gray-50 p-3 font-mono text-sm" dir="ltr">
        <li v-for="c in recovery" :key="c">{{ c }}</li>
      </ul>
      <NuxtLink :to="localePath('/admin')" class="inline-block rounded bg-gray-900 px-4 py-2 text-white">{{ $t('nav.admin') }}</NuxtLink>
    </template>

    <template v-else-if="setup">
      <p class="rounded bg-amber-50 p-3 text-sm">{{ $t('auth.mfa_setup_required') }}</p>
      <button v-if="!secret" class="rounded bg-gray-900 px-4 py-2 text-white" @click="start">{{ $t('mfa.start') }}</button>
      <template v-else>
        <p class="text-sm">{{ $t('mfa.setup_step1') }}</p>
        <code class="block break-all rounded bg-gray-50 p-2" dir="ltr" data-test="mfa-secret">{{ secret.secret }}</code>
        <a :href="secret.otpauth_url" class="text-sm underline" dir="ltr">{{ secret.otpauth_url.slice(0, 40) }}…</a>
        <p class="text-sm">{{ $t('mfa.setup_step2') }}</p>
      </template>
    </template>

    <form v-if="!recovery.length && (!setup || secret)" class="space-y-4" @submit.prevent="submit">
      <label class="block">{{ $t('auth.mfa_code') }}
        <input v-model="code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required class="mt-1 w-full rounded border px-3 py-2">
      </label>
      <button class="w-full rounded bg-gray-900 py-2 text-white">{{ $t('auth.mfa_submit') }}</button>
    </form>
  </div>
</template>
