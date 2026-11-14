<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Category { id: string, slug: string, name: { en?: string, ar?: string }, size_guide: { en?: string | null, ar?: string | null } | null, parent_id: string | null, sort_order: number, is_active: boolean }
const { request, send, run, error, notice, tr } = useAdmin()
const { t, locale } = useI18n()
const categories = ref<Category[]>([])
const editing = ref<Category | null>(null)
const open = ref(false)
const form = reactive({ name_en: '', name_ar: '', slug: '', parent_id: '', sort_order: 0, is_active: true, guide_en: '', guide_ar: '' })

const tree = computed(() => {
  const out: { c: Category, depth: number }[] = []
  const walk = (parent: string | null, depth: number) => categories.value.filter(c => c.parent_id === parent).forEach((c) => {
    out.push({ c, depth })
    walk(c.id, depth + 1)
  })
  walk(null, 0)
  return out
})
async function load() {
  await run(async () => {
    categories.value = (await request<{ data: Category[] }>('/admin/categories')).data
  })
}
function edit(c: Category | null) {
  editing.value = c
  Object.assign(form, c
    ? { name_en: c.name.en ?? '', name_ar: c.name.ar ?? '', slug: c.slug, parent_id: c.parent_id ?? '', sort_order: c.sort_order, is_active: c.is_active, guide_en: c.size_guide?.en ?? '', guide_ar: c.size_guide?.ar ?? '' }
    : { name_en: '', name_ar: '', slug: '', parent_id: '', sort_order: 0, is_active: true, guide_en: '', guide_ar: '' })
  open.value = true
}
function autoSlug() {
  if (!editing.value) form.slug = form.name_en.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
}
async function save() {
  const body = { name: { en: form.name_en, ar: form.name_ar }, size_guide: { en: form.guide_en || null, ar: form.guide_ar || null }, slug: form.slug, parent_id: form.parent_id || null, sort_order: Number(form.sort_order), is_active: form.is_active }
  const ok = await run(() => editing.value ? send('PATCH', `/admin/categories/${editing.value.id}`, body) : send('POST', '/admin/categories', body), t('admin.common.saved'))
  if (ok !== undefined) {
    open.value = false
    await load()
  }
}
async function remove(c: Category) {
  if (!confirm(t('admin.common.confirm'))) return
  await run(() => send('DELETE', `/admin/categories/${c.id}`), t('admin.common.done'))
  await load()
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.categories.title')" :subtitle="$t('admin.categories.subtitle')">
      <button class="btn-primary btn-sm" @click="edit(null)"><AppIcon name="plus" :size="14" /> {{ $t('admin.categories.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.common.name') }}</th><th>Slug</th><th>{{ $t('admin.categories.order') }}</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="{ c, depth } in tree" :key="c.id">
            <td><span :style="{ paddingInlineStart: `${depth * 1.5}rem` }" class="inline-block">{{ depth ? '└ ' : '' }}<b>{{ tr(c.name as never) }}</b></span> <span class="text-xs text-ink-500">{{ locale === 'ar' ? c.name.en : c.name.ar }}</span></td>
            <td class="font-mono text-xs" dir="ltr">{{ c.slug }}</td>
            <td>{{ c.sort_order }}</td>
            <td><span :class="c.is_active ? 'text-green-700' : 'text-ink-500'">{{ c.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</span></td>
            <td class="whitespace-nowrap text-end">
              <button class="btn-outline btn-sm" @click="edit(c)"><AppIcon name="edit" :size="14" /></button>
              <button class="btn-sm ms-1 text-red-600" :aria-label="$t('common.remove')" @click="remove(c)"><AppIcon name="trash" :size="14" /></button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="!categories.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>

    <AdminModal :open="open" :title="editing ? $t('admin.categories.edit') : $t('admin.categories.add')" @close="open = false">
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
        <label class="block"><span class="label">{{ $t('admin.common.name_en') }}</span><input v-model="form.name_en" class="input" dir="ltr" required @input="autoSlug"></label>
        <label class="block"><span class="label">{{ $t('admin.common.name_ar') }}</span><input v-model="form.name_ar" class="input" dir="rtl" required></label>
        <label class="block"><span class="label">Slug</span><input v-model="form.slug" class="input" dir="ltr" required pattern="[a-z0-9]+(-[a-z0-9]+)*"></label>
        <label class="block"><span class="label">{{ $t('admin.categories.parent') }}</span>
          <select v-model="form.parent_id" class="input"><option value="">—</option><option v-for="c in categories.filter(x => x.id !== editing?.id)" :key="c.id" :value="c.id">{{ tr(c.name as never) }}</option></select>
        </label>
        <label class="block"><span class="label">{{ $t('admin.categories.order') }}</span><input v-model.number="form.sort_order" class="input" dir="ltr"></label>
        <label class="mt-6 flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> {{ $t('admin.common.active') }}</label>
        <label><span class="label">{{ $t('admin.categories.size_guide') }} (EN)</span><textarea v-model="form.guide_en" class="input font-mono text-xs" rows="5" dir="ltr" placeholder="## Size chart&#10;- S: chest 86–90 cm&#10;- M: chest 90–94 cm" /></label>
        <label><span class="label">{{ $t('admin.categories.size_guide') }} (AR)</span><textarea v-model="form.guide_ar" class="input font-mono text-xs" rows="5" dir="rtl" /></label>
        <p class="text-xs text-ink-500 sm:col-span-2">{{ $t('admin.categories.size_guide_hint') }}</p>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
