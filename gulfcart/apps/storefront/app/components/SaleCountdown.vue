<script setup lang="ts">
/** Live "ends in" timer: d hh:mm:ss boxes. Emits `ended` once when it reaches zero. */
const props = defineProps<{ ends: string, dark?: boolean }>()
const emit = defineEmits<{ ended: [] }>()
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 1000)))
onBeforeUnmount(() => clearInterval(timer))

const left = computed(() => Math.max(0, Math.floor((new Date(props.ends).getTime() - now.value) / 1000)))
watch(left, (v, old) => {
  if (v === 0 && old > 0) emit('ended')
})
const parts = computed(() => {
  const s = left.value
  const d = Math.floor(s / 86400)
  const pad = (n: number) => String(n).padStart(2, '0')
  return [...(d > 0 ? [`${d}d`] : []), pad(Math.floor((s % 86400) / 3600)), pad(Math.floor((s % 3600) / 60)), pad(s % 60)]
})
</script>

<template>
  <span class="inline-flex items-center gap-1 font-mono text-sm font-bold tabular-nums" dir="ltr" data-test="countdown" :aria-label="$t('flash.ends_in')">
    <template v-for="(p, i) in parts" :key="i">
      <span v-if="i > 0 && !parts[i - 1]!.endsWith('d')" class="opacity-60">:</span>
      <span class="min-w-7 rounded px-1 py-0.5 text-center" :class="dark ? 'bg-white text-ink-900' : 'bg-ink-900 text-white'">{{ p }}</span>
    </template>
  </span>
</template>
