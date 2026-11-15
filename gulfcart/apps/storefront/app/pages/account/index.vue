<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
const auth = useAuthStore()
const { request, csrf } = useApi()
const { t } = useI18n()
const localePath = useLocalePath()
const phone = ref('')
const code = ref('')
const codeSent = ref(false)
const message = ref('')
const error = ref('')

async function call(fn: () => Promise<void>) {
  error.value = ''
  message.value = ''
  try {
    await csrf()
    await fn()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

const resend = () => call(async () => {
  await request('/auth/email/resend', { method: 'POST' })
  message.value = t('auth.resent')
})
const sendCode = () => call(async () => {
  await request('/auth/otp/send', { method: 'POST', body: { phone: phone.value } })
  codeSent.value = true
  message.value = t('auth.code_sent')
})
const verifyCode = () => call(async () => {
  await request('/auth/verify-otp', { method: 'POST', body: { phone: phone.value, code: code.value } })
  await auth.fetchMe()
  codeSent.value = false
  message.value = t('auth.phone_verified')
})
</script>

<template>
  <section class="max-w-lg space-y-4">
    <h1 class="text-2xl font-semibold">{{ $t('account.title') }}</h1>
    <p>{{ $t('account.welcome', { name: auth.user?.name ?? '' }) }}</p>
    <p v-if="message" role="status" class="text-green-700">{{ message }}</p>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>

    <div v-if="auth.user?.email && auth.user.email_verified === false" class="rounded bg-amber-50 p-3 text-sm">
      {{ $t('auth.verify_banner') }} <button class="underline" @click="resend">{{ $t('auth.resend') }}</button>
    </div>

    <form v-if="!auth.user?.phone_verified" class="space-y-2 rounded border p-3" @submit.prevent="codeSent ? verifyCode() : sendCode()">
      <h2 class="font-medium">{{ $t('auth.phone_title') }}</h2>
      <input v-model="phone" type="tel" dir="ltr" placeholder="+9715XXXXXXXX" required class="w-full rounded border px-2 py-1">
      <input v-if="codeSent" v-model="code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" required class="w-full rounded border px-2 py-1" :placeholder="$t('auth.mfa_code')">
      <button class="rounded bg-gray-900 px-4 py-1 text-white">{{ codeSent ? $t('auth.verify_code') : $t('auth.send_code') }}</button>
    </form>

    <NuxtLink :to="localePath('/account/orders')" class="underline">{{ $t('nav.orders') }}</NuxtLink>
    <NuxtLink :to="localePath('/account/wishlist')" class="ms-4 underline">{{ $t('wishlist.title') }}</NuxtLink>
    <NuxtLink :to="localePath('/account/rewards')" class="ms-4 underline" data-test="account-rewards">{{ $t('rewards.title') }}</NuxtLink>
  </section>
</template>
