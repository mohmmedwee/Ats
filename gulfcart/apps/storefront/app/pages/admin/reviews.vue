<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Review { id: string, rating: number, title: string | null, body: string | null, author: string | null, status: string, product_id: string, product_title: Record<string, string> | null, created_at: string, locale: string }
const { request, send, run, error, notice, tr } = useAdmin()
const { t, locale } = useI18n()
const localePath = useLocalePath()
const reviews = ref<Review[]>([])
const status = ref('pending')
async function load() {
  await run(async () => {
    reviews.value = (await request<{ data: Review[] }>('/admin/reviews', { query: { status: status.value } })).data
  })
}
async function act(r: Review, action: 'approve' | 'reject') {
  await run(() => send('POST', `/admin/reviews/${r.id}/${action}`), t('admin.common.done'))
  await load()
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.reviews.title')" :subtitle="$t('admin.reviews.subtitle')">
      <button v-for="s in ['pending', 'approved', 'rejected']" :key="s" class="chip" :class="status === s ? 'chip-active' : ''" @click="status = s; load()">{{ $t(`admin.status_labels.${s}`) }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="space-y-3">
      <article v-for="r in reviews" :key="r.id" class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs text-ink-500"><NuxtLink :to="localePath(`/admin/products/${r.product_id}`)" class="link">{{ tr(r.product_title as never) }}</NuxtLink> · {{ r.author }} · {{ formatDateTime(r.created_at, locale) }}</p>
            <p class="mt-1 flex items-center gap-2"><StarRating :value="r.rating" /> <b>{{ r.title }}</b></p>
            <p v-if="r.body" class="mt-1 whitespace-pre-line text-sm text-ink-700" :dir="r.locale === 'ar' ? 'rtl' : 'ltr'">{{ r.body }}</p>
          </div>
          <div class="flex gap-2">
            <button v-if="r.status !== 'approved'" class="btn-primary btn-sm" @click="act(r, 'approve')">{{ $t('admin.products_page.approve') }}</button>
            <button v-if="r.status !== 'rejected'" class="btn-outline btn-sm" @click="act(r, 'reject')">{{ $t('admin.products_page.reject') }}</button>
          </div>
        </div>
      </article>
      <p v-if="!reviews.length" class="card p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
  </section>
</template>
