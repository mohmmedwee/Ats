<script setup lang="ts">
const { locale, locales } = useI18n()
const switchLocalePath = useSwitchLocalePath()
const others = computed(() => locales.value.filter(l => l.code !== locale.value))
</script>

<template>
  <span class="flex gap-2">
    <NuxtLink
      v-for="l in others"
      :key="l.code"
      :to="switchLocalePath(l.code)"
      class="text-sm underline"
      data-test="locale-switch"
    >
      {{ l.name }}
    </NuxtLink>
  </span>
</template>
