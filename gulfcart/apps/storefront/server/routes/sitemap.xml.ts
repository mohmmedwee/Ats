interface Urls { products: { slug: string, updated_at: string | null }[], categories: string[] }

/** Bilingual sitemap (English default URLs + /ar alternates) built from the API. */
export default defineCachedEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const site = String(config.public.siteUrl).replace(/\/$/, '')
  const api = String(config.apiInternal).replace(/\/$/, '')
  const [urls, pages] = await Promise.all([
    $fetch<{ data: Urls }>(`${api}/seo/urls`).then(r => r.data).catch(() => ({ products: [], categories: [] })),
    $fetch<{ data: { slug: string }[] }>(`${api}/pages`).then(r => r.data).catch(() => []),
  ])
  const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;')
  const entry = (path: string, lastmod?: string | null) => {
    const en = `${site}${path}`
    const ar = `${site}/ar${path === '/' ? '' : path}`
    return `<url><loc>${esc(en)}</loc>${lastmod ? `<lastmod>${lastmod.slice(0, 10)}</lastmod>` : ''}`
      + `<xhtml:link rel="alternate" hreflang="en" href="${esc(en)}"/><xhtml:link rel="alternate" hreflang="ar" href="${esc(ar)}"/></url>`
      + `<url><loc>${esc(ar)}</loc>${lastmod ? `<lastmod>${lastmod.slice(0, 10)}</lastmod>` : ''}`
      + `<xhtml:link rel="alternate" hreflang="en" href="${esc(en)}"/><xhtml:link rel="alternate" hreflang="ar" href="${esc(ar)}"/></url>`
  }
  const body = [
    entry('/'),
    entry('/products'),
    entry('/categories'),
    entry('/brands'),
    ...urls.categories.map(c => entry(`/products?category=${encodeURIComponent(c)}`)),
    ...pages.map(p => entry(`/info/${p.slug}`)),
    ...urls.products.map(p => entry(`/products/${p.slug}`, p.updated_at)),
  ].join('')
  setHeader(event, 'Content-Type', 'application/xml; charset=utf-8')
  return `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">${body}</urlset>`
}, { maxAge: 3600, name: 'sitemap' })
