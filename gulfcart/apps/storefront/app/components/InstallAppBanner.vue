<script setup lang="ts">
/**
 * "Add GulfCart to your home screen" on phones. Android/Chrome uses the browser's install prompt;
 * iPhone shows the Share → Add to Home Screen hint. Hidden once installed or for 14 days after closing.
 */
interface InstallEvent extends Event { prompt: () => Promise<void>, userChoice: Promise<{ outcome: string }> }
const KEY = 'gc_install_dismissed'
const deferred = shallowRef<InstallEvent | null>(null)
const ios = ref(false)
const show = ref(false)

function dismissedRecently() {
  try {
    return Date.now() - Number(localStorage.getItem(KEY) ?? 0) < 14 * 86400000
  }
  catch {
    return true
  }
}
onMounted(() => {
  const standalone = window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone
  if (standalone || dismissedRecently() || window.innerWidth >= 768) return
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    deferred.value = e as InstallEvent
    show.value = true
  })
  ios.value = /iphone|ipad|ipod/i.test(navigator.userAgent) && /safari/i.test(navigator.userAgent) && !/crios|fxios/i.test(navigator.userAgent)
  if (ios.value) setTimeout(() => (show.value = true), 4000)
})
function close() {
  show.value = false
  try {
    localStorage.setItem(KEY, String(Date.now()))
  }
  catch { /* storage unavailable */ }
}
async function install() {
  if (!deferred.value) return
  await deferred.value.prompt()
  await deferred.value.userChoice.catch(() => undefined)
  deferred.value = null
  close()
}
</script>

<template>
  <Transition enter-from-class="translate-y-full opacity-0" enter-active-class="transition duration-300" leave-to-class="translate-y-full opacity-0" leave-active-class="transition duration-200">
    <div v-if="show" class="fixed inset-x-2 bottom-[68px] z-40 flex items-center gap-3 rounded-xl bg-ink-900 p-3 text-white shadow-2xl md:hidden" role="dialog" :aria-label="$t('pwa.title')" data-test="install-banner">
      <img src="/icon-192.png" alt="" class="h-11 w-11 rounded-lg">
      <div class="min-w-0 flex-1">
        <p class="text-sm font-bold">{{ $t('pwa.title') }}</p>
        <p class="text-xs opacity-80">{{ ios && !deferred ? $t('pwa.ios') : $t('pwa.text') }}</p>
      </div>
      <button v-if="deferred" class="rounded-md bg-white px-3 py-2 text-xs font-bold text-ink-900" @click="install">{{ $t('pwa.install') }}</button>
      <button class="p-1 opacity-70" :aria-label="$t('common.close')" @click="close"><AppIcon name="x" :size="18" /></button>
    </div>
  </Transition>
</template>
