<script setup lang="ts">
const auth = useAuthStore()
const { t } = useI18n()
const localePath = useLocalePath()
const route = useRoute()
// Only same-site relative paths are accepted as redirect targets.
const redirect = computed(() => { const r = String(route.query.redirect ?? ''); return r.startsWith('/') && !r.startsWith('//') ? r : '/account' })
const form = reactive({ name: '', email: '', phone: '', password: '', password_confirmation: '' })
const error = ref('')
const busy = ref(false)

async function submit() {
  error.value = ''
  busy.value = true
  try {
    await auth.register({ ...form, phone: form.phone || undefined })
    await useCartStore().load().catch(() => undefined)
    const welcome = await useReferral().applyPending()
    await navigateTo(localePath(welcome ? '/account/rewards?welcome=1' : redirect.value))
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
    <h1 class="text-2xl font-semibold">{{ $t('nav.register') }}</h1>
    <label class="block">{{ $t('auth.name') }}
      <input v-model="form.name" required autocomplete="name" class="mt-1 w-full rounded border px-3 py-2">
    </label>
    <label class="block">{{ $t('auth.email') }}
      <input v-model="form.email" type="email" required autocomplete="email" class="mt-1 w-full rounded border px-3 py-2">
    </label>
    <label class="block">{{ $t('auth.phone') }}
      <input v-model="form.phone" type="tel" dir="ltr" autocomplete="tel" placeholder="+9715XXXXXXXX" class="mt-1 w-full rounded border px-3 py-2">
    </label>
    <label class="block">{{ $t('auth.password') }}
      <input v-model="form.password" type="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full rounded border px-3 py-2">
    </label>
    <label class="block">{{ $t('auth.password_confirmation') }}
      <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded border px-3 py-2">
    </label>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <button :disabled="busy" class="w-full rounded bg-gray-900 py-2 text-white">{{ $t('auth.submit_register') }}</button>
    <p class="text-sm">{{ $t('auth.have_account') }} <NuxtLink :to="localePath('/login')" class="underline">{{ $t('nav.login') }}</NuxtLink></p>
  </form>
</template>
