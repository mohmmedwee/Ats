<script setup lang="ts">
interface Review { id: string, rating: number, title: string | null, body: string | null, author: string | null, verified: boolean, created_at: string, photos?: string[] }
interface Payload { average: number | null, count: number, distribution: Record<string, number>, reviews: Review[], mine: { can_review: boolean, status: string | null } | null }
const props = defineProps<{ productId: string }>()
const { request, csrf } = useApi()
const { locale, t } = useI18n()
const data = ref<Payload | null>(null)
const form = reactive({ rating: 5, title: '', body: '' })
const photos = ref<File[]>([])
const zoom = ref<string | null>(null)
function pick(e: Event) {
  photos.value = Array.from((e.target as HTMLInputElement).files ?? []).slice(0, 3)
}
const open = ref(false)
const msg = ref('')
const error = ref('')

async function load() {
  data.value = (await request<{ data: Payload }>(`/products/${props.productId}/reviews`).catch(() => null))?.data ?? null
}
async function submit() {
  error.value = ''
  try {
    await csrf()
    // multipart so photos can travel with the review
    const fd = new FormData()
    fd.append('rating', String(form.rating))
    if (form.title) fd.append('title', form.title)
    if (form.body) fd.append('body', form.body)
    photos.value.forEach(f => fd.append('photos[]', f))
    await request(`/products/${props.productId}/reviews`, { method: 'POST', body: fd })
    photos.value = []
    open.value = false
    msg.value = t('reviews.thanks')
    await load()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}
onMounted(load)
watch(() => props.productId, load)
</script>

<template>
  <section class="card p-5" data-test="reviews">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <h2 class="text-lg font-bold">{{ $t('reviews.title') }}</h2>
        <template v-if="data?.average">
          <StarRating :value="data.average" />
          <span class="text-sm text-ink-700"><b>{{ data.average }}</b> · {{ $t('reviews.count', { n: data.count }) }}</span>
        </template>
      </div>
      <button v-if="data?.mine?.can_review" class="btn-outline btn-sm" data-test="write-review" @click="open = !open">{{ $t('reviews.write') }}</button>
    </div>
    <p v-if="msg" role="status" class="mb-3 text-sm text-green-700">{{ msg }}</p>
    <p v-else-if="data?.mine?.status === 'pending'" class="mb-3 text-sm text-ink-500">{{ $t('reviews.pending') }}</p>

    <form v-if="open" class="mb-5 space-y-3 rounded-xl bg-ink-100/60 p-4" @submit.prevent="submit">
      <div class="flex items-center gap-1" dir="ltr">
        <button v-for="i in 5" :key="i" type="button" :aria-label="`${i}`" class="text-gold-500" @click="form.rating = i">
          <svg width="28" height="28" viewBox="0 0 24 24" :fill="i <= form.rating ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.5"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z" /></svg>
        </button>
      </div>
      <input v-model="form.title" class="input" :placeholder="$t('reviews.title_ph')" maxlength="120">
      <textarea v-model="form.body" class="input" rows="3" :placeholder="$t('reviews.body_ph')" maxlength="2000" />
      <label class="flex cursor-pointer items-center gap-2 text-sm" data-test="review-photos">
        <AppIcon name="plus" :size="16" /> {{ $t('reviews.add_photos') }}
        <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="pick">
        <span v-if="photos.length" class="text-xs text-ink-500">({{ photos.length }}/3)</span>
      </label>
      <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
      <button class="btn-primary btn-sm">{{ $t('reviews.submit') }}</button>
    </form>

    <div v-if="data?.reviews.length" class="divide-y divide-ink-100">
      <article v-for="r in data.reviews" :key="r.id" class="py-3">
        <div class="flex items-center gap-2 text-sm">
          <StarRating :value="r.rating" :size="14" />
          <b>{{ r.title }}</b>
        </div>
        <p v-if="r.body" class="mt-1 whitespace-pre-line text-sm text-ink-700">{{ r.body }}</p>
        <div v-if="r.photos?.length" class="mt-2 flex gap-2">
          <button v-for="ph in r.photos" :key="ph" type="button" class="h-20 w-16 overflow-hidden rounded-md bg-ink-100" @click="zoom = ph"><img :src="ph" alt="" class="h-full w-full object-cover" loading="lazy"></button>
        </div>
        <p class="mt-1 text-xs text-ink-500">{{ r.author }} · {{ new Date(r.created_at).toLocaleDateString(locale === 'ar' ? 'ar-AE' : 'en-GB') }}<span v-if="r.verified" class="ms-2 text-green-700">✓ {{ $t('reviews.verified') }}</span></p>
      </article>
    </div>
    <p v-else-if="data" class="text-sm text-ink-500">{{ $t('reviews.none') }}</p>
    <div v-if="zoom" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" @click="zoom = null"><img :src="zoom" alt="" class="max-h-full max-w-full rounded-md"></div>
  </section>
</template>
