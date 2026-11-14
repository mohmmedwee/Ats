# CJdropshipping supplier

[CJdropshipping](https://cjdropshipping.com) is a dropshipping supplier with a public API
([API 2.0 docs](https://developers.cjdropshipping.com/en/api/api2/)). It sources products (mostly from
China, with some overseas warehouses), ships each order directly to the customer with a tracking
number, and has shipping lines to the UAE and Saudi Arabia. Costs are in USD and paid from a prepaid
CJ wallet balance.

It is the first real connector (`connector_type = cj`), so GulfCart can sell before any private
supplier agreement exists.

## What the connector does

| GulfCart step | CJ API call |
|---|---|
| Token (cached per key, renewed automatically) | `POST authentication/getAccessToken` |
| Product, variants and cost | `GET product/query?pid=` (`variants[].vid`, `variantSellPrice`) |
| Stock per variant | `GET product/stock/getInventoryByPid?pid=` |
| Shipping cost and method | `POST logistic/freightCalculate`: preferred logistic if configured, else cheapest |
| Purchase | `POST shopping/order/createOrderV2` (our idempotency key is CJ's `orderNumber`) |
| Payment of the CJ order | `POST shopping/order/confirmOrder`, then `POST shopping/pay/payBalance` |
| Status and tracking number | `GET shopping/order/getOrderDetail?orderId=` (`orderStatus`, `trackNumber`, `logisticName`) |
| Reconciliation after a timeout | `getOrderDetail` by our reference, then scan `GET shopping/order/list` |
| Cancel (only while unpaid) | `DELETE shopping/order/deleteOrder?orderId=` |

**Safety rules:**
- **Rate limits and connection failures** are retried safely.
- **Timeouts and 5xx on writes** are treated as an unknown outcome and reconciled, never resubmitted
  blindly: `idempotentOrders = false`, because CJ does not document deduplication by `orderNumber`.
- **A failed wallet payment** (for example, low balance) moves the supplier order to the manual queue
  with the CJ order ID, so an operator can top up, pay in the CJ dashboard, and record it.

## Setup (about 30 minutes)

1. **Create a CJ account** at cjdropshipping.com and add funds to the wallet. Read CJ's terms on
   resale and image use.
2. **Get the API key:** My CJ → Authorization → API → generate the key.
3. **Set it on the server** (never in Git), and allow outbound HTTPS to `developers.cjdropshipping.com`:
   ```env
   CJ_API_KEY=...
   # optional
   CJ_LOGISTICS="CJPacket Ordinary"   # preferred shipping lines, in order
   CJ_AUTO_PAY=true                   # pay CJ orders from the wallet automatically
   ```
4. **Register the supplier and check the key:**
   ```bash
   php artisan suppliers:cj-setup --check-vid=<a CJ variant id>
   ```
   This prints the shipping options and prices to AE and SA for that variant.
5. **Approve it in `/admin/suppliers`:**
   - Add an authorization record: CJ terms as the agreement reference, countries AE and SA, resale,
     API purchasing, direct shipping, and image use if CJ's terms allow it.
   - Then activate the supplier.
6. **Import products** by CJ product ID (`pid`, from the product page or URL):
   ```bash
   php artisan suppliers:cj-import 1234567890ABCDEF 2345678901BCDEFG --category=dresses
   ```
   - Products arrive as drafts with their variants, cost and stock. The importer also stores a
     per-unit shipping estimate (the higher of AE and SA) for pricing.
   - Before publishing in `/admin/products`: add Arabic titles, check the category, approve the images.
7. **Keep cost and stock fresh:** `suppliers:sync` (scheduled) pulls CJ prices and stock. Tracking is
   pulled by `procurement:track` every 30 minutes, as for every supplier.

## Verify on the first live order (cannot be tested without an account)

The connector is built and covered by tests against recorded API shapes (`tests/Feature/Suppliers/CjConnectorTest.php`),
but the following must be confirmed with a real CJ account and one low-value test order before
opening to customers:

- [ ] `createOrderV2` accepts our address fields for AE and SA:
  - province uses the English emirate or region name;
  - `shippingZip` falls back to `00000` when there is no postal code;
  - the phone is in E.164 format.
- [ ] `confirmOrder` then `payBalance` is the right sequence for the account. If CJ already pays on
  create, set `CJ_CONFIRM_BEFORE_PAY=false`.
- [ ] `getOrderDetail` accepts our `orderNumber` for reconciliation. If it does not, the list scan
  covers recent orders.
- [ ] Status names returned in `orderStatus` match the mapping in `CjDropshippingConnector::STATUS`.
- [ ] Delivery times and parcel condition to Dubai and Riyadh are acceptable, and no fees are charged
  to the customer at delivery.
