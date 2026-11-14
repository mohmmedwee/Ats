<script setup lang="ts">
const route = useRoute()
const { request, csrf } = useApi()
const { t } = useI18n()
const localePath = useLocalePath()
const password = ref('')
const confirmation = ref('')
const done = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  try {
    await csrf()
    await request('/auth/reset-password', { method: 'POST', body: {
      token: String(route.query.token ?? ''), email: String(route.query.email ?? ''),
      password: password.value, password_confirmation: confirmation.value,
    } })
    done.value = true
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}
</script>

<template>
  <form class="mx-auto max-w-sm space-y-4" @submit.prevent="submit">
    <h1 class="text-2xl font-semibold">{{ $t('auth.forgot_title') }}</h1>
    <template v-if="done">
      <p role="status" class="text-green-700">{{ $t('auth.reset_done') }}</p>
      <NuxtLink :to="localePath('/login')" class="underline">{{ $t('nav.login') }}</NuxtLink>
    </template>
    <template v-else>
      <label class="block">{{ $t('auth.new_password') }}<input v-model="password" type="password" minlength="10" required autocomplete="new-password" class="mt-1 w-full rounded border px-3 py-2"></label>
      <label class="block">{{ $t('auth.password_confirmation') }}<input v-model="confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded border px-3 py-2"></label>
      <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
      <button class="w-full rounded bg-gray-900 py-2 text-white">{{ $t('auth.reset_submit') }}</button>
    </template>
  </form>
</template>
