<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
const auth = useAuthStore()
</script>

<template>
  <section>
    <h1 class="text-2xl font-semibold">{{ $t('admin.title') }}</h1>
    <ul class="mt-4 list-disc ps-6">
      <li v-if="auth.can('users.manage')">{{ $t('admin.users') }}</li>
      <li v-if="auth.can('roles.manage')">{{ $t('admin.roles') }}</li>
    </ul>
  </section>
</template>
