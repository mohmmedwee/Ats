<script setup lang="ts">
const { consent, setConsent, init } = useTracking()
const { site } = useSite()
const localePath = useLocalePath()
const mounted = ref(false)
onMounted(() => {
  mounted.value = true
  init()
})
watch(site, () => init())
const hasTags = computed(() => Object.keys(site.value?.tracking ?? {}).length > 0)
</script>

<template>
  <div v-if="mounted && consent === null && hasTags" class="fixed inset-x-3 bottom-20 z-50 mx-auto max-w-xl rounded-2xl bg-ink-900 p-4 text-sm text-white shadow-xl md:bottom-4" role="dialog" :aria-label="$t('consent.title')" data-test="consent-banner">
    <p class="mb-3">{{ $t('consent.text') }} <NuxtLink :to="localePath('/info/privacy')" class="underline">{{ $t('consent.more') }}</NuxtLink></p>
    <div class="flex gap-2">
      <button class="btn bg-white px-4 py-2 text-ink-900" data-test="consent-accept" @click="setConsent('all')">{{ $t('consent.accept') }}</button>
      <button class="btn border border-white/40 px-4 py-2 text-white" @click="setConsent('essential')">{{ $t('consent.essential') }}</button>
    </div>
  </div>
</template>
