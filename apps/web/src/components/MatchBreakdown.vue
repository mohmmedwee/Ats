<script setup lang="ts">
import { computed } from 'vue'

import type { MatchDetail } from '@/api/client'

const props = defineProps<{ detail: MatchDetail }>()

const dimensions = computed(() =>
  Object.entries(props.detail.breakdown).sort(([, a], [, b]) => b.contribution - a.contribution),
)

const matched = computed(() =>
  props.detail.evidence.filter((item) => item.kind === 'matched_requirement'),
)
const missing = computed(() =>
  props.detail.evidence.filter((item) => item.kind === 'missing_requirement'),
)
const uncertain = computed(() => props.detail.evidence.filter((item) => item.kind === 'uncertain'))
</script>

<template>
  <div class="mt-3 border-t border-slate-200 pt-3">
    <!-- why it was rejected, first: it decides everything else ------------->
    <div v-if="detail.hard_blockers.length" class="mb-4 rounded-md bg-red-50 p-3">
      <p class="text-xs font-semibold uppercase tracking-wide text-red-800">Rejected because</p>
      <ul class="mt-1 space-y-1 text-sm text-red-800">
        <li v-for="blocker in detail.hard_blockers" :key="blocker.rule">
          {{ blocker.reason }}
        </li>
      </ul>
    </div>

    <dl class="space-y-1.5">
      <div v-for="[name, dimension] in dimensions" :key="name" class="flex items-center gap-3">
        <dt class="w-40 shrink-0 text-xs text-slate-600">{{ name.replace(/_/g, ' ') }}</dt>
        <dd class="flex flex-1 items-center gap-2">
          <div class="h-1 w-20 overflow-hidden rounded-full bg-slate-200">
            <div class="h-full bg-slate-700" :style="{ width: `${dimension.score * 100}%` }" />
          </div>
          <span class="text-xs tabular-nums text-slate-500">
            +{{ dimension.contribution.toFixed(1) }} of
            {{ (dimension.weight * 100).toFixed(0) }}
          </span>
          <span class="text-xs text-slate-400">{{ dimension.detail }}</span>
        </dd>
      </div>
    </dl>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <div v-if="matched.length">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Matched, with evidence
        </p>
        <ul class="mt-1 space-y-1 text-sm">
          <li v-for="item in matched" :key="item.requirement" class="text-slate-700">
            {{ item.requirement }}
            <span class="font-mono text-xs text-slate-400">{{ item.reference }}</span>
          </li>
        </ul>
      </div>
      <div v-if="missing.length">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Missing</p>
        <ul class="mt-1 space-y-1 text-sm">
          <li v-for="item in missing" :key="item.requirement" class="text-slate-700">
            {{ item.requirement }}
          </li>
        </ul>
      </div>
    </div>

    <div v-if="uncertain.length" class="mt-3">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Worth asking about</p>
      <ul class="mt-1 space-y-1 text-sm text-slate-700">
        <li v-for="item in uncertain" :key="item.requirement">{{ item.requirement }}</li>
      </ul>
    </div>

    <div v-if="detail.explanation" class="mt-4 rounded-md bg-slate-50 p-3">
      <p class="text-sm text-slate-700">{{ detail.explanation }}</p>
      <ul
        v-if="detail.explanation_data.questions_to_ask?.length"
        class="mt-2 list-inside list-disc text-sm text-slate-600"
      >
        <li v-for="question in detail.explanation_data.questions_to_ask" :key="question">
          {{ question }}
        </li>
      </ul>
    </div>

    <p class="mt-3 font-mono text-xs text-slate-400">
      inputs {{ detail.inputs_hash.slice(0, 12) }}
      <span v-if="detail.semantic_similarity !== null">
        · similarity {{ (detail.semantic_similarity * 100).toFixed(0) }}%
      </span>
    </p>
  </div>
</template>
