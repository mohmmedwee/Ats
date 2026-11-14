<script setup lang="ts">
const auth = useAuthStore()
const { t } = useI18n()
const localePath = useLocalePath()
const route = useRoute()
// Only same-site relative paths are accepted as redirect targets.
const redirect = computed(() => { const r = String(route.query.redirect ?? ''); return r.startsWith('/') && !r.startsWith('//') ? r : '/account' })
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
  <form class="mx-auto max-w-sm space-y-4" @submit.prevent="submit">
    <h1 class="text-2xl font-semibold">{{ $t('nav.login') }}</h1>
    <label class="block">{{ $t('auth.login') }}
      <input v-model="form.login" required autocomplete="username" class="mt-1 w-full rounded border px-3 py-2">
    </label>
    <label class="block">{{ $t('auth.password') }}
      <input v-model="form.password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded border px-3 py-2">
    </label>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <button :disabled="busy" class="w-full rounded bg-gray-900 py-2 text-white">{{ $t('auth.submit_login') }}</button>
    <NuxtLink :to="localePath('/forgot-password')" class="block text-sm underline">{{ $t('auth.forgot') }}</NuxtLink>
    <p class="text-sm">{{ $t('auth.no_account') }} <NuxtLink :to="localePath('/register')" class="underline">{{ $t('nav.register') }}</NuxtLink></p>
  </form>
</template>
