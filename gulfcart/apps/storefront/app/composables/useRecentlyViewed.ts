import type { ProductCard } from '~/types/api'

const KEY = 'gc_recent'
const MAX = 12

/** Products the visitor opened recently, kept in this browser only. */
export function useRecentlyViewed() {
  const items = useState<ProductCard[]>('recently-viewed', () => [])
  const loaded = useState('recently-viewed-loaded', () => false)

  if (import.meta.client && !loaded.value) {
    loaded.value = true
    try {
      items.value = JSON.parse(localStorage.getItem(KEY) ?? '[]') as ProductCard[]
    }
    catch {
      items.value = []
    }
  }

  function add(p: ProductCard) {
    items.value = [p, ...items.value.filter(x => x.id !== p.id)].slice(0, MAX)
    try {
      localStorage.setItem(KEY, JSON.stringify(items.value))
    }
    catch {
      // storage unavailable: keep in memory only
    }
  }

  return { items, add }
}
