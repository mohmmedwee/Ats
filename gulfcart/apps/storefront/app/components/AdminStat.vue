<script setup lang="ts">
import type { IconName } from '~/components/AppIcon.vue'

defineProps<{ label: string, value: string | number, icon?: IconName, tone?: 'default' | 'warn' | 'danger' | 'good', to?: string }>()
</script>

<template>
  <component :is="to ? 'NuxtLink' : 'div'" :to="to" class="card flex items-center gap-4 p-4 transition" :class="to ? 'hover:shadow-md' : ''">
    <span
      v-if="icon" class="grid h-11 w-11 shrink-0 place-items-center rounded-xl"
      :class="{ 'bg-red-50 text-red-600': tone === 'danger', 'bg-gold-100 text-gold-600': tone === 'warn', 'bg-green-50 text-green-700': tone === 'good', 'bg-brand-50 text-brand-600': !tone || tone === 'default' }"
    ><AppIcon :name="icon" /></span>
    <span class="min-w-0">
      <span class="block text-xs text-ink-500">{{ label }}</span>
      <span class="block truncate text-xl font-bold text-ink-900" dir="auto">{{ value }}</span>
    </span>
  </component>
</template>
