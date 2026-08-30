<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ tier: string }>()

const label = computed(() => {
  switch (props.tier) {
    case 't0_read':
      return 'Read'
    case 't1_prepare':
      return 'Needs confirmation'
    case 't2_external':
      return 'UI only'
    default:
      return props.tier
  }
})

const classes = computed(() => {
  switch (props.tier) {
    case 't0_read':
      return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    case 't1_prepare':
      return 'bg-amber-50 text-amber-800 ring-amber-200'
    default:
      return 'bg-slate-100 text-slate-700 ring-slate-300'
  }
})
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
    :class="classes"
    >{{ label }}</span
  >
</template>
