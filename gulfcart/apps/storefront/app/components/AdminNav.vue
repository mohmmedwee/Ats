<script setup lang="ts">
const auth = useAuthStore()
const localePath = useLocalePath()
</script>

<template>
  <nav class="mb-6 flex flex-wrap gap-4 border-b pb-2 text-sm">
    <NuxtLink :to="localePath('/admin')">{{ $t('admin.dashboard') }}</NuxtLink>
    <NuxtLink v-if="auth.can('procurement.view')" :to="localePath('/admin/procurement')">{{ $t('admin.procurement') }}</NuxtLink>
    <NuxtLink v-if="auth.can('refunds.request') || auth.can('refunds.approve')" :to="localePath('/admin/refunds')">{{ $t('admin.refunds') }}</NuxtLink>
    <NuxtLink v-if="auth.can('orders.view_any')" :to="localePath('/admin/returns')">{{ $t('admin.returns') }}</NuxtLink>
    <NuxtLink v-if="auth.can('catalog.manage')" :to="localePath('/admin/products')">{{ $t('admin.products') }}</NuxtLink>
    <NuxtLink v-if="auth.can('suppliers.manage')" :to="localePath('/admin/suppliers')">{{ $t('admin.suppliers') }}</NuxtLink>
    <NuxtLink v-if="auth.can('catalog.manage')" :to="localePath('/admin/imports')">{{ $t('admin.imports') }}</NuxtLink>
  </nav>
</template>
