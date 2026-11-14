<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ product: ProductCard }>()
const { locale } = useI18n()
const localePath = useLocalePath()
</script>

<template>
  <NuxtLink :to="localePath(`/products/${product.slug}`)" class="group block overflow-hidden rounded-xl bg-white transition hover:shadow-card" data-test="product-card">
    <div class="relative aspect-[3/4] overflow-hidden bg-ink-100">
      <img v-if="product.image" :src="product.image" :alt="product.title" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy">
      <WishlistButton :product-id="product.id" floating />
      <span v-if="product.delivery_estimate" class="absolute bottom-2 start-2 inline-flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 text-[11px] font-medium text-ink-700 backdrop-blur">
        <AppIcon name="truck" :size="13" /> {{ $t('common.days', { min: product.delivery_estimate.min_days, max: product.delivery_estimate.max_days }) }}
      </span>
    </div>
    <div class="space-y-1 p-2.5">
      <h3 class="line-clamp-2 min-h-[2.5rem] text-[13px] leading-5 text-ink-700">{{ product.title }}</h3>
      <p v-if="product.price" class="text-base font-bold text-brand-600">{{ formatMoney(product.price, locale) }}</p>
    </div>
  </NuxtLink>
</template>
