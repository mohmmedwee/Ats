const { chromium } = require('playwright');
const crypto = require('crypto');
// Smoke test of the full journey against a running stack (local or staging with the sandbox
// gateway and demo data). Usage:
//   BASE_URL=http://localhost:3000 ADMIN_EMAIL=admin@gulfcart.local ADMIN_PASSWORD=ChangeMe-123 \
//   node e2e/smoke-journey.cjs ./e2e-output
// Requires: playwright, the demo catalog (php artisan db:seed --class=DemoCatalogSeeder), a queue
// worker, and a dev admin without MFA enrolled yet.
const out = process.argv[2] || '.';
const BASE = process.env.BASE_URL || 'http://localhost:3000';
const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@gulfcart.local';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'ChangeMe-123';
const log = (k, v) => console.log(`${k}: ${v}`);

function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secret.replace(/=+$/, '')) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = crypto.createHmac('sha1', key).update(counter).digest();
  const o = h[h.length - 1] & 0xf;
  return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0');
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  page.on('pageerror', e => console.log('PAGEERROR', e.message));

  // 1. Browse as a guest in Arabic and add to cart
  await page.goto(BASE + '/ar/products');
  await page.waitForSelector('[data-test=product-card]');
  log('ar products dir', await page.getAttribute('html', 'dir'));
  log('ar cards', await page.locator('[data-test=product-card]').count());
  await page.screenshot({ path: `${out}/products-ar.png` });
  await page.locator('[data-test=product-card]').first().click();
  await page.waitForSelector('[data-test=product-title]');
  log('product', await page.textContent('[data-test=product-title]'));
  log('price', (await page.textContent('[data-test=product-price]')).trim());
  await page.screenshot({ path: `${out}/product-ar.png` });
  await page.click('[data-test=add-to-cart]');
  await page.waitForSelector('[data-test=cart-message]');
  log('add message', (await page.textContent('[data-test=cart-message]')).trim());
  log('cart badge', (await page.textContent('[data-test=nav-cart]')).trim());

  // 2. Switch to English, cart → login redirect → register → merged cart
  await page.goto(BASE + '/cart');
  await page.waitForSelector('[data-test=cart-line]');
  log('cart subtotal', (await page.textContent('[data-test=cart-subtotal]')).trim());
  await page.click('text=Log in to check out');
  await page.waitForURL('**/login**');
  await page.click('text=Create account');
  await page.waitForURL('**/register**');
  const email = `shopper${Date.now()}@example.com`;
  const inputs = page.locator('main form input');
  await inputs.nth(0).fill('Test Shopper'); await inputs.nth(1).fill(email);
  await inputs.nth(3).fill('Secret-pass-1'); await inputs.nth(4).fill('Secret-pass-1');
  await page.click('main form button');
  await page.waitForURL('**/account');
  await page.goto(BASE + '/cart');
  await page.waitForSelector('[data-test=cart-line]');
  log('cart after signup (merged)', await page.locator('[data-test=cart-line]').count());

  // 3. Checkout: address, shipping, quote, place order
  await page.click('[data-test=go-checkout]');
  await page.waitForSelector('[data-test=address-form]');
  const f = page.locator('[data-test=address-form]');
  await f.locator('[name=full_name]').fill('Test Shopper');
  await f.locator('[name=phone]').fill('050 123 4567');
  await f.locator('[name=address_line_1]').fill('Villa 12, Street 5');
  await f.locator('[name=city]').fill('Dubai');
  await f.locator('[name=state]').selectOption('DXB');
  await f.locator('button').click();
  await page.waitForSelector('[data-test=quote-total]');
  log('quote total', (await page.textContent('[data-test=quote-total]')).trim());
  await page.screenshot({ path: `${out}/checkout-en.png` });
  await page.click('[data-test=place-order]');

  // 4. Sandbox payment page → pay
  await page.waitForURL('**/payment/return**');
  await page.waitForSelector('[data-test=sandbox-pay]');
  await page.click('[data-test=sandbox-pay]');
  await page.waitForSelector('[data-test=payment-success]');
  log('payment', (await page.textContent('[data-test=payment-success]')).trim());
  await page.click('text=View order');
  await page.waitForSelector('[data-test=package]');
  await page.waitForTimeout(2500); // worker purchases from the mock supplier
  await page.reload();
  await page.waitForSelector('[data-test=package]');
  log('order header', (await page.textContent('h1')).trim());
  log('order status', (await page.locator('section span.rounded').first().textContent()).trim());
  await page.screenshot({ path: `${out}/order-en.png` });

  // 5. Admin: dev super admin enrols in MFA and sees the dashboard
  await page.click('text=Log out');
  await page.waitForTimeout(500);
  await page.goto(BASE + '/login');
  await page.waitForLoadState('networkidle');
  await page.locator('main form input').nth(0).fill(ADMIN_EMAIL);
  await page.locator('main form input').nth(1).fill(ADMIN_PASSWORD);
  await page.click('main form button');
  await page.waitForURL('**/mfa?setup=1');
  await page.waitForLoadState('networkidle');
  await page.click('text=Generate key');
  const secret = (await page.textContent('[data-test=mfa-secret]')).trim();
  await page.locator('main form input').fill(totp(secret));
  await page.click('main form button');
  await page.waitForSelector('text=Save your recovery codes');
  log('recovery codes', await page.locator('ul li').count());
  await page.goto(BASE + '/admin');
  await page.waitForSelector('[data-test=kpi-exceptions]');
  log('admin exceptions', (await page.textContent('[data-test=kpi-exceptions]')).trim());
  await page.screenshot({ path: `${out}/admin-en.png` });
  await page.goto(BASE + '/admin/procurement?x=1');
  await page.waitForTimeout(1000);
  log('procurement open rows', await page.locator('[data-test=procurement-row]').count());
  await browser.close();
})().catch(e => { console.error('E2E FAILED:', e.message); process.exit(1); });
