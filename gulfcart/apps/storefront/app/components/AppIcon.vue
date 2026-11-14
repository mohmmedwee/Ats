<script setup lang="ts">
// Minimal stroke icon set (24×24).
const props = defineProps<{ name: 'search' | 'cart' | 'user' | 'home' | 'grid' | 'truck' | 'shield' | 'return' | 'globe' | 'heart' | 'chevron' | 'receipt' | 'check', size?: number }>()
const paths: Record<string, string[]> = {
  search: ['M21 21l-4.3-4.3', 'M10.5 18a7.5 7.5 0 1 0 0-15 7.5 7.5 0 0 0 0 15z'],
  cart: ['M3 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 2-1.6L21.5 7H6', 'M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2z', 'M18 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2z'],
  user: ['M20 21a8 8 0 0 0-16 0', 'M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10z'],
  home: ['M3 11l9-8 9 8', 'M5 10v10h14V10'],
  grid: ['M4 4h7v7H4z', 'M13 4h7v7h-7z', 'M4 13h7v7H4z', 'M13 13h7v7h-7z'],
  truck: ['M3 6h11v10H3z', 'M14 9h4l3 3v4h-7', 'M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', 'M17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z'],
  shield: ['M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z', 'M9 12l2 2 4-4'],
  return: ['M9 14L4 9l5-5', 'M4 9h11a5 5 0 0 1 0 10h-3'],
  globe: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M3 12h18', 'M12 3a14 14 0 0 1 0 18', 'M12 3a14 14 0 0 0 0 18'],
  heart: ['M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z'],
  chevron: ['M9 6l6 6-6 6'],
  receipt: ['M6 3h12v18l-3-2-3 2-3-2-3 2z', 'M9 8h6', 'M9 12h6'],
  check: ['M5 12l5 5 9-10'],
}
</script>

<template>
  <svg :width="props.size ?? 22" :height="props.size ?? 22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path v-for="d in paths[props.name]" :key="d" :d="d" />
  </svg>
</template>
