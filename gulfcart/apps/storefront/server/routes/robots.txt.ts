export default defineEventHandler((event) => {
  const site = String(useRuntimeConfig(event).public.siteUrl).replace(/\/$/, '')
  setHeader(event, 'Content-Type', 'text/plain; charset=utf-8')
  return [
    'User-agent: *',
    'Disallow: /admin',
    'Disallow: /ar/admin',
    'Disallow: /account',
    'Disallow: /ar/account',
    'Disallow: /checkout',
    'Disallow: /ar/checkout',
    'Disallow: /cart',
    'Disallow: /ar/cart',
    'Disallow: /payment',
    'Disallow: /ar/payment',
    '',
    `Sitemap: ${site}/sitemap.xml`,
    '',
  ].join('\n')
})
