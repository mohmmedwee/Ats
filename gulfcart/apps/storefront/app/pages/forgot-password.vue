<script setup lang="ts">
const { request, csrf } = useApi()
const { t } = useI18n()
const email = ref('')
const done = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  try {
    await csrf()
    await request('/auth/forgot-password', { method: 'POST', body: { email: email.value } })
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
    <p v-if="done" role="status" class="text-green-700">{{ $t('auth.forgot_sent') }}</p>
    <template v-else>
      <label class="block">{{ $t('auth.email') }}<input v-model="email" type="email" required class="mt-1 w-full rounded border px-3 py-2"></label>
      <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
      <button class="w-full rounded bg-gray-900 py-2 text-white">{{ $t('auth.send_link') }}</button>
    </template>
  </form>
</template>
