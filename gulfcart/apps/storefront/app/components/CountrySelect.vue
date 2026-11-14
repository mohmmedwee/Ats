<script setup lang="ts">
const { country } = useApi()
const countries = ['AE', 'SA'] as const
const cart = useCartStore()
watch(country, () => cart.load().catch(() => undefined))
</script>

<template>
  <label class="flex items-center gap-2 text-sm">
    <span>{{ $t('country.label') }}</span>
    <select v-model="country" class="rounded border px-2 py-1">
      <option v-for="c in countries" :key="c" :value="c">{{ $t(`country.${c}`) }}</option>
    </select>
  </label>
</template>
