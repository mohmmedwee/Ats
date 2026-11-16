<script setup lang="ts">
/** Footer newsletter sign-up: explicit consent, shows the welcome code when one is set up. */
const { request } = useApi()
const { t } = useI18n()
const email = ref('')
const done = ref(false)
const code = ref<string | null>(null)
const error = ref('')
async function submit() {
  error.value = ''
  try {
    const res = await request<{ data: { welcome_code: string | null } }>('/newsletter', { method: 'POST', body: { email: email.value, source: 'footer' } })
    code.value = res.data.welcome_code
    done.value = true
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}
</script>

<template>
  <div data-test="newsletter">
    <p class="font-semibold text-ink-900">{{ $t('newsletter.title') }}</p>
    <p class="mb-2 text-xs text-ink-500">{{ $t('newsletter.text') }}</p>
    <p v-if="done" class="rounded-md bg-green-50 p-2 text-xs text-green-800">
      {{ $t('newsletter.thanks') }}<template v-if="code"> {{ $t('newsletter.code') }} <b class="font-mono" dir="ltr">{{ code }}</b></template>
    </p>
    <form v-else class="flex gap-2" @submit.prevent="submit">
      <input v-model="email" type="email" required class="input flex-1 bg-white" :placeholder="$t('newsletter.placeholder')" dir="ltr" autocomplete="email">
      <button class="btn-primary btn-sm">{{ $t('newsletter.join') }}</button>
    </form>
    <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
  </div>
</template>
