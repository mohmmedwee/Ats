<script setup lang="ts">
import type { Category, Envelope } from '~/types/api'

const { request } = useApi()
const localePath = useLocalePath()
const { t } = useI18n()
const { data: categories } = await useAsyncData('all-categories', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })
const active = ref<string | null>(null)
watch(categories, (c) => {
  active.value ??= c?.[0]?.id ?? null
}, { immediate: true })
const current = computed(() => (categories.value ?? []).find(c => c.id === active.value))
const link = (slug: string) => localePath({ path: '/products', query: { category: slug } })
useHead({ title: `${t('categories.title')} · GulfCart` })
</script>

<template>
  <section>
    <h1 class="section-title mb-4">{{ $t('categories.title') }}</h1>
    <!-- Shopping-app layout: main sections on the side, their sub-sections as a picture grid. -->
    <div class="grid grid-cols-[96px_1fr] gap-3 md:grid-cols-[220px_1fr] md:gap-6">
      <nav class="card h-fit overflow-hidden" data-test="category-sections">
        <button
          v-for="c in categories" :key="c.id" type="button"
          class="block w-full border-s-4 px-3 py-3 text-start text-xs transition md:text-sm"
          :class="c.id === active ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700' : 'border-transparent text-ink-700 hover:bg-ink-100/50'"
          @click="active = c.id"
        >
          {{ c.name }}
        </button>
      </nav>

      <div v-if="current" class="card p-4">
        <div class="mb-4 flex items-center justify-between">
          <h2 class="font-bold">{{ current.name }}</h2>
          <NuxtLink :to="link(current.slug)" class="link text-sm">{{ $t('categories.shop_all') }} →</NuxtLink>
        </div>
        <div class="grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6" data-test="category-tiles">
          <NuxtLink v-for="ch in (current.children.length ? current.children : [current])" :key="ch.id" :to="link(ch.slug)" class="group flex flex-col items-center gap-2 text-center">
            <span class="grid aspect-square w-full place-items-center overflow-hidden rounded-2xl bg-ink-100">
              <img v-if="ch.image_url" :src="ch.image_url" :alt="ch.name" class="h-full w-full object-cover transition group-hover:scale-105">
              <AppIcon v-else name="grid" class="text-ink-300" />
            </span>
            <span class="text-xs font-medium md:text-sm">{{ ch.name }}</span>
          </NuxtLink>
        </div>
      </div>
    </div>
  </section>
</template>
