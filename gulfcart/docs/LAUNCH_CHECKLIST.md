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

## B. Strongly recommended at launch

| Item | Setting / guide |
|---|---|
| WhatsApp Business (Meta) + approved message templates | `WHATSAPP_*` · [NOTIFICATIONS.md](NOTIFICATIONS.md) |
| Support WhatsApp number and email | `SUPPORT_WHATSAPP`, `SUPPORT_EMAIL` |
| Meta Pixel, TikTok Pixel, Snap Pixel, Google Analytics 4 IDs | `META_PIXEL_ID`, `TIKTOK_PIXEL_ID`, `SNAP_PIXEL_ID`, `GA4_MEASUREMENT_ID` |
| Anthropic API key for Arabic auto-translation of imported products | `TRANSLATION_PROVIDER=claude`, `ANTHROPIC_API_KEY` |
| Google Search Console + Merchant Center, Meta Commerce Manager (product feeds) | [DEPLOY.md](DEPLOY.md) §6 |
| Off-site backup storage (S3/Backblaze) | [DEPLOY.md](DEPLOY.md) §5 |

## C. In the admin panel after deploying

1. Admin → Countries: seller legal name, VAT number, payment methods.
2. Admin → Pricing: margin rules (start with 30–40%, minimum 15%).
3. Admin → Shipping: delivery prices and free-delivery threshold.
4. Admin → Suppliers: record the CJ agreement and activate the supplier.
5. Admin → CJ products: import the first 20–50 products, review Arabic titles and images, publish.
6. Admin → Discount codes: create a welcome code, e.g. WELCOME10.
7. Admin → Team: invite staff with the right roles.
