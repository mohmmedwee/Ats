# Payments

| Provider | Use | Setting |
|---|---|---|
| Sandbox | Development and staging: a fake payment page with "pay" and "fail" buttons | `PAYMENT_PROVIDER=sandbox` (refused in production) |
| **Tap Payments** | Production in the UAE and Saudi Arabia: Visa/Mastercard, **mada**, **Apple Pay**, Google Pay | `PAYMENT_PROVIDER=tap` |

## How a payment works with Tap

1. The customer places the order and is redirected to Tap's hosted page. It shows all methods enabled
   on the Tap account, so card numbers never touch GulfCart (PCI SAQ-A).
2. Tap redirects back to `/payment/return`. The page waits a few seconds for confirmation.
3. Tap posts the result to `https://API-DOMAIN/api/v1/webhooks/payments/tap`. GulfCart checks:
   - the `hashstring` signature, made with the secret key;
   - for a capture, the status, amount and currency again from Tap's API;
   - that each result is applied only once.
4. Only after this check is the order marked as paid. Purchasing from the supplier then starts.

Refunds, including partial ones and four-eyes approvals above 500, are sent to Tap from the admin
panel. The final result arrives by webhook.

## Setup

1. Open a Tap merchant account. Tap needs the trade licence (or commercial registration), bank
   details, the website with the policy pages, and the Arabic and English business name.
2. In the Tap dashboard, enable cards, **mada** (Saudi Arabia) and **Apple Pay**. For Apple Pay,
   complete the **domain verification** Tap asks for: a file served by the storefront domain.
3. Copy the **secret key**: use `sk_test_…` first, and `sk_live_…` after Tap approves the account.
4. Set on the API server:
   ```env
   PAYMENT_PROVIDER=tap
   TAP_SECRET_KEY=sk_test_...
   TAP_WEBHOOK_BASE_URL=https://api.your-domain.com   # must be reachable from the internet
   PAYMENT_FEE_PERCENT=2.5                           # your Tap rate, used in pricing
   ```
5. Test with Tap's test cards: one successful payment, one declined payment and one refund. Check that
   the order shows **Paid**, and that the refund appears in Admin → Refunds.

## Still to verify on the first live test

The Tap integration follows Tap's v2 API and is covered by tests against its documented request and
response shapes, but has not run against a real Tap account yet:

- [ ] The webhook `hashstring` matches for charges and for refunds.
- [ ] The refund webhook arrives (refund IDs start with `re_`).
- [ ] Apple Pay appears on iPhone Safari after domain verification.

Tabby and Tamara (buy now, pay later) can be added as additional gateways later; their contracts are
separate from Tap.
