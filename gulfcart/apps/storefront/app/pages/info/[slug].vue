<script setup lang="ts">
interface Page { slug: string, title: string, body: string, updated_at: string | null, contact: { email: string | null, whatsapp: string | null, hours: string | null } | null }
const route = useRoute()
const { request } = useApi()
const { locale } = useI18n()
const { site, load } = useSite()
const { data: page, error } = await useAsyncData(`page-${route.params.slug}-${locale.value}`, () =>
  request<{ data: Page }>(`/pages/${route.params.slug}`).then(r => r.data), { watch: [locale] })
useHead(() => ({ title: page.value ? `${page.value.title} · GulfCart` : 'GulfCart' }))
onMounted(() => load())
const waLink = (n: string) => `https://wa.me/${n.replace(/\D/g, '')}`
</script>

<template>
  <div class="mx-auto max-w-4xl">
    <div v-if="page" class="grid gap-6 md:grid-cols-[1fr_15rem]">
      <article class="card p-6 md:p-8">
        <h1 class="mb-6 text-2xl font-bold">{{ page.title }}</h1>
        <RichText :text="page.body" />
        <div v-if="page.contact" class="mt-6 grid gap-3 sm:grid-cols-2">
          <a v-if="page.contact.whatsapp" :href="waLink(page.contact.whatsapp)" target="_blank" rel="noopener" class="btn bg-green-600 text-white hover:bg-green-700" data-test="contact-whatsapp">{{ $t('content.whatsapp') }}</a>
          <a v-if="page.contact.email" :href="`mailto:${page.contact.email}`" class="btn-outline" dir="ltr">{{ page.contact.email }}</a>
          <p v-if="page.contact.hours" class="text-sm text-ink-500 sm:col-span-2">{{ $t('content.hours') }}: <span dir="ltr">{{ page.contact.hours }}</span></p>
        </div>
      </article>
      <aside class="card h-fit p-4 text-sm">
        <p class="mb-2 font-semibold">{{ $t('footer.help') }}</p>
        <NuxtLink v-for="p in site?.pages ?? []" :key="p.slug" :to="$localePath(`/info/${p.slug}`)" class="block rounded-lg px-2 py-1.5 hover:bg-ink-100" :class="p.slug === page.slug ? 'bg-ink-100 font-semibold' : ''">{{ p.title }}</NuxtLink>
      </aside>
    </div>
    <p v-else-if="error" class="card p-8 text-center text-ink-500">{{ $t('errors.generic') }}</p>
  </div>
</template>
