<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'

import { api, type MatchDetail } from '@/api/client'
import MatchBreakdown from '@/components/MatchBreakdown.vue'
import ScoreBar from '@/components/ScoreBar.vue'

const queryClient = useQueryClient()
const routing = ref('')
const includeRejected = ref(false)
const expanded = ref<string | null>(null)
const details = ref<Record<string, MatchDetail>>({})

const filters = computed(() => ({
  routing: routing.value || undefined,
  include_rejected: includeRejected.value,
}))

const { data, isPending, isError } = useQuery({
  queryKey: ['matches', filters],
  queryFn: () => api.matches(filters.value),
})

const runScoring = useMutation({
  mutationFn: () => api.runScoring(false),
  onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['matches'] }),
})

const shortlist = useMutation({
  mutationFn: (jobId: string) => api.shortlist(jobId),
  onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['matches'] }),
})

/** Details are fetched on expand: the queue should stay fast with 200 rows. */
async function toggle(jobId: string) {
  if (expanded.value === jobId) {
    expanded.value = null
    return
  }
  expanded.value = jobId
  if (!details.value[jobId]) {
    details.value[jobId] = await api.matchDetail(jobId)
  }
}

const counts = computed(() => Object.entries(data.value?.counts_by_routing ?? {}))
</script>

<template>
  <section>
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold">Review queue</h1>
        <p class="mt-1 text-sm text-slate-600">
          Ranked against your profile. Every number below is computed from your verified facts and
          the posting text — no model sets a score.
        </p>
      </div>
      <button
        class="shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white disabled:opacity-50"
        :disabled="runScoring.isPending.value"
        @click="runScoring.mutate()"
      >
        {{ runScoring.isPending.value ? 'Scoring…' : 'Score all jobs' }}
      </button>
    </div>

    <p v-if="runScoring.data.value" class="mt-3 text-sm text-slate-600">
      Scored {{ runScoring.data.value.scored }}, reused
      {{ runScoring.data.value.reused }} unchanged.
    </p>

    <div class="mt-6 flex flex-wrap items-end gap-3">
      <label class="text-sm">
        <span class="block text-xs uppercase tracking-wide text-slate-500">Routing</span>
        <select v-model="routing" class="mt-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm">
          <option value="">Review queue</option>
          <option value="high_priority">High priority</option>
          <option value="normal_review">Normal review</option>
          <option value="possible_match">Possible match</option>
          <option value="archived">Archived</option>
          <option value="rejected">Rejected</option>
        </select>
      </label>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="includeRejected" type="checkbox" />
        Include rejected and archived
      </label>
      <p v-if="counts.length" class="text-xs text-slate-500">
        <span v-for="[name, count] in counts" :key="name" class="mr-3">
          {{ name.replace('_', ' ') }}: {{ count }}
        </span>
      </p>
    </div>

    <p v-if="isPending" class="mt-6 text-sm text-slate-500">Loading matches…</p>
    <p v-else-if="isError" class="mt-6 text-sm text-red-600">Could not reach the API.</p>
    <template v-else-if="data">
      <p v-if="!data.items.length" class="mt-6 text-sm text-slate-500">
        Nothing scored yet. Add a source, run discovery, then score the jobs.
      </p>

      <ul class="mt-4 space-y-3">
        <li
          v-for="match in data.items"
          :key="match.match_id"
          class="rounded-lg border border-slate-200 bg-white p-4"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-sm font-medium">
                <a
                  :href="match.application_url"
                  target="_blank"
                  rel="noopener"
                  class="hover:underline"
                  >{{ match.title }}</a
                >
              </h2>
              <p class="text-xs text-slate-500">
                {{ match.company }}
                <span v-if="match.location"> · {{ match.location }}</span>
                <span v-if="match.remote_type !== 'unknown'"> · {{ match.remote_type }}</span>
              </p>
            </div>
            <ScoreBar :score="match.score" :routing="match.routing" />
          </div>

          <p v-if="match.blocker_reasons.length" class="mt-2 text-sm text-red-700">
            {{ match.blocker_reasons.join('; ') }}
          </p>
          <p v-else-if="match.top_strengths.length" class="mt-2 text-xs text-slate-600">
            Matches: {{ match.top_strengths.join(' · ') }}
            <span v-if="match.top_gaps.length" class="text-slate-500">
              — missing {{ match.top_gaps.join(' · ') }}
            </span>
          </p>

          <p v-if="match.injection_flagged" class="mt-2 text-xs text-amber-800">
            This posting tries to give the agent instructions. It is treated as data.
          </p>

          <div class="mt-3 flex gap-2">
            <button
              class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
              @click="toggle(match.job_id)"
            >
              {{ expanded === match.job_id ? 'Hide breakdown' : 'Why this score' }}
            </button>
            <button
              v-if="!match.shortlisted"
              class="rounded-md border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50"
              @click="shortlist.mutate(match.job_id)"
            >
              Shortlist
            </button>
            <span v-else class="self-center text-xs text-emerald-700">Shortlisted</span>
          </div>

          <MatchBreakdown
            v-if="expanded === match.job_id && details[match.job_id]"
            :detail="details[match.job_id]"
          />
        </li>
      </ul>
    </template>
  </section>
</template>
