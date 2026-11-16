# Launch checklist: what the owner needs to provide

The software is complete. These items need accounts, documents or decisions that only the business can
provide. Each one names the setting it fills (in `deploy/api.env`) and the guide to follow.

## A. Required before the first sale

| # | Item | Where to get it | Setting / guide |
|---|---|---|---|
| 1 | **Company and trade licence**: UAE mainland/free zone or Saudi CR, with e-commerce activity | Business setup provider | Seller name on invoices: Admin → Countries |
| 2 | **VAT registration**: UAE FTA TRN and/or Saudi ZATCA VAT number (mandatory above the threshold) | Tax advisor | Admin → Countries → VAT number |
| 3 | **Domain**, e.g. gulfcart.com | Any registrar | `DOMAIN`, `API_DOMAIN` · [DEPLOY.md](DEPLOY.md) |
| 4 | **Server** (4 vCPU / 8 GB, Gulf region) | AWS, Azure, DigitalOcean… | [DEPLOY.md](DEPLOY.md) |
| 5 | **Tap Payments merchant account**: cards, mada, Apple Pay | tap.company (needs licence, bank account, live website with policies) | `TAP_SECRET_KEY` · [PAYMENTS.md](PAYMENTS.md) |
| 6 | **CJdropshipping account + wallet top-up + API key** | cjdropshipping.com → My CJ → Authorization → API | `CJ_API_KEY` · [suppliers/CJ_DROPSHIPPING.md](suppliers/CJ_DROPSHIPPING.md) |
| 7 | **Email sending** (order confirmations) | Postmark, Amazon SES or Brevo + domain DNS records (SPF/DKIM) | `MAIL_*` |
| 8 | **SMS provider with registered sender ID** (sign-in codes) | Unifonic (KSA/UAE) or Twilio | `SMS_PROVIDER`, `UNIFONIC_APP_SID` |
| 9 | **Legal review of policy pages** and the company's legal name/address on them | Lawyer | Admin → Pages |
| 10 | **Customs and VAT rates confirmed** (duty %, duty-free threshold) | Customs broker / tax advisor | Admin → Tax and customs |
| 11 | **One real test order** to Dubai and Riyadh: delivery time, packaging, no fees at the door | You | [CJ_DROPSHIPPING.md](suppliers/CJ_DROPSHIPPING.md) checklist |

## A2. Selling electronics, perfume or cosmetics

These sections are set to **only publish authorized brands** (Admin → Categories → "Only authorized brands").
A brand is marked authorized in Admin → Brands only when you hold a written authorization: a distributor
letter or the brand's reseller programme. Counterfeit or grey-market goods are seized at customs, and the
seller is liable.

| Product type | UAE | Saudi Arabia |
|---|---|---|
| Phones, routers, smart watches, Bluetooth/Wi-Fi devices | TDRA type approval (by the manufacturer or importer) | CST (formerly CITC) conformity + SASO/SABER certificate |
| Chargers, power banks, electrical goods | ECAS / Emirates Conformity (MoIAT) | SASO IECEE RC / SABER |
| Perfume, cosmetics | Municipality / MoIAT product registration | SFDA notification |
| Lithium batteries (shipping) | Courier must accept dangerous goods (UN3480/3481); many standard lines refuse them | Same |

Confirm with the supplier that their products already carry these approvals for the UAE and KSA, and ask
your customs broker. CJ ships some electronics on dedicated battery lines; check the shipping option before
listing. Add specifications and warranty text on each electronics product (Admin → Products).

## B. Strongly recommended at launch

| Item | Setting / guide |
|---|---|
| PayPal Business account (optional second payment method, charged in USD) | `PAYMENT_METHODS`, `PAYPAL_*` · [PAYMENTS.md](PAYMENTS.md) |
| WhatsApp Business (Meta) + approved message templates | `WHATSAPP_*` · [NOTIFICATIONS.md](NOTIFICATIONS.md) |
| Support WhatsApp number and email | `SUPPORT_WHATSAPP`, `SUPPORT_EMAIL` |
| Meta Pixel, TikTok Pixel, Snap Pixel, Google Analytics 4 IDs | `META_PIXEL_ID`, `TIKTOK_PIXEL_ID`, `SNAP_PIXEL_ID`, `GA4_MEASUREMENT_ID` |
| Anthropic API key for Arabic auto-translation of imported products | `TRANSLATION_PROVIDER=claude`, `ANTHROPIC_API_KEY` |
| Google Search Console + Merchant Center, Meta Commerce Manager (product feeds) | [DEPLOY.md](DEPLOY.md) §6 |
| Off-site backup storage (S3/Backblaze) | [DEPLOY.md](DEPLOY.md) §5 |

## B2. Growth features (ready, adjust if you want)

- **Flash sales**: Admin → Flash sales. Schedule a % off for the whole store, a section or one product, with a countdown on the site. A sale never takes a product below its cost; if the discount is too deep the product gets the lowest price that still covers its cost (so it sells at about zero profit: check Admin → Profit).
- **Rewards and invites**: on by default. Server settings (optional):
  `LOYALTY_ENABLED=true`, `LOYALTY_POINTS_PER_UNIT=1` (points per 1 AED/SAR), `LOYALTY_POINT_VALUE=0.05` (100 points = 5 AED/SAR),
  `LOYALTY_MIN_REDEEM=100`, `LOYALTY_REFERRER_POINTS=500`, `LOYALTY_FRIEND_PERCENT=10`, `LOYALTY_FRIEND_MAX=50`.
  Unused points are a liability: Admin → Rewards shows their value.
- **Order tracking**: customers use /track with the order number and phone; signed-in customers see the timeline on the order page.
- **Profit**: Admin → Profit shows daily profit and products losing money. Set `PAYMENT_FEE_PERCENT` to your real gateway fee.
- **App on the home screen (PWA)**: works automatically on HTTPS. Phones see an "Install" banner.

## C. In the admin panel after deploying

1. Admin → Countries: seller legal name, VAT number, payment methods.
2. Admin → Pricing: margin rules (start with 30–40%, minimum 15%).
3. Admin → Shipping: delivery prices and free-delivery threshold.
4. Admin → Suppliers: record the CJ agreement and activate the supplier.
5. Admin → CJ products: import the first 20–50 products, review Arabic titles and images, publish.
6. Admin → Discount codes: create a welcome code, e.g. WELCOME10.
7. Admin → Team: invite staff with the right roles.
