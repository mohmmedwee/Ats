<script setup lang="ts">
/** Mobile number + SMS code sign-in. Creates the account on first use, then asks for a name. */
const emit = defineEmits<{ done: [] }>()
const auth = useAuthStore()
const { t } = useI18n()
const { country: siteCountry } = useApi()
const step = ref<'phone' | 'code' | 'name'>('phone')
const form = reactive({ country: siteCountry.value as string, phone: '', code: '', name: '', email: '' })
const error = ref('')
const busy = ref(false)
const resendIn = ref(0)
let timer: ReturnType<typeof setInterval> | undefined

function countdown() {
  resendIn.value = 45
  clearInterval(timer)
  timer = setInterval(() => (resendIn.value = Math.max(0, resendIn.value - 1)), 1000)
}
onBeforeUnmount(() => clearInterval(timer))

async function guard(fn: () => Promise<void>) {
  error.value = ''
  busy.value = true
  try {
    await fn()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
  finally {
    busy.value = false
  }
}
const send = () => guard(async () => {
  await auth.phoneStart(form.country, form.phone)
  step.value = 'code'
  countdown()
})
const verify = () => guard(async () => {
  const res = await auth.phoneVerify(form.country, form.phone, form.code)
  await useCartStore().load().catch(() => undefined)
  if (res.profile_incomplete) step.value = 'name'
  else emit('done')
})
const saveName = () => guard(async () => {
  await auth.updateProfile({ name: form.name, ...(form.email ? { email: form.email } : {}) })
  emit('done')
})
</script>

<template>
  <div>
    <form v-if="step === 'phone'" class="space-y-4" data-test="phone-form" @submit.prevent="send">
      <div>
        <span class="label">{{ $t('phone_auth.mobile') }}</span>
        <div class="flex gap-2" dir="ltr">
          <select v-model="form.country" class="input w-28" :aria-label="$t('country.label')">
            <option value="AE">🇦🇪 +971</option>
            <option value="SA">🇸🇦 +966</option>
          </select>
          <input v-model="form.phone" class="input flex-1" inputmode="tel" autocomplete="tel-national" required :placeholder="form.country === 'SA' ? '05X XXX XXXX' : '05X XXX XXXX'" data-test="phone-input">
        </div>
      </div>
      <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
      <button :disabled="busy" class="btn-primary w-full" data-test="phone-send">{{ $t('phone_auth.send_code') }}</button>
      <p class="text-center text-xs text-ink-500">{{ $t('phone_auth.no_password') }}</p>
    </form>

    <form v-else-if="step === 'code'" class="space-y-4" @submit.prevent="verify">
      <p class="text-sm text-ink-700">{{ $t('phone_auth.code_sent') }} <b dir="ltr">{{ form.country === 'SA' ? '+966' : '+971' }} {{ form.phone }}</b></p>
      <input v-model="form.code" class="input text-center text-2xl tracking-[0.5em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required dir="ltr" data-test="code-input">
      <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
      <button :disabled="busy" class="btn-primary w-full" data-test="code-verify">{{ $t('phone_auth.verify') }}</button>
      <div class="flex justify-between text-sm">
        <button type="button" class="link" @click="step = 'phone'; form.code = ''">{{ $t('phone_auth.change_number') }}</button>
        <button type="button" class="link disabled:text-ink-300 disabled:no-underline" :disabled="resendIn > 0 || busy" @click="send">{{ resendIn ? $t('phone_auth.resend_in', { s: resendIn }) : $t('phone_auth.resend') }}</button>
      </div>
    </form>

    <form v-else class="space-y-4" data-test="name-form" @submit.prevent="saveName">
      <p class="text-sm text-ink-700">{{ $t('phone_auth.welcome') }}</p>
      <label class="block"><span class="label">{{ $t('auth.name') }}</span><input v-model="form.name" class="input" required autocomplete="name" data-test="name-input"></label>
      <label class="block"><span class="label">{{ $t('phone_auth.email_optional') }}</span><input v-model="form.email" type="email" class="input" autocomplete="email" dir="ltr"></label>
      <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
      <button :disabled="busy" class="btn-primary w-full" data-test="name-save">{{ $t('phone_auth.continue') }}</button>
    </form>
  </div>
</template>
