<script setup lang="ts">
import { computed } from 'vue'

import type { Provenance } from '@/api/client'

const props = defineProps<{ provenance: Provenance }>()

/**
 * The distinction this badge draws is the one the whole system rests on: what
 * the candidate has vouched for, what came off the CV, and what a model wrote.
 * Only the first is safe to put in an application unreviewed.
 */
const label = computed(() => {
  switch (props.provenance) {
    case 'user_confirmed':
      return 'Confirmed'
    case 'cv_derived':
      return 'From CV'
    default:
      return 'Draft'
  }
})

const classes = computed(() => {
  switch (props.provenance) {
    case 'user_confirmed':
      return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    case 'cv_derived':
      return 'bg-sky-50 text-sky-700 ring-sky-200'
    default:
      return 'bg-amber-50 text-amber-800 ring-amber-200'
  }
})
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
    :class="classes"
    >{{ label }}</span
  >
</template>
