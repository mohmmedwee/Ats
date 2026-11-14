<script setup lang="ts">
const { country } = useApi()
const countries = ['AE', 'SA'] as const
const cart = useCartStore()
watch(country, () => cart.load().catch(() => undefined))
</script>

<template>
  <label class="inline-flex items-center gap-1 text-xs text-ink-700">
    <AppIcon name="globe" :size="16" />
    <span class="sr-only">{{ $t('country.label') }}</span>
    <select v-model="country" class="cursor-pointer bg-transparent font-medium outline-none" data-test="country-select">
      <option v-for="c in countries" :key="c" :value="c">{{ $t(`country.${c}`) }} · {{ c === 'AE' ? 'AED' : 'SAR' }}</option>
    </select>
  </label>
</template>
