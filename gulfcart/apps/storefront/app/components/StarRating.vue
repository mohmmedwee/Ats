<script setup lang="ts">
const props = defineProps<{ value: number, size?: number }>()
</script>

<template>
  <span class="inline-flex text-gold-500" dir="ltr" :aria-label="`${props.value} / 5`">
    <svg v-for="i in 5" :key="i" :width="props.size ?? 16" :height="props.size ?? 16" viewBox="0 0 24 24" :fill="i <= Math.round(props.value) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
      <path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" />
    </svg>
  </span>
</template>
