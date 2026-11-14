<script setup lang="ts">
import type { Envelope, OrderSummary } from '~/types/api'

definePageMeta({ middleware: 'auth' })
const { request } = useApi()
const { locale } = useI18n()
const localePath = useLocalePath()
const orders = ref<OrderSummary[]>([])
onMounted(async () => {
  orders.value = (await request<Envelope<OrderSummary[]>>('/orders')).data
})
</script>

<template>
  <section>
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('orders.title') }}</h1>
    <p v-if="!orders.length" class="text-gray-500">{{ $t('orders.empty') }}</p>
    <ul class="divide-y rounded border">
      <li v-for="o in orders" :key="o.id">
        <NuxtLink :to="localePath(`/account/orders/${o.id}`)" class="flex flex-wrap items-center gap-4 p-3 hover:bg-gray-50">
          <img v-if="o.image" :src="o.image" alt="" class="h-12 w-12 rounded object-cover">
          <span class="flex-1 font-medium">{{ o.order_number }}</span>
          <StatusBadge :status="o.status" scope="orders.status" />
          <span>{{ formatMoney(o.total, locale) }}</span>
        </NuxtLink>
      </li>
    </ul>
  </section>
</template>
