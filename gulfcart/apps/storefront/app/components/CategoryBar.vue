<script setup lang="ts">
import type { Category, Envelope } from '~/types/api'

const { request } = useApi()
const localePath = useLocalePath()
const route = useRoute()
const { data: categories } = await useAsyncData('nav-categories', () => request<Envelope<Category[]>>('/categories').then(r => r.data), { server: false, default: () => [] })
const active = computed(() => String(route.query.category ?? ''))
</script>

<template>
  <nav class="bg-white">
    <div class="no-scrollbar mx-auto flex max-w-7xl gap-6 overflow-x-auto px-3 text-[13px] md:justify-center md:gap-8 md:overflow-visible md:px-4">
      <NuxtLink :to="localePath('/products')" class="whitespace-nowrap border-b-2 py-2.5" :class="!active ? 'border-ink-900 font-semibold' : 'border-transparent text-ink-700'">
        {{ $t('home.all') }}
      </NuxtLink>
      <template v-for="c in categories" :key="c.id">
        <div class="group relative">
          <NuxtLink :to="localePath({ path: '/products', query: { category: c.slug } })" class="block whitespace-nowrap border-b-2 py-2.5" :class="active === c.slug || c.children.some(ch => ch.slug === active) ? 'border-ink-900 font-semibold' : 'border-transparent text-ink-700 hover:text-ink-900'">
            {{ c.name }}
          </NuxtLink>
          <!-- desktop dropdown with sub-sections -->
          <div v-if="c.children.length" class="invisible absolute start-0 top-full z-40 hidden min-w-48 rounded-b-xl bg-white p-2 opacity-0 shadow-card transition group-hover:visible group-hover:opacity-100 md:block">
            <NuxtLink v-for="ch in c.children" :key="ch.id" :to="localePath({ path: '/products', query: { category: ch.slug } })" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-ink-100 hover:text-ink-900">
              <img v-if="ch.image_url" :src="ch.image_url" alt="" class="h-7 w-7 rounded-full object-cover">{{ ch.name }}
            </NuxtLink>
          </div>
        </div>
      </template>
      <NuxtLink :to="localePath('/brands')" class="whitespace-nowrap border-b-2 border-transparent py-2.5 text-ink-700 hover:text-ink-900">{{ $t('brands.title') }}</NuxtLink>
    </div>
  </nav>
</template>
