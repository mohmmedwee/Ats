<script setup lang="ts">
// Friendly brand URL (/brands/nova) → the product listing filtered by that brand.
const route = useRoute()
const localePath = useLocalePath()
await navigateTo(localePath({ path: '/products', query: { brand: String(route.params.slug) } }), { replace: true, redirectCode: 301 })
</script>

<template>
  <div />
</template>
