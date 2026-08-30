<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ score: number; routing: string }>()

/**
 * Colour follows the routing decision rather than the raw number, so what the
 * user sees matches what the system will actually do with the job.
 */
const tone = computed(() => {
  switch (props.routing) {
    case 'high_priority':
      return { bar: 'bg-emerald-500', text: 'text-emerald-700' }
    case 'normal_review':
      return { bar: 'bg-sky-500', text: 'text-sky-700' }
    case 'possible_match':
      return { bar: 'bg-amber-500', text: 'text-amber-700' }
    case 'rejected':
      return { bar: 'bg-red-400', text: 'text-red-700' }
    default:
      return { bar: 'bg-slate-400', text: 'text-slate-600' }
  }
})

const label = computed(() => props.routing.replace('_', ' '))
</script>

<template>
  <div class="flex items-center gap-3">
    <span class="w-12 shrink-0 text-right text-sm font-semibold tabular-nums" :class="tone.text">
      {{ score.toFixed(0) }}
    </span>
    <div class="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-slate-200">
      <div class="h-full rounded-full" :class="tone.bar" :style="{ width: `${score}%` }" />
    </div>
    <span class="text-xs" :class="tone.text">{{ label }}</span>
  </div>
</template>
