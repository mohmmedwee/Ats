<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
interface ImportResult { id: string, status: string, total_rows: number, created_count: number, updated_count: number, error_count: number, errors: { row: number, errors: string[] }[] }
const { request, csrf } = useApi()
const { t } = useI18n()
const suppliers = ref<{ id: string, name: string }[]>([])
const supplierId = ref('')
const file = ref<File | null>(null)
const result = ref<ImportResult | null>(null)
const error = ref('')

onMounted(async () => {
  suppliers.value = (await request<{ data: { id: string, name: string }[] }>('/admin/suppliers')).data
  supplierId.value = suppliers.value[0]?.id ?? ''
})

async function upload() {
  if (!file.value || !supplierId.value) return
  error.value = ''
  const body = new FormData()
  body.append('supplier_id', supplierId.value)
  body.append('file', file.value)
  try {
    await csrf()
    const created = (await request<{ data: ImportResult }>('/admin/imports/products', { method: 'POST', body })).data
    for (let i = 0; i < 30; i++) {
      result.value = (await request<{ data: ImportResult }>(`/admin/imports/${created.id}`)).data
      if (['completed', 'failed'].includes(result.value.status)) break
      await new Promise(r => setTimeout(r, 1000))
    }
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}
</script>

<template>
  <section>
    <AdminNav />
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('admin.imports') }}</h1>
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="upload">
      <label class="text-sm">{{ $t('admin.supplier') }}
        <select v-model="supplierId" class="ms-2 rounded border px-2 py-1"><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
      </label>
      <input type="file" accept=".csv,text/csv" required @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null">
      <button class="rounded bg-gray-900 px-4 py-1 text-white">{{ $t('admin.upload') }}</button>
    </form>
    <p class="mt-2 text-xs text-gray-500" dir="ltr">external_product_id, external_variant_id, sku, title_en, title_ar, …, countries — docs/SUPPLIER_IMPORT.md</p>
    <p v-if="error" role="alert" class="mt-2 text-red-600">{{ error }}</p>
    <div v-if="result" class="mt-4 text-sm">
      <StatusBadge :status="result.status" />
      {{ $t('admin.rows') }}: {{ result.total_rows }} · {{ $t('admin.created') }}: {{ result.created_count }} · {{ $t('admin.updated') }}: {{ result.updated_count }} · {{ $t('admin.errors') }}: {{ result.error_count }}
      <ul class="mt-2 list-disc ps-6 text-red-700">
        <li v-for="e in result.errors" :key="e.row" dir="ltr">#{{ e.row }}: {{ e.errors.join('; ') }}</li>
      </ul>
    </div>
  </section>
</template>
