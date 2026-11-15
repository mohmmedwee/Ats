<script setup lang="ts">
import type { ProductCard } from '~/types/api'

const props = defineProps<{ product: ProductCard }>()
const { locale } = useI18n()
const localePath = useLocalePath()

/** Hovering (or tapping) a colour swatch previews that colour's photo. */
const preview = ref<string | null>(null)
const image = computed(() => preview.value ?? props.product.image)
const SWATCH: Record<string, string> = {
  black: '#1f1f23', white: '#ffffff', beige: '#e7d7bd', cream: '#f3ead7', navy: '#24325f', blue: '#2f6fd6', red: '#d22b2b',
  pink: '#f2a6bf', green: '#2f8a4d', olive: '#6f7340', brown: '#7b4d2a', grey: '#9a9aa2', gray: '#9a9aa2', silver: '#c0c0c8', gold: '#d4af37',
  yellow: '#f2c230', orange: '#f08a2b', purple: '#7a4fb3', burgundy: '#7a1f33', khaki: '#b9a77a',
}
const colors = computed(() => props.product.colors ?? [])
</script>

<template>
  <NuxtLink :to="localePath(`/products/${product.slug}`)" class="group block" data-test="product-card">
    <div class="relative aspect-[3/4] overflow-hidden rounded-md bg-ink-100">
      <img v-if="image" :src="image" :alt="product.title" class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]" loading="lazy">
      <WishlistButton :product-id="product.id" floating />
      <span v-if="product.delivery_estimate" class="absolute bottom-0 start-0 m-1.5 inline-flex items-center gap-1 rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-medium text-ink-700 backdrop-blur">
        <AppIcon name="truck" :size="11" /> {{ $t('common.days', { min: product.delivery_estimate.min_days, max: product.delivery_estimate.max_days }) }}
      </span>
    </div>
    <div class="space-y-1 px-0.5 pb-2 pt-2">
      <div v-if="colors.length > 1" class="flex items-center gap-1" @mouseleave="preview = null">
        <button
          v-for="c in colors.slice(0, 4)" :key="c.name" type="button"
          class="h-3.5 w-3.5 overflow-hidden rounded-full border border-ink-300"
          :style="{ background: SWATCH[c.name.toLowerCase()] ?? '#ddd' }" :aria-label="c.name"
          @mouseenter="preview = c.image" @click.prevent="preview = c.image"
        />
        <span v-if="colors.length > 4" class="text-[10px] text-ink-500">+{{ colors.length - 4 }}</span>
      </div>
      <h3 class="line-clamp-1 text-[12px] leading-5 text-ink-700 md:text-[13px]">{{ product.title }}</h3>
      <p v-if="product.price" class="text-[15px] font-extrabold text-ink-900" dir="auto">{{ formatMoney(product.price, locale) }}</p>
    </div>
  </NuxtLink>
</template>
