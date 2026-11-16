<script setup lang="ts">
import type { Map as LMap, Marker } from 'leaflet'
import 'leaflet/dist/leaflet.css'

/**
 * Optional delivery pin: tap the map (or use the phone's location) and drag to adjust.
 * OpenStreetMap tiles, no API key. The courier gets a Google Maps link to the exact spot.
 */
const props = defineProps<{ modelValue: { lat: number, lng: number } | null, country?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: { lat: number, lng: number } | null] }>()
const el = ref<HTMLElement | null>(null)
const locating = ref(false)
const failed = ref(false)
let map: LMap | null = null
let marker: Marker | null = null
let L: typeof import('leaflet') | null = null

const CENTERS: Record<string, [number, number]> = { AE: [25.2048, 55.2708], SA: [24.7136, 46.6753], KW: [29.3759, 47.9774], QA: [25.2854, 51.531], BH: [26.2285, 50.586], OM: [23.588, 58.3829] }

function place(lat: number, lng: number, zoom = false) {
  if (!map || !L) return
  const at: [number, number] = [Number(lat.toFixed(6)), Number(lng.toFixed(6))]
  if (!marker) {
    marker = L.marker(at, {
      draggable: true,
      icon: L.divIcon({ className: '', html: '<span style="display:block;width:22px;height:22px;border-radius:50% 50% 50% 0;background:#14121A;transform:rotate(-45deg);border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)"></span>', iconSize: [22, 22], iconAnchor: [11, 22] }),
    }).addTo(map)
    marker.on('dragend', () => {
      const p = marker!.getLatLng()
      emit('update:modelValue', { lat: Number(p.lat.toFixed(6)), lng: Number(p.lng.toFixed(6)) })
    })
  }
  else {
    marker.setLatLng(at)
  }
  if (zoom) map.setView(at, 17)
  emit('update:modelValue', { lat: at[0], lng: at[1] })
}

onMounted(async () => {
  L = await import('leaflet')
  if (!el.value) return
  const start = props.modelValue ? [props.modelValue.lat, props.modelValue.lng] as [number, number] : CENTERS[props.country ?? 'AE'] ?? CENTERS.AE!
  map = L.map(el.value, { zoomControl: true, attributionControl: true }).setView(start, props.modelValue ? 17 : 11)
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map)
  if (props.modelValue) place(props.modelValue.lat, props.modelValue.lng)
  map.on('click', e => place(e.latlng.lat, e.latlng.lng))
})
onBeforeUnmount(() => map?.remove())

function locate() {
  if (!navigator.geolocation) {
    failed.value = true
    return
  }
  locating.value = true
  failed.value = false
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      locating.value = false
      place(pos.coords.latitude, pos.coords.longitude, true)
    },
    () => {
      locating.value = false
      failed.value = true
    },
    { enableHighAccuracy: true, timeout: 10000 },
  )
}
function clear() {
  marker?.remove()
  marker = null
  emit('update:modelValue', null)
}
</script>

<template>
  <div class="space-y-2" data-test="map-pin">
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" class="btn-outline btn-sm" :disabled="locating" data-test="map-locate" @click="locate"><AppIcon name="globe" :size="14" /> {{ locating ? $t('map.locating') : $t('map.use_location') }}</button>
      <button v-if="modelValue" type="button" class="text-xs underline" @click="clear">{{ $t('map.clear') }}</button>
      <span class="text-xs text-ink-500">{{ modelValue ? $t('map.pinned') : $t('map.hint') }}</span>
    </div>
    <p v-if="failed" class="text-xs text-red-600">{{ $t('map.failed') }}</p>
    <div ref="el" class="z-0 h-56 w-full overflow-hidden rounded-md border border-ink-100" />
  </div>
</template>
