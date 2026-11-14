<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ products: ProductCard[], loading?: boolean, skeletons?: number }>()
</script>

<template>
  <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:gap-3 lg:grid-cols-5">
    <ProductCardItem v-for="p in products" :key="p.id" :product="p" />
    <template v-if="loading && !products.length">
      <div v-for="n in skeletons ?? 10" :key="`s${n}`" class="animate-pulse overflow-hidden rounded-xl bg-white">
        <div class="aspect-[3/4] bg-ink-100" />
        <div class="space-y-2 p-2.5"><div class="h-3 rounded bg-ink-100" /><div class="h-4 w-1/2 rounded bg-ink-100" /></div>
      </div>
    </template>
  </div>
</template>
