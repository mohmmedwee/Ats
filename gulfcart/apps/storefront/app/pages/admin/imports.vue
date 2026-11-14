<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface ImportResult { id: string, supplier_id: string, source: string, status: string, total_rows: number, created_count: number, updated_count: number, error_count: number, errors: { row: number, errors: string[] }[], created_at: string | null }
const { request, run, error, notice } = useAdmin()
const { csrf } = useApi()
const { locale, t } = useI18n()
const suppliers = ref<{ id: string, name: string }[]>([])
const supplierId = ref('')
const file = ref<File | null>(null)
const history = ref<ImportResult[]>([])
const expanded = ref<string | null>(null)
const names = computed(() => Object.fromEntries(suppliers.value.map(s => [s.id, s.name])))

async function loadHistory() {
  history.value = (await request<{ data: ImportResult[] }>('/admin/imports')).data
}
onMounted(() => run(async () => {
  suppliers.value = (await request<{ data: { id: string, name: string }[] }>('/admin/suppliers').catch(() => ({ data: [] }))).data
  supplierId.value = suppliers.value[0]?.id ?? ''
  await loadHistory()
}))
async function upload() {
  if (!file.value || !supplierId.value) return
  const body = new FormData()
  body.append('supplier_id', supplierId.value)
  body.append('file', file.value)
  await run(async () => {
    await csrf()
    const created = (await request<{ data: ImportResult }>('/admin/imports/products', { method: 'POST', body })).data
    for (let i = 0; i < 30; i++) {
      const r = (await request<{ data: ImportResult }>(`/admin/imports/${created.id}`)).data
      if (['completed', 'failed'].includes(r.status)) break
      await new Promise(res => setTimeout(res, 1000))
    }
    expanded.value = created.id
    await loadHistory()
  }, t('admin.imports_page.uploaded'))
}
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.imports')" :subtitle="$t('admin.imports_page.subtitle')" />
    <AdminAlerts :error="error" :notice="notice" />
    <form class="card mb-6 flex flex-wrap items-end gap-3 p-4" @submit.prevent="upload">
      <label><span class="label">{{ $t('admin.supplier') }}</span>
        <select v-model="supplierId" class="input"><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
      </label>
      <label class="flex-1"><span class="label">{{ $t('admin.imports_page.file') }}</span><input type="file" accept=".csv,text/csv" required class="input" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null"></label>
      <button class="btn-dark">{{ $t('admin.upload') }}</button>
      <p class="w-full text-xs text-ink-500" dir="ltr">external_product_id, external_variant_id, sku, title_en, title_ar, description_en, description_ar, category_slug, brand, attributes, cost_price, cost_currency, stock_status, image_url, weight_grams, hs_code, countries</p>
    </form>

    <div class="card overflow-x-auto">
      <h2 class="px-4 pt-4 font-bold">{{ $t('admin.imports_page.history') }}</h2>
      <table class="admin-table mt-2">
        <thead><tr><th>{{ $t('admin.common.date') }}</th><th>{{ $t('admin.supplier') }}</th><th>{{ $t('admin.imports_page.source') }}</th><th>{{ $t('admin.status') }}</th><th>{{ $t('admin.created') }}</th><th>{{ $t('admin.updated') }}</th><th>{{ $t('admin.errors') }}</th></tr></thead>
        <tbody>
          <template v-for="r in history" :key="r.id">
            <tr class="cursor-pointer" @click="expanded = expanded === r.id ? null : r.id">
              <td class="whitespace-nowrap text-ink-500">{{ formatDateTime(r.created_at, locale) }}</td>
              <td>{{ names[r.supplier_id] ?? '—' }}</td>
              <td>{{ r.source === 'cj' ? 'CJ' : 'CSV' }}</td>
              <td><StatusBadge :status="r.status" /></td>
              <td>{{ r.created_count }}</td>
              <td>{{ r.updated_count }}</td>
              <td :class="r.error_count ? 'font-semibold text-red-600' : ''">{{ r.error_count }}</td>
            </tr>
            <tr v-if="expanded === r.id && r.errors.length">
              <td colspan="7" class="bg-red-50/50">
                <ul class="list-disc space-y-1 ps-6 text-xs text-red-700"><li v-for="(e, i) in r.errors" :key="i" dir="ltr">{{ e.row ? `#${e.row}: ` : '' }}{{ e.errors.join('; ') }}</li></ul>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      <p v-if="!history.length" class="p-6 text-center text-ink-500">{{ $t('common.none') }}</p>
    </div>
  </section>
</template>
