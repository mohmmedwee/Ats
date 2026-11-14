<script setup lang="ts">
import type { Category, Envelope } from '~/types/api'

const { request } = useApi()
const localePath = useLocalePath()
const route = useRoute()
const { data: categories } = await useAsyncData('nav-categories', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })
const active = computed(() => String(route.query.category ?? ''))
</script>

<template>
  <nav class="border-b bg-white">
    <div class="no-scrollbar mx-auto flex max-w-7xl gap-6 overflow-x-auto px-4 text-sm">
      <NuxtLink :to="localePath('/products')" class="whitespace-nowrap border-b-2 py-3" :class="!active ? 'border-ink-900 font-semibold' : 'border-transparent text-ink-700'">
        {{ $t('home.all') }}
      </NuxtLink>
      <template v-for="c in categories" :key="c.id">
        <NuxtLink :to="localePath({ path: '/products', query: { category: c.slug } })" class="whitespace-nowrap border-b-2 py-3" :class="active === c.slug ? 'border-ink-900 font-semibold' : 'border-transparent text-ink-700 hover:text-ink-900'">
          {{ c.name }}
        </NuxtLink>
      </template>
    </div>
  </nav>
</template>
