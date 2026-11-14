# Customer notifications

| Event | Email | WhatsApp template | SMS (only for customers without email, when WhatsApp is off) |
|---|---|---|---|
| Payment received | ✓ | `gc_order_confirmed` | ✓ |
| Package shipped | ✓ | `gc_order_shipped` | ✓ |
| Order delivered | ✓ | `gc_order_delivered` | ✓ |
| Order cancelled | ✓ | `gc_order_cancelled` | ✓ |
| Refund issued | ✓ | `gc_refund_issued` | ✓ |
| Return approved or rejected | ✓ | — | ✓ |
| Cart left 3–48 h (one reminder) | ✓ | `gc_cart_reminder` | ✓ |

Each message is sent at most once per channel, even when events are delivered twice
(`notification_logs.dedupe_key`). Templates live in `apps/api/lang/{en,ar}/notifications.php`.

## Turning on WhatsApp (Meta WhatsApp Cloud API)

1. In Meta Business Manager, create a WhatsApp Business account and add your business phone number.
   Verify the business; the display name must match the brand.
2. Create a **system user token** with `whatsapp_business_messaging` permission, and copy the
   **Phone number ID**.
3. In WhatsApp Manager → Message templates, create the templates below. For each, add
   **English (en)** and **Arabic (ar)** versions, category **Utility** (except the cart reminder,
   which is **Marketing**), and a **URL button** "View order" or "View cart" with the dynamic URL
   `https://YOUR-DOMAIN/{{1}}`.
4. Set on the server:
   ```env
   WHATSAPP_PROVIDER=meta
   WHATSAPP_PHONE_NUMBER_ID=...
   WHATSAPP_TOKEN=...
   ```

| Template name | Body (English) | Body (Arabic) |
|---|---|---|
| `gc_order_confirmed` | Hi {{1}}, we received your payment for order {{2}} ({{3}}). We'll message you when it ships. | مرحباً {{1}}، تم استلام الدفع للطلب {{2}} ({{3}}). سنبلغك عند الشحن. |
| `gc_order_shipped` | Hi {{1}}, a package from order {{2}} has shipped with {{3}}. Tracking number: {{4}} | مرحباً {{1}}، تم شحن طرد من الطلب {{2}} عبر {{3}}. رقم التتبع: {{4}} |
| `gc_order_delivered` | Hi {{1}}, order {{2}} has been delivered. We hope you love it! | مرحباً {{1}}، تم توصيل الطلب {{2}}. نتمنى أن ينال إعجابك! |
| `gc_order_cancelled` | Hi {{1}}, order {{2}} was cancelled. Any payment will be refunded to your original payment method. | مرحباً {{1}}، تم إلغاء الطلب {{2}} وسيُسترد أي مبلغ مدفوع إلى وسيلة الدفع الأصلية. |
| `gc_refund_issued` | Hi {{1}}, we refunded {{2}} for order {{3}}. It can take 5–14 days to appear. | مرحباً {{1}}، تم استرداد {{2}} للطلب {{3}}. قد يستغرق ظهوره من 5 إلى 14 يوماً. |
| `gc_cart_reminder` | Hi {{1}}, you still have {{2}} item(s) waiting in your cart. | مرحباً {{1}}، لديك {{2}} منتج بانتظارك في السلة. |

The variables are filled in the order listed in `config/gulfcart.php` → `whatsapp.templates`.
Change the names there if Meta asks you to rename a template.

## SMS

`SMS_PROVIDER=twilio` or `unifonic` (see `.env.example`). Register a sender ID with the provider:
regulators in the UAE and Saudi Arabia require it.

## Cart reminders

`notifications:cart-reminders` runs hourly. Set `CART_REMINDERS_ENABLED=false` to turn it off. Only
signed-in customers' carts are reminded, once per cart.
