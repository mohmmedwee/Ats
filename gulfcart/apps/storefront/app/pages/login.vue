<script setup lang="ts">
const auth = useAuthStore()
const { t } = useI18n()
const localePath = useLocalePath()
const route = useRoute()
// Only same-site relative paths are accepted as redirect targets.
const redirect = computed(() => { const r = String(route.query.redirect ?? ''); return r.startsWith('/') && !r.startsWith('//') ? r : '/account' })
const mode = ref<'phone' | 'email'>(route.query.mode === 'email' ? 'email' : 'phone')
const form = reactive({ login: '', password: '' })
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    const result = await auth.login(form.login, form.password)
    if (result.mfa_required) return navigateTo(localePath('/mfa'))
    if (result.mfa_setup_required) return navigateTo(localePath('/mfa?setup=1'))
    await useCartStore().load().catch(() => undefined)
    return navigateTo(localePath(redirect.value))
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-md">
    <div class="card p-6 md:p-8">
      <h1 class="mb-1 text-2xl font-bold">{{ $t('phone_auth.title') }}</h1>
      <p class="mb-5 text-sm text-ink-500">{{ $t('phone_auth.subtitle') }}</p>
      <div class="mb-5 grid grid-cols-2 rounded-full bg-ink-100 p-1 text-sm font-semibold">
        <button type="button" class="rounded-full py-2" :class="mode === 'phone' ? 'bg-white shadow' : 'text-ink-500'" data-test="mode-phone" @click="mode = 'phone'">{{ $t('phone_auth.tab_phone') }}</button>
        <button type="button" class="rounded-full py-2" :class="mode === 'email' ? 'bg-white shadow' : 'text-ink-500'" data-test="mode-email" @click="mode = 'email'">{{ $t('phone_auth.tab_email') }}</button>
      </div>

      <PhoneSignIn v-if="mode === 'phone'" @done="navigateTo(localePath(redirect))" />

      <form v-else class="space-y-4" data-test="email-form" @submit.prevent="submit">
        <label class="block"><span class="label">{{ $t('auth.login') }}</span>
          <input v-model="form.login" required autocomplete="username" class="input" dir="ltr">
        </label>
        <label class="block"><span class="label">{{ $t('auth.password') }}</span>
          <input v-model="form.password" type="password" required autocomplete="current-password" class="input" dir="ltr">
        </label>
        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <button :disabled="busy" class="btn-dark w-full">{{ $t('auth.submit_login') }}</button>
        <div class="flex justify-between text-sm">
          <NuxtLink :to="localePath('/forgot-password')" class="link">{{ $t('auth.forgot') }}</NuxtLink>
          <NuxtLink :to="localePath('/register')" class="link">{{ $t('nav.register') }}</NuxtLink>
        </div>
      </form>
    </div>
  </div>
</template>
