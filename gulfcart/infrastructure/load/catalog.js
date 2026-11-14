// k6 load test: storefront catalog browsing (read path, cached country config, pg_trgm search).
// Run: k6 run -e BASE_URL=https://staging.gulfcart.example/api/v1 infrastructure/load/catalog.js
// Targets (docs/PRD.md §6): p95 < 300 ms, error rate < 1%.
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost/api/v1';

export const options = {
  scenarios: {
    browse: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '1m', target: 50 }, { duration: '3m', target: 200 }, { duration: '1m', target: 0 }] },
  },
  thresholds: { http_req_failed: ['rate<0.01'], 'http_req_duration{kind:catalog}': ['p(95)<300'] },
};

const countries = ['AE', 'SA'];
const terms = ['dress', 'bag', 'فستان', 'candle', 'leather'];

export default function () {
  const headers = { 'X-Country': countries[__VU % 2], 'Accept-Language': __VU % 3 === 0 ? 'ar' : 'en', Accept: 'application/json' };
  const list = http.get(`${BASE}/products?limit=24`, { headers, tags: { kind: 'catalog' } });
  check(list, { 'listing 200': r => r.status === 200 });
  const items = list.json('data') || [];
  if (items.length) {
    const slug = items[Math.floor(Math.random() * items.length)].slug;
    check(http.get(`${BASE}/products/${slug}`, { headers, tags: { kind: 'catalog' } }), { 'detail 200': r => r.status === 200 });
  }
  check(http.get(`${BASE}/products/search?q=${encodeURIComponent(terms[__ITER % terms.length])}`, { headers, tags: { kind: 'catalog' } }), { 'search 200': r => r.status === 200 });
  sleep(1);
}
