<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Brand { id: string, slug: string, name: { en: string, ar: string | null }, description: { en: string | null, ar: string | null } | null, is_authorized: boolean, authorization_note: string | null, is_featured: boolean, is_active: boolean, sort_order: number, logo_url: string | null, product_count: number }
const { request, send, run, error, notice, tr } = useAdmin()
const { csrf } = useApi()
const { t } = useI18n()
const brands = ref<Brand[]>([])
const open = ref(false)
const editing = ref<Brand | null>(null)
const f = reactive({ name_en: '', name_ar: '', slug: '', desc_en: '', desc_ar: '', is_authorized: false, authorization_note: '', is_featured: false, is_active: true })

async function load() {
  await run(async () => {
    brands.value = (await request<{ data: Brand[] }>('/admin/brands')).data
  })
}
function edit(b: Brand | null) {
  editing.value = b
  Object.assign(f, b
    ? { name_en: b.name.en, name_ar: b.name.ar ?? '', slug: b.slug, desc_en: b.description?.en ?? '', desc_ar: b.description?.ar ?? '', is_authorized: b.is_authorized, authorization_note: b.authorization_note ?? '', is_featured: b.is_featured, is_active: b.is_active }
    : { name_en: '', name_ar: '', slug: '', desc_en: '', desc_ar: '', is_authorized: false, authorization_note: '', is_featured: false, is_active: true })
  open.value = true
}
async function save() {
  const body = {
    name: { en: f.name_en, ar: f.name_ar || null }, description: { en: f.desc_en || null, ar: f.desc_ar || null },
    is_authorized: f.is_authorized, authorization_note: f.authorization_note || null, is_featured: f.is_featured, is_active: f.is_active,
    ...(editing.value ? {} : f.slug ? { slug: f.slug } : {}),
  }
  const ok = await run(() => editing.value ? send('PATCH', `/admin/brands/${editing.value.id}`, body) : send('POST', '/admin/brands', body), t('admin.common.saved'))
  if (ok !== undefined) {
    open.value = false
    await load()
  }
}
async function upload(b: Brand, e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  await run(async () => {
    await csrf()
    await request(`/admin/brands/${b.id}/logo`, { method: 'POST', body })
  }, t('admin.common.saved'))
  await load()
}
async function toggle(b: Brand, field: 'is_authorized' | 'is_featured' | 'is_active') {
  await run(() => send('PATCH', `/admin/brands/${b.id}`, { [field]: !b[field] }), t('admin.common.saved'))
  await load()
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.brands.title')" :subtitle="$t('admin.brands.subtitle')">
      <button class="btn-primary btn-sm" @click="edit(null)"><AppIcon name="plus" :size="14" /> {{ $t('admin.brands.add') }}</button>
    </AdminHeader>
    <AdminAlerts :error="error" :notice="notice" />
    <div class="card overflow-x-auto">
      <table class="admin-table">
        <thead><tr><th>{{ $t('admin.brands.logo') }}</th><th>{{ $t('admin.common.name') }}</th><th>{{ $t('admin.brands.products') }}</th><th>{{ $t('admin.brands.authorized') }}</th><th>{{ $t('admin.brands.featured') }}</th><th>{{ $t('admin.status') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="b in brands" :key="b.id" data-test="admin-brand">
            <td>
              <label class="grid h-12 w-12 cursor-pointer place-items-center overflow-hidden rounded-lg border border-dashed border-ink-300 bg-white" :title="$t('admin.brands.upload_logo')">
                <img v-if="b.logo_url" :src="b.logo_url" alt="" class="h-full w-full object-contain">
                <AppIcon v-else name="plus" :size="16" class="text-ink-300" />
                <input type="file" accept="image/*" class="hidden" @change="upload(b, $event)">
              </label>
            </td>
            <td><b>{{ tr(b.name as never) }}</b><span class="block font-mono text-xs text-ink-500" dir="ltr">{{ b.slug }}</span></td>
            <td>{{ b.product_count }}</td>
            <td>
              <button class="chip text-xs" :class="b.is_authorized ? 'border-green-600 bg-green-50 text-green-800' : ''" @click="toggle(b, 'is_authorized')">{{ b.is_authorized ? '✓ ' + $t('brands.authorized_short') : '—' }}</button>
              <span v-if="b.authorization_note" class="block text-xs text-ink-500">{{ b.authorization_note }}</span>
            </td>
            <td><button class="chip text-xs" :class="b.is_featured ? 'chip-active' : ''" @click="toggle(b, 'is_featured')">{{ b.is_featured ? '★' : '☆' }}</button></td>
            <td><button class="chip text-xs" :class="b.is_active ? 'chip-active' : ''" @click="toggle(b, 'is_active')">{{ b.is_active ? $t('admin.common.active') : $t('admin.common.inactive') }}</button></td>
            <td class="text-end"><button class="btn-outline btn-sm" @click="edit(b)"><AppIcon name="edit" :size="14" /></button></td>
          </tr>
        </tbody>
      </table>
      <p v-if="!brands.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>

    <AdminModal :open="open" :title="editing ? $t('admin.brands.edit') : $t('admin.brands.add')" wide @close="open = false">
      <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
        <label><span class="label">{{ $t('admin.common.name_en') }}</span><input v-model="f.name_en" class="input" dir="ltr" required></label>
        <label><span class="label">{{ $t('admin.common.name_ar') }}</span><input v-model="f.name_ar" class="input" dir="rtl"></label>
        <label v-if="!editing" class="sm:col-span-2"><span class="label">Slug</span><input v-model="f.slug" class="input" dir="ltr" pattern="[a-z0-9]+(-[a-z0-9]+)*" :placeholder="f.name_en.toLowerCase().replace(/[^a-z0-9]+/g, '-')"></label>
        <label><span class="label">{{ $t('admin.brands.description') }} (EN)</span><textarea v-model="f.desc_en" class="input" rows="3" dir="ltr" /></label>
        <label><span class="label">{{ $t('admin.brands.description') }} (AR)</span><textarea v-model="f.desc_ar" class="input" rows="3" dir="rtl" /></label>
        <label class="flex items-center gap-2 text-sm"><input v-model="f.is_authorized" type="checkbox"> {{ $t('admin.brands.authorized') }}</label>
        <label><span class="label">{{ $t('admin.brands.note') }}</span><input v-model="f.authorization_note" class="input"></label>
        <p class="text-xs text-ink-500 sm:col-span-2">{{ $t('admin.brands.authorized_hint') }}</p>
        <label class="flex items-center gap-2 text-sm"><input v-model="f.is_featured" type="checkbox"> {{ $t('admin.brands.featured') }}</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="f.is_active" type="checkbox"> {{ $t('admin.common.active') }}</label>
        <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-outline" @click="open = false">{{ $t('common.cancel') }}</button><button class="btn-primary">{{ $t('common.save') }}</button></div>
      </form>
    </AdminModal>
  </section>
</template>
