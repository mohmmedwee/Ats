<script setup lang="ts">
definePageMeta({ middleware: 'staff' })
interface Supplier { id: string, name: string, code: string, connector_type: string, commercial_authorization_status: string, is_active: boolean, authorizations: { agreement_reference: string, countries: string[], valid_until: string | null }[] }
const { request, csrf } = useApi()
const { t } = useI18n()
const suppliers = ref<Supplier[]>([])
const error = ref('')

async function load() {
  suppliers.value = (await request<{ data: Supplier[] }>('/admin/suppliers')).data
}

async function status(s: Supplier, action: 'activate' | 'deactivate') {
  error.value = ''
  try {
    await csrf()
    await request(`/admin/suppliers/${s.id}/status`, { method: 'POST', body: { action } })
    await load()
  }
  catch (e) {
    error.value = apiErrorMessage(e, t('errors.generic'))
  }
}

onMounted(load)
</script>

<template>
  <section>
    <AdminNav />
    <h1 class="mb-4 text-2xl font-semibold">{{ $t('admin.suppliers') }}</h1>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <table class="w-full text-sm">
      <tbody>
        <tr v-for="s in suppliers" :key="s.id" class="border-b">
          <td class="p-2">{{ s.name }} <span class="text-gray-500" dir="ltr">({{ s.code }}, {{ s.connector_type }})</span></td>
          <td><StatusBadge :status="s.commercial_authorization_status" /></td>
          <td>{{ s.authorizations.map(a => `${a.agreement_reference} ${a.countries.join('/')}`).join(', ') }}</td>
          <td>
            <button v-if="!s.is_active" class="underline" @click="status(s, 'activate')">{{ $t('admin.activate') }}</button>
            <button v-else class="underline" @click="status(s, 'deactivate')">{{ $t('admin.deactivate') }}</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
