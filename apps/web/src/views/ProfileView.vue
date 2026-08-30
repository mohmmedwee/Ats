<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'

import { ApiError, api, type Fact, type ParseReport } from '@/api/client'
import ProvenanceBadge from '@/components/ProvenanceBadge.vue'

const queryClient = useQueryClient()
const report = ref<ParseReport | null>(null)
const uploadError = ref<string | null>(null)

const { data: profile, isPending } = useQuery({ queryKey: ['profile'], queryFn: api.profile })
const { data: resumes } = useQuery({ queryKey: ['resumes'], queryFn: api.resumes })
const { data: answers } = useQuery({ queryKey: ['answers'], queryFn: api.answers })

const invalidate = () => {
  void queryClient.invalidateQueries({ queryKey: ['profile'] })
  void queryClient.invalidateQueries({ queryKey: ['resumes'] })
}

const upload = useMutation({
  mutationFn: (file: File) => api.uploadResume(file),
  onMutate: () => {
    uploadError.value = null
    report.value = null
  },
  onSuccess: (result) => {
    report.value = result
    invalidate()
  },
  onError: (error) => {
    uploadError.value = error instanceof ApiError ? error.message : 'Upload failed'
  },
})

const reparse = useMutation({
  mutationFn: (id: string) => api.reparseResume(id),
  onSuccess: (result) => {
    report.value = result
    invalidate()
  },
})

const confirmFact = useMutation({
  mutationFn: (id: string) => api.confirmFact(id),
  onSuccess: invalidate,
})

const removeFact = useMutation({
  mutationFn: (id: string) => api.deleteFact(id),
  onSuccess: invalidate,
})

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (file) upload.mutate(file)
  input.value = ''
}

/** Group facts by kind so the page reads like a CV rather than a flat list. */
const grouped = computed(() => {
  const groups = new Map<string, Fact[]>()
  for (const fact of profile.value?.facts ?? []) {
    const bucket = groups.get(fact.kind) ?? []
    bucket.push(fact)
    groups.set(fact.kind, bucket)
  }
  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b))
})

const isLocked = (field: string) => profile.value?.locked_fields.includes(field) ?? false
const primaryResume = computed(() => resumes.value?.find((resume) => resume.is_primary))
</script>

<template>
  <section>
    <h1 class="text-xl font-semibold">Candidate profile</h1>
    <p class="mt-1 text-sm text-slate-600">
      Upload a CV and review what was read from it. Nothing here is used in an application until
      you confirm it.
    </p>

    <!-- upload ------------------------------------------------------------->
    <div class="mt-6 rounded-lg border border-slate-200 bg-white p-4">
      <label class="block text-sm font-medium" for="cv">CV (DOCX or PDF, up to 10 MB)</label>
      <input
        id="cv"
        type="file"
        accept=".docx,.pdf"
        class="mt-2 block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-sm file:text-white"
        :disabled="upload.isPending.value"
        @change="onFileChange"
      />
      <p v-if="upload.isPending.value" class="mt-2 text-sm text-slate-500">Reading the CV…</p>
      <p v-if="uploadError" class="mt-2 text-sm text-red-600">{{ uploadError }}</p>

      <div v-if="primaryResume" class="mt-3 flex items-center gap-3 text-sm text-slate-600">
        <span class="font-mono">{{ primaryResume.filename }}</span>
        <button
          class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
          :disabled="reparse.isPending.value"
          @click="reparse.mutate(primaryResume.id)"
        >
          Re-parse
        </button>
        <span v-if="primaryResume.parse_error" class="text-red-600">{{
          primaryResume.parse_error
        }}</span>
      </div>
    </div>

    <!-- parse report -------------------------------------------------------->
    <div v-if="report" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
      <p class="text-sm">
        Added {{ report.facts_added }}, kept {{ report.facts_kept }}, withdrew
        {{ report.facts_withdrawn }}.
      </p>
      <p v-if="report.error" class="mt-1 text-sm text-red-600">{{ report.error }}</p>
      <div v-if="report.rejected.length" class="mt-3">
        <p class="text-sm font-medium text-amber-800">
          Not kept — the CV does not contain these:
        </p>
        <ul class="mt-1 list-inside list-disc text-sm text-slate-600">
          <li v-for="claim in report.rejected" :key="`${claim.kind}-${claim.value}`">
            {{ claim.value }} <span class="text-slate-400">({{ claim.kind }})</span>
          </li>
        </ul>
      </div>
    </div>

    <!-- profile fields ------------------------------------------------------>
    <p v-if="isPending" class="mt-6 text-sm text-slate-500">Loading profile…</p>
    <template v-else-if="profile">
      <dl class="mt-8 grid gap-4 sm:grid-cols-3">
        <div
          v-for="field in ['headline', 'location', 'years_experience'] as const"
          :key="field"
          class="rounded-lg border border-slate-200 bg-white p-4"
        >
          <dt class="flex items-center gap-2 text-xs uppercase tracking-wide text-slate-500">
            {{ field.replace('_', ' ') }}
            <span v-if="isLocked(field)" class="text-slate-400" title="Edited by you; re-parsing will not change it">
              locked
            </span>
          </dt>
          <dd class="mt-1 text-sm">{{ profile[field] ?? '—' }}</dd>
        </div>
      </dl>

      <!-- facts ------------------------------------------------------------->
      <div v-for="[kind, facts] in grouped" :key="kind" class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
          {{ kind.replace('_', ' ') }}
        </h2>
        <ul class="mt-3 space-y-2">
          <li
            v-for="fact in facts"
            :key="fact.id"
            class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3"
          >
            <div class="flex items-start gap-3">
              <ProvenanceBadge :provenance="fact.provenance" />
              <span class="text-sm">{{ fact.value }}</span>
            </div>
            <div class="flex shrink-0 gap-2">
              <button
                v-if="fact.provenance !== 'user_confirmed'"
                class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
                @click="confirmFact.mutate(fact.id)"
              >
                Confirm
              </button>
              <button
                class="rounded-md border border-slate-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50"
                @click="removeFact.mutate(fact.id)"
              >
                Remove
              </button>
            </div>
          </li>
        </ul>
      </div>

      <p v-if="!grouped.length" class="mt-6 text-sm text-slate-500">
        No facts yet. Upload a CV to get started.
      </p>
    </template>

    <!-- answer bank --------------------------------------------------------->
    <div v-if="answers?.length" class="mt-10">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Answer bank</h2>
      <ul class="mt-3 space-y-2">
        <li
          v-for="answer in answers"
          :key="answer.id"
          class="rounded-lg border border-slate-200 bg-white p-3"
        >
          <div class="flex items-center gap-3">
            <ProvenanceBadge :provenance="answer.provenance" />
            <p class="text-sm font-medium">{{ answer.question }}</p>
          </div>
          <p class="mt-1 text-sm text-slate-600">{{ answer.answer }}</p>
        </li>
      </ul>
    </div>
  </section>
</template>
