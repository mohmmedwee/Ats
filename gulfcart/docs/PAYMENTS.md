# Payments

| Provider | Use | Setting |
|---|---|---|
| Sandbox | Development and staging: a fake payment page with "pay" and "fail" buttons | `PAYMENT_PROVIDER=sandbox` (refused in production) |
| **Tap Payments** | Production in the UAE and Saudi Arabia: Visa/Mastercard, **mada**, **Apple Pay**, Google Pay | `PAYMENT_PROVIDER=tap` |
| **PayPal** | Optional second choice at checkout, charged in **USD** | `PAYMENT_METHODS=tap,paypal` |

When `PAYMENT_METHODS` lists more than one provider, the customer chooses at checkout. Each payment
remembers its provider, so webhooks and refunds always go back to the gateway that took the money.

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

## PayPal

**Currency.** PayPal does not settle in AED or SAR, so orders are charged in US dollars at the fixed
pegs: 1 USD = 3.6725 AED = 3.75 SAR. Checkout shows the dollar amount before the customer pays.
- The original AED/SAR total is stored with the PayPal order, and every capture is checked against it.
- Refunds are converted the same way, rounded down so they never exceed what was captured.
- PayPal's currency conversion fee is about 3–4% on top of its normal fee.
- If most buyers use PayPal, raise `PAYMENT_FEE_PERCENT` so prices cover the fees.

**Flow:**
1. The customer approves on PayPal.
2. The customer is sent back to `/payment/return`, and the store captures the payment.
3. If the customer closes the browser first, the `CHECKOUT.ORDER.APPROVED` webhook captures it instead.
4. Each capture is applied once, even though the return page and the webhook both report it.

**Setup:**
1. Open a PayPal **Business** account. PayPal Business is available to companies in the UAE and Saudi
   Arabia; withdrawals go to a local bank account.
2. At developer.paypal.com → Apps & Credentials, create an app. Copy the **Client ID** and **Secret**:
   Sandbox first, Live later.
3. In the same app, add a **webhook** with URL `https://API-DOMAIN/api/v1/webhooks/payments/paypal` and
   these events:
   - `CHECKOUT.ORDER.APPROVED`
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.DENIED`
   - `PAYMENT.CAPTURE.REFUNDED`

   Then copy the **Webhook ID**.
4. Set on the API server:
   ```env
   PAYMENT_METHODS=tap,paypal
   PAYPAL_MODE=sandbox            # live after testing
   PAYPAL_CLIENT_ID=...
   PAYPAL_CLIENT_SECRET=...
   PAYPAL_WEBHOOK_ID=...
   ```
5. Test with a PayPal sandbox buyer account:
   - one payment;
   - one cancelled payment, which must leave the order unpaid;
   - one partial refund.

Not yet run against a real PayPal account; covered by tests against PayPal's documented API shapes.

Tabby and Tamara (buy now, pay later) can be added as additional gateways later; their contracts are
separate from Tap.

## Cash on delivery (optional, off by default)

Turn it on in `deploy/api.env`:

```
COD_ENABLED=true
COD_COUNTRIES=AE,SA          # where it is offered
COD_FEE_AE=10                # VAT-inclusive fee added to the order (like delivery)
COD_FEE_SA=10
COD_MAX_AE=500               # order total limit; above it customers pay online
COD_MAX_SA=500
COD_REQUIRE_OTP=true         # SMS code to the delivery phone before the order is confirmed (needs SMS set up)
COD_BLOCK_AFTER_REFUSALS=1   # refusals before the option disappears for that customer (0 = never)
```

How it works:

1. At checkout the customer picks *Cash on delivery*; the quote adds the fee and checks the limit.
2. After placing the order they enter the SMS code. The order becomes **Confirmed · pay on delivery** and is
   bought from the supplier straight away (you pay the supplier before the customer pays: keep the limit low).
3. When the courier remits the cash, open the order in Admin → **Cash received**: the payment is recorded,
   the invoice is issued and loyalty points are earned.
4. If the customer refuses the parcel, press **Refused at the door**: the order is cancelled and the refusal
   counted. Ask the carrier for return-to-sender; you still owe the supplier and the shipping.

Refunds after collection are paid back manually (bank transfer or store credit); the online refund button
does not apply to cash.
