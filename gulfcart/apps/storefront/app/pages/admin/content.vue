<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Page { id: string, slug: string, title: { en: string, ar: string }, body: { en: string, ar: string }, is_published: boolean, in_footer: boolean, sort_order: number, updated_at: string | null }
const { request, send, run, error, notice, tr } = useAdmin()
const { t, locale } = useI18n()
const localePath = useLocalePath()
const pages = ref<Page[]>([])
const editing = ref<Page | null>(null)
const open = ref(false)
const f = reactive({ slug: '', title_en: '', title_ar: '', body_en: '', body_ar: '', is_published: true, in_footer: true, sort_order: 100 })

async function load() {
  await run(async () => {
    pages.value = (await request<{ data: Page[] }>('/admin/pages')).data
  })
}
function edit(p: Page | null) {
  editing.value = p
  Object.assign(f, p
    ? { slug: p.slug, title_en: p.title.en, title_ar: p.title.ar, body_en: p.body.en, body_ar: p.body.ar, is_published: p.is_published, in_footer: p.in_footer, sort_order: p.sort_order }
    : { slug: '', title_en: '', title_ar: '', body_en: '', body_ar: '', is_published: true, in_footer: true, sort_order: 100 })
  open.value = true
}
async function save() {
  const body = { title: { en: f.title_en, ar: f.title_ar }, body: { en: f.body_en, ar: f.body_ar }, is_published: f.is_published, in_footer: f.in_footer, sort_order: Number(f.sort_order), ...(editing.value ? {} : { slug: f.slug }) }
  const ok = await run(() => editing.value ? send('PATCH', `/admin/pages/${editing.value.id}`, body) : send('POST', '/admin/pages', body), t('admin.common.saved'))
  if (ok !== undefined) {
    open.value = false
    await load()
  }
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.content.title')" :subtitle="$t('admin.content.subtitle')">
      <button class="btn-primary btn-sm" @click="edit(null)"><AppIcon name="plus" :size="14" /> {{ $t('admin.content.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <p class="mb-4 rounded-xl bg-gold-100 px-4 py-3 text-sm text-ink-700">{{ $t('admin.content.legal_note') }}</p>
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.common.name') }}</th><th>{{ $t('admin.content.link') }}</th><th>{{ $t('admin.status') }}</th><th>{{ $t('admin.content.updated') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="p in pages" :key="p.id">
            <td class="font-medium">{{ tr(p.title as never) }}</td>
            <td><NuxtLink :to="localePath(`/info/${p.slug}`)" target="_blank" class="link" dir="ltr">/info/{{ p.slug }}</NuxtLink></td>
            <td><span :class="p.is_published ? 'text-green-700' : 'text-ink-500'">{{ p.is_published ? $t('admin.content.published') : $t('admin.content.hidden') }}</span><span v-if="p.in_footer" class="ms-2 text-xs text-ink-500">· {{ $t('admin.content.in_footer') }}</span></td>
            <td class="text-ink-500">{{ formatDateTime(p.updated_at, locale) }}</td>
            <td class="text-end"><button class="btn-outline btn-sm" @click="edit(p)"><AppIcon name="edit" :size="14" /></button></td>
          </tr>
        </tbody>
      </table>
    </div>

    <AdminModal :open="open" :title="editing ? tr(editing.title as never) : $t('admin.content.add')" wide @close="open = false">
      <form class="grid gap-3 md:grid-cols-2" @submit.prevent="save">
        <label v-if="!editing" class="md:col-span-2"><span class="label">{{ $t('admin.content.link') }}</span><input v-model="f.slug" class="input" required pattern="[a-z0-9]+(-[a-z0-9]+)*" dir="ltr" placeholder="shipping-uae"></label>
        <label><span class="label">{{ $t('admin.common.name_en') }}</span><input v-model="f.title_en" class="input" dir="ltr" required></label>
        <label><span class="label">{{ $t('admin.common.name_ar') }}</span><input v-model="f.title_ar" class="input" dir="rtl" required></label>
        <label><span class="label">{{ $t('admin.content.body_en') }}</span><textarea v-model="f.body_en" class="input font-mono text-xs" dir="ltr" rows="16" required /></label>
        <label><span class="label">{{ $t('admin.content.body_ar') }}</span><textarea v-model="f.body_ar" class="input font-mono text-xs" dir="rtl" rows="16" required /></label>
        <p class="text-xs text-ink-500 md:col-span-2">{{ $t('admin.content.format_hint') }}</p>
        <div class="flex flex-wrap items-center gap-4 text-sm md:col-span-2">
          <label class="flex items-center gap-2"><input v-model="f.is_published" type="checkbox"> {{ $t('admin.content.published') }}</label>
          <label class="flex items-center gap-2"><input v-model="f.in_footer" type="checkbox"> {{ $t('admin.content.in_footer') }}</label>
          <label class="flex items-center gap-2">{{ $t('admin.categories.order') }} <input v-model.number="f.sort_order" class="input w-20" dir="ltr"></label>
        </div>
        <div class="flex justify-end gap-2 md:col-span-2"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
