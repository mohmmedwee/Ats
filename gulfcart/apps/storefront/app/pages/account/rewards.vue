<script setup lang="ts">
import type { Envelope, Money } from '~/types/api'

definePageMeta({ middleware: 'auth' })
interface Rewards {
  enabled: boolean
  balance: number
  balance_value: Money
  points_per_unit: number
  point_value: string
  min_redeem: number
  referral: { code: string, referrer_points: number, friend_percent: number, friends_joined: number, can_enter_code: boolean }
  codes: { code: string, source: 'loyalty' | 'referral', type: string, value: string, max_discount: string | null, ends_at: string | null }[]
  history: { type: string, points: number, note: string | null, created_at: string }[]
}
const { request, csrf, country } = useApi()
const { t, locale } = useI18n()
const route = useRoute()
const config = useRuntimeConfig()
const data = ref<Rewards | null>(null)
const busy = ref(false)
const message = ref('')
const error = ref('')
const friendCode = ref('')
const copied = ref('')
const currency = computed(() => (country.value === 'SA' ? 'SAR' : 'AED'))

async function load() {
  data.value = (await request<Envelope<Rewards>>('/loyalty')).data
}
onMounted(async () => {
  await load()
  if (route.query.welcome) message.value = t('rewards.welcome_ready')
  friendCode.value = useReferral().pending() ?? ''
})
watch(country, load)

/** Redeem options: steps of min_redeem up to the balance (max 4 buttons). */
const steps = computed(() => {
  const d = data.value
  if (!d || d.balance < d.min_redeem) return []
  const max = Math.floor(d.balance / d.min_redeem) * d.min_redeem
  return [...new Set([d.min_redeem, d.min_redeem * 5, d.min_redeem * 10, max].filter(p => p <= max))].sort((a, b) => a - b)
})
const worth = (points: number) => formatMoney({ amount: (points * Number(data.value?.point_value ?? 0)).toFixed(2), currency: currency.value }, locale.value)

async function redeem(points: number) {
  busy.value = true
  error.value = ''
  message.value = ''
  try {
    await csrf()
    const res = await request<Envelope<{ code: string, value: Money }>>('/loyalty/redeem', { method: 'POST', body: { points } })
    message.value = t('rewards.redeemed', { code: res.data.code, amount: formatMoney(res.data.value, locale.value) })
    await load()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
  finally {
    busy.value = false
  }
}

async function enterCode() {
  error.value = ''
  message.value = ''
  try {
    await csrf()
    const res = await request<Envelope<{ code: string }>>('/loyalty/referral', { method: 'POST', body: { code: friendCode.value } })
    useReferral().clear()
    message.value = t('rewards.welcome_code', { code: res.data.code })
    await load()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

const inviteLink = computed(() => `${config.public.siteUrl || (import.meta.client ? window.location.origin : '')}/${locale.value === 'ar' ? 'ar/' : ''}?ref=${data.value?.referral.code ?? ''}`)
const shareText = computed(() => t('rewards.share_text', { percent: data.value?.referral.friend_percent ?? 10, link: inviteLink.value }))
async function copy(text: string, key: string) {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = key
    setTimeout(() => (copied.value = ''), 2000)
  }
  catch { /* clipboard blocked */ }
}
async function share() {
  if (navigator.share) {
    await navigator.share({ text: shareText.value }).catch(() => undefined)
  }
  else {
    await copy(shareText.value, 'link')
  }
}
const label = (type: string) => t(`rewards.types.${type}`)
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-5">
    <h1 class="text-lg font-extrabold uppercase tracking-wide md:text-xl">{{ $t('rewards.title') }}</h1>
    <p v-if="message" role="status" class="rounded-md bg-green-50 p-3 text-sm text-green-800" data-test="rewards-message">{{ message }}</p>
    <p v-if="error" role="alert" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

    <template v-if="data">
      <!-- balance -->
      <div class="rounded-md bg-ink-900 p-5 text-white md:p-6" data-test="rewards-balance">
        <p class="text-xs font-semibold uppercase tracking-widest opacity-70">{{ $t('rewards.balance') }}</p>
        <p class="mt-1 text-4xl font-black tabular-nums">{{ data.balance.toLocaleString(locale) }} <span class="text-base font-semibold opacity-80">{{ $t('rewards.points') }}</span></p>
        <p class="mt-1 text-sm opacity-80">{{ $t('rewards.worth', { amount: formatMoney(data.balance_value, locale) }) }}</p>
        <p class="mt-4 text-xs opacity-70">{{ $t('rewards.how', { n: data.points_per_unit, currency, min: data.min_redeem, amount: worth(data.min_redeem) }) }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <button v-for="p in steps" :key="p" :disabled="busy" class="rounded-md bg-white px-3.5 py-2 text-sm font-bold text-ink-900 transition hover:bg-ink-100 disabled:opacity-50" :data-test="`redeem-${p}`" @click="redeem(p)">
            {{ $t('rewards.redeem_btn', { points: p.toLocaleString(locale), amount: worth(p) }) }}
          </button>
          <p v-if="!steps.length" class="text-sm opacity-80">{{ $t('rewards.need_more', { n: data.min_redeem - data.balance }) }}</p>
        </div>
      </div>

      <!-- personal codes ready to use -->
      <div v-if="data.codes.length" class="space-y-2" data-test="rewards-codes">
        <h2 class="text-sm font-extrabold uppercase tracking-wide">{{ $t('rewards.your_codes') }}</h2>
        <div v-for="c in data.codes" :key="c.code" class="flex items-center gap-3 rounded-md border border-dashed border-ink-300 p-3">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600"><AppIcon name="tag" :size="18" /></span>
          <div class="min-w-0 flex-1">
            <p class="font-mono text-base font-bold" dir="ltr">{{ c.code }}</p>
            <p class="text-xs text-ink-500">
              {{ c.type === 'percent' ? $t('rewards.percent_off', { n: Number(c.value) }) : $t('rewards.amount_off', { amount: formatMoney({ amount: c.value, currency }, locale) }) }}
              <span v-if="c.ends_at"> · {{ $t('rewards.until', { date: new Date(c.ends_at).toLocaleDateString(locale) }) }}</span>
            </p>
          </div>
          <button class="btn-outline btn-sm" @click="copy(c.code, c.code)">{{ copied === c.code ? '✓' : $t('rewards.copy') }}</button>
        </div>
        <p class="text-xs text-ink-500">{{ $t('rewards.codes_hint') }}</p>
      </div>

      <!-- invite friends -->
      <div class="rounded-md bg-brand-50 p-5" data-test="rewards-invite">
        <h2 class="text-base font-extrabold">{{ $t('rewards.invite_title') }}</h2>
        <p class="mt-1 text-sm text-ink-700">{{ $t('rewards.invite_text', { percent: data.referral.friend_percent, points: data.referral.referrer_points }) }}</p>
        <div class="mt-3 flex items-center gap-2 rounded-md bg-white p-2">
          <span class="flex-1 truncate px-2 font-mono text-sm" dir="ltr">{{ inviteLink }}</span>
          <button class="btn-outline btn-sm" @click="copy(inviteLink, 'link')">{{ copied === 'link' ? '✓' : $t('rewards.copy') }}</button>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <a :href="`https://wa.me/?text=${encodeURIComponent(shareText)}`" target="_blank" rel="noopener" class="btn bg-[#25D366] text-white hover:opacity-90">WhatsApp</a>
          <button class="btn-primary" @click="share">{{ $t('rewards.share') }}</button>
          <span class="text-xs text-ink-500">{{ $t('rewards.your_code') }} <b class="font-mono" dir="ltr">{{ data.referral.code }}</b> · {{ $t('rewards.friends_joined', { n: data.referral.friends_joined }) }}</span>
        </div>
      </div>

      <form v-if="data.referral.can_enter_code" class="flex flex-wrap items-end gap-2 rounded-md border border-ink-100 p-4" @submit.prevent="enterCode">
        <label class="min-w-48 flex-1"><span class="label">{{ $t('rewards.have_code') }}</span><input v-model="friendCode" class="input uppercase" dir="ltr" maxlength="16" required data-test="friend-code"></label>
        <button class="btn-primary">{{ $t('coupon.apply') }}</button>
      </form>

      <!-- history -->
      <div v-if="data.history.length">
        <h2 class="mb-2 text-sm font-extrabold uppercase tracking-wide">{{ $t('rewards.history') }}</h2>
        <ul class="divide-y divide-ink-100 border-y border-ink-100 text-sm">
          <li v-for="(h, i) in data.history" :key="i" class="flex items-center justify-between gap-3 py-2.5">
            <span>{{ label(h.type) }} <span v-if="h.note && h.type !== 'referral_bonus'" class="text-xs text-ink-500" dir="auto">· {{ h.note }}</span><span class="block text-xs text-ink-500">{{ new Date(h.created_at).toLocaleDateString(locale) }}</span></span>
            <b class="tabular-nums" :class="h.points > 0 ? 'text-green-700' : 'text-ink-500'" dir="ltr">{{ h.points > 0 ? '+' : '' }}{{ h.points }}</b>
          </li>
        </ul>
      </div>
    </template>
    <div v-else class="h-40 animate-pulse rounded-md bg-ink-100" />
  </section>
</template>
