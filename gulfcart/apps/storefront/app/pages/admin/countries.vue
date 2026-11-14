<script setup lang="ts">
definePageMeta({ middleware: 'staff', layout: 'admin' })
interface Country { code: string, name: Record<string, string> | string, currency: string, is_active: boolean, settings: { rounding?: string, returns?: { window_days?: number }, payment_methods?: string[], seller?: { legal_name?: string, vat_number?: string }, importer_of_record?: string } }
const { request, send, run, error, notice, tr } = useAdmin()
const { t } = useI18n()
const countries = ref<Country[]>([])
const forms = ref<Record<string, { is_active: boolean, rounding: string, window_days: number, payment_methods: string[], legal_name: string, vat_number: string, importer_of_record: string }>>({})
const methods = ['card', 'apple_pay', 'mada', 'google_pay', 'tabby', 'tamara']

async function load() {
  await run(async () => {
    countries.value = (await request<{ data: Country[] }>('/admin/countries')).data
    forms.value = Object.fromEntries(countries.value.map(c => [c.code, {
      is_active: c.is_active, rounding: c.settings.rounding ?? 'ceil_whole', window_days: c.settings.returns?.window_days ?? 14,
      payment_methods: c.settings.payment_methods ?? ['card'], legal_name: c.settings.seller?.legal_name ?? '', vat_number: c.settings.seller?.vat_number ?? '',
      importer_of_record: c.settings.importer_of_record ?? '',
    }]))
  })
}
async function save(code: string) {
  const f = forms.value[code]!
  const seller: Record<string, string> = {}
  if (f.legal_name) seller.legal_name = f.legal_name
  if (f.vat_number) seller.vat_number = f.vat_number
  await run(() => send('PATCH', `/admin/countries/${code}`, {
    is_active: f.is_active,
    settings: { rounding: f.rounding, returns: { window_days: Number(f.window_days) }, payment_methods: f.payment_methods, ...(Object.keys(seller).length ? { seller } : {}), ...(f.importer_of_record ? { importer_of_record: f.importer_of_record } : {}) },
  }), t('admin.common.saved'))
  await load()
}
onMounted(load)
</script>

<template>
  <section>
    <AdminHeader :title="$t('admin.countries.title')" :subtitle="$t('admin.countries.subtitle')" />
    <AdminAlerts :error="error" :notice="notice" />
    <div class="grid gap-6 lg:grid-cols-2">
      <form v-for="c in countries" :key="c.code" class="card space-y-4 p-5" @submit.prevent="save(c.code)">
        <template v-if="forms[c.code]">
          <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold">{{ tr(c.name as never) || $t(`country.${c.code}`) }} <span class="text-sm font-normal text-ink-500">{{ c.code }} · {{ c.currency }}</span></h2>
            <label class="flex items-center gap-2 text-sm"><input v-model="forms[c.code]!.is_active" type="checkbox"> {{ $t('admin.common.active') }}</label>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <label><span class="label">{{ $t('admin.countries.rounding') }}</span>
              <select v-model="forms[c.code]!.rounding" class="input"><option v-for="r in ['ceil_whole', 'ceil_x9', 'ceil_0_05', 'ceil_cent']" :key="r" :value="r">{{ $t(`admin.countries.roundings.${r}`) }}</option></select>
            </label>
            <label><span class="label">{{ $t('admin.countries.return_days') }}</span><input v-model.number="forms[c.code]!.window_days" class="input" dir="ltr"></label>
            <label class="sm:col-span-2"><span class="label">{{ $t('admin.countries.seller') }}</span><input v-model="forms[c.code]!.legal_name" class="input"></label>
            <label><span class="label">{{ $t('admin.countries.vat_number') }}</span><input v-model="forms[c.code]!.vat_number" class="input" dir="ltr"></label>
            <label><span class="label">{{ $t('admin.countries.ior') }}</span><input v-model="forms[c.code]!.importer_of_record" class="input"></label>
          </div>
          <div>
            <span class="label">{{ $t('admin.countries.payment_methods') }}</span>
            <div class="flex flex-wrap gap-3 text-sm"><label v-for="m in methods" :key="m" class="flex items-center gap-1"><input v-model="forms[c.code]!.payment_methods" type="checkbox" :value="m"> {{ $t(`admin.countries.methods.${m}`) }}</label></div>
          </div>
          <div class="flex justify-end"><button class="btn-primary">{{ $t('common.save') }}</button></div>
        </template>
      </form>
    </div>
  </section>
</template>
