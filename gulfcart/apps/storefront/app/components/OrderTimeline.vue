<script setup lang="ts">
import type { IconName } from './AppIcon.vue'
import type { PackageProgress, Tracking } from '~/types/api'

/** Package progress: steps with dates, expected delivery, carrier link and latest carrier events. */
const props = defineProps<{ pkg: PackageProgress & { tracking: Pick<Tracking, 'carrier' | 'tracking_number' | 'tracking_url' | 'events'>[] } }>()
const { locale } = useI18n()
const showAll = ref(false)
const fmt = (iso: string | null, time = false) => (iso
  ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-AE' : 'en-GB', time ? { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'short' })
  : '')
const current = computed(() => {
  const s = props.pkg.steps
  const last = s.map(x => x.done).lastIndexOf(true)
  return last
})
const events = computed(() => props.pkg.tracking.flatMap(t => t.events).sort((a, b) => b.occurred_at.localeCompare(a.occurred_at)))
const icons: Record<string, IconName> = { placed: 'receipt', confirmed: 'check', preparing: 'box', shipped: 'truck', delivered: 'home' }
</script>

<template>
  <div class="space-y-4" data-test="order-timeline">
    <p v-if="pkg.estimated_delivery" class="flex items-center gap-2 rounded-md bg-ink-100/70 px-3 py-2.5 text-sm" data-test="eta">
      <AppIcon name="clock" :size="17" />
      <span>{{ $t('track.eta') }} <b>{{ fmt(pkg.estimated_delivery.from) }} – {{ fmt(pkg.estimated_delivery.to) }}</b></span>
    </p>

    <!-- steps: vertical on phones, horizontal from md -->
    <ol v-if="pkg.steps.length" class="relative grid gap-0 md:grid-cols-5">
      <li v-for="(s, i) in pkg.steps" :key="s.key" class="relative flex gap-3 pb-5 md:flex-col md:items-center md:pb-0 md:text-center">
        <!-- connector -->
        <span v-if="i < pkg.steps.length - 1" class="absolute start-[15px] top-8 h-[calc(100%-24px)] w-0.5 md:start-[calc(50%+18px)] md:top-[15px] md:h-0.5 md:w-[calc(100%-36px)]" :class="pkg.steps[i + 1]!.done ? 'bg-ink-900' : 'bg-ink-100'" />
        <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 transition" :class="s.done ? 'border-ink-900 bg-ink-900 text-white' : i === current + 1 ? 'border-ink-900 bg-white text-ink-900' : 'border-ink-100 bg-white text-ink-300'">
          <AppIcon :name="icons[s.key] ?? 'check'" :size="15" />
        </span>
        <span class="pt-1 md:pt-2">
          <span class="block text-sm" :class="s.done ? 'font-bold text-ink-900' : 'text-ink-500'">{{ $t(`track.steps.${s.key}`) }}</span>
          <span v-if="s.at" class="block text-xs text-ink-500">{{ fmt(s.at, true) }}</span>
          <span v-else-if="i === current + 1" class="block text-xs text-ink-500">{{ $t(`track.next.${s.key}`) }}</span>
        </span>
      </li>
    </ol>
    <p v-else class="text-sm text-ink-500">{{ $t('track.cancelled') }}</p>

    <div v-for="t in pkg.tracking.filter(x => x.tracking_number)" :key="t.tracking_number!" class="flex flex-wrap items-center gap-2 text-sm">
      <AppIcon name="truck" :size="16" /><span class="font-semibold">{{ t.carrier }}</span><span class="font-mono text-ink-500" dir="ltr">{{ t.tracking_number }}</span>
      <a v-if="t.tracking_url" :href="t.tracking_url" target="_blank" rel="noopener" class="ms-auto text-xs font-bold underline underline-offset-4">{{ $t('track.carrier_site') }}</a>
    </div>

    <ol v-if="events.length" class="space-y-2 border-s-2 border-ink-100 ps-4 text-sm" data-test="carrier-events">
      <li v-for="e in (showAll ? events : events.slice(0, 3))" :key="e.code + e.occurred_at" class="relative">
        <span class="absolute -start-[21px] top-1.5 h-2 w-2 rounded-full bg-ink-300" />
        <p>{{ e.description }}<span v-if="e.location" class="text-ink-500"> · {{ e.location }}</span></p>
        <p class="text-xs text-ink-500">{{ fmt(e.occurred_at, true) }}</p>
      </li>
      <li v-if="events.length > 3"><button type="button" class="text-xs font-semibold underline" @click="showAll = !showAll">{{ showAll ? $t('track.less') : $t('track.more', { n: events.length - 3 }) }}</button></li>
    </ol>
  </div>
</template>
