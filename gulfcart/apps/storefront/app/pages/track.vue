<script setup lang="ts">
import type { Envelope, PackageProgress, Tracking } from '~/types/api'

/** Track an order without signing in: order number + phone on the delivery address. */
interface Result {
  order_number: string
  status: string
  placed_at: string
  item_count: number
  packages: (PackageProgress & { number: number, status: string, tracking: Pick<Tracking, 'carrier' | 'tracking_number' | 'tracking_url' | 'status' | 'events'>[], items: { title: string, image: string | null, quantity: number, attributes: Record<string, string> }[] })[]
}
const route = useRoute()
const { request } = useApi()
const { t } = useI18n()
const form = reactive({ order_number: String(route.query.order ?? ''), phone: '' })
const result = ref<Result | null>(null)
const error = ref('')
const busy = ref(false)
useSeoMeta({ title: () => `${t('track.title')} · GulfCart` })

async function submit() {
  busy.value = true
  error.value = ''
  result.value = null
  try {
    result.value = (await request<Envelope<Result>>('/orders/track', { method: 'POST', body: form })).data
  }
  catch (e) {
    const status = (e as { status?: number, response?: { status?: number } }).response?.status ?? (e as { status?: number }).status
    error.value = status === 404 ? t('track.not_found') : apiErrorMessage(e, t('errors.generic'))
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-6">
    <div class="text-center">
      <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-ink-100"><AppIcon name="truck" :size="26" /></span>
      <h1 class="text-xl font-extrabold uppercase tracking-wide">{{ $t('track.title') }}</h1>
      <p class="mt-1 text-sm text-ink-500">{{ $t('track.subtitle') }}</p>
    </div>

    <form class="grid gap-3 rounded-md border border-ink-100 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-test="track-form" @submit.prevent="submit">
      <label><span class="label">{{ $t('track.order_number') }}</span><input v-model="form.order_number" class="input uppercase" dir="ltr" placeholder="GC-10001" required maxlength="30"></label>
      <label><span class="label">{{ $t('track.phone') }}</span><input v-model="form.phone" type="tel" class="input" dir="ltr" placeholder="05X XXX XXXX" required maxlength="30"></label>
      <button :disabled="busy" class="btn-primary h-[42px]">{{ $t('track.submit') }}</button>
    </form>
    <p v-if="error" role="alert" class="rounded-md bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

    <div v-if="result" class="space-y-5" data-test="track-result">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-base font-extrabold" dir="ltr">{{ result.order_number }}</h2>
        <StatusBadge :status="result.status" scope="orders.status" />
      </div>
      <div v-for="p in result.packages" :key="p.number" class="space-y-4 rounded-md border border-ink-100 p-4">
        <div class="flex items-center justify-between">
          <p class="text-sm font-bold">{{ $t('orders.package', { n: p.number }) }}</p>
          <div class="flex -space-x-2 rtl:space-x-reverse">
            <img v-for="(i, k) in p.items.slice(0, 4)" :key="k" :src="i.image ?? ''" alt="" class="h-10 w-8 rounded border-2 border-white bg-ink-100 object-cover">
          </div>
        </div>
        <OrderTimeline :pkg="p" />
      </div>
    </div>
  </section>
</template>
