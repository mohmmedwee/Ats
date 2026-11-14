// k6 load test: concurrent cart → checkout quote → checkout session (write path, row locks,
// live supplier stock check). Uses token auth. Run against STAGING with demo data and the mock
// supplier only — never production.
// Run: k6 run -e BASE_URL=https://staging.gulfcart.example/api/v1 infrastructure/load/checkout.js
// Target: p95 checkout quote < 800 ms, no 5xx.
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost/api/v1';
export const options = {
  vus: 50,
  duration: '3m',
  thresholds: { http_req_failed: ['rate<0.01'], 'http_req_duration{kind:quote}': ['p(95)<800'] },
};

const json = (token, extra = {}) => ({ headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-Country': 'AE', Authorization: `Bearer ${token}` }, extra) });

export function setup() {
  const products = http.get(`${BASE}/products?limit=10`, { headers: { Accept: 'application/json' } }).json('data');
  const variants = products.map(p => http.get(`${BASE}/products/${p.id}/variants`, { headers: { Accept: 'application/json' } }).json('data')[0].id);
  return { variants };
}

export default function ({ variants }) {
  const email = `load-${__VU}-${__ITER}-${Date.now()}@example.test`;
  const reg = http.post(`${BASE}/auth/register`, JSON.stringify({ name: 'Load Test', email, password: 'Load-test-pass-1', password_confirmation: 'Load-test-pass-1', device_name: 'k6' }), { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } });
  if (!check(reg, { 'registered': r => r.status === 201 })) return;
  const token = reg.json('data.token');

  http.post(`${BASE}/cart/items`, JSON.stringify({ variant_id: variants[__ITER % variants.length], quantity: 1 }), json(token));
  const address = http.post(`${BASE}/addresses`, JSON.stringify({ country: 'AE', full_name: 'Load Test', phone: '0501234567', address_line_1: 'Villa 1', city: 'Dubai', state: 'DXB' }), json(token)).json('data.id');
  const quote = http.post(`${BASE}/checkout/quote`, JSON.stringify({ address_id: address, shipping_method: 'standard' }), Object.assign(json(token), { tags: { kind: 'quote' } }));
  check(quote, { 'quote 200': r => r.status === 200 });
  const session = http.post(`${BASE}/checkout`, JSON.stringify({ address_id: address, shipping_method: 'standard' }), json(token));
  check(session, { 'session 201': r => r.status === 201 });
}
