<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ product: ProductCard }>()
const { locale } = useI18n()
const localePath = useLocalePath()
</script>

<template>
  <NuxtLink :to="localePath(`/products/${product.slug}`)" class="group block rounded-lg border p-3 hover:shadow" data-test="product-card">
    <div class="aspect-square overflow-hidden rounded bg-gray-100">
      <img v-if="product.image" :src="product.image" :alt="product.title" class="h-full w-full object-cover group-hover:scale-105" loading="lazy">
    </div>
    <p v-if="product.brand" class="mt-2 text-xs uppercase text-gray-500">{{ product.brand }}</p>
    <h3 class="font-medium">{{ product.title }}</h3>
    <p v-if="product.price" class="mt-1 font-semibold">{{ $t('catalog.from', { price: formatMoney(product.price, locale) }) }}</p>
    <p v-if="product.delivery_estimate" class="mt-1 text-xs text-gray-500">
      {{ $t('catalog.delivery', { min: product.delivery_estimate.min_days, max: product.delivery_estimate.max_days }) }}
    </p>
  </NuxtLink>
</template>
