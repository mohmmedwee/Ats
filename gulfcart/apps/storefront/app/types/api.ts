export interface Money { amount: string, currency: string }
export interface Envelope<T> { success: true, data: T, meta: { request_id: string, pagination?: Pagination, counts?: Record<string, number> } }
export interface Pagination { next_cursor?: string | null, prev_cursor?: string | null, limit?: number, page?: number, per_page?: number, total?: number, last_page?: number }

export type Availability = 'in_stock' | 'low_stock' | 'out_of_stock'

export interface ProductCard {
  id: string
  slug: string
  title: string
  brand: string | null
  brand_slug?: string | null
  category: { slug: string, name: string } | null
  price: Money | null
  image: string | null
  delivery_estimate: { min_days: number, max_days: number } | null
  colors?: { name: string, image: string | null }[]
}

export interface Variant {
  id: string
  sku: string
  attributes: Record<string, string>
  price: Money | null
  availability: Availability
  purchasable: boolean
}

export interface ProductDetail extends ProductCard {
  description: string | null
  images: { url: string, alt: string | null, variant_id: string | null }[]
  availability: Availability
  variants: Variant[]
  prices_include_tax: true
  size_guide?: string | null
  specifications?: string | null
  warranty?: string | null
  brand_info?: { slug: string, name: string, logo_url: string | null, authorized: boolean } | null
}

export interface Category { id: string, slug: string, name: string, image_url?: string | null, children: Category[] }

export interface CartLine {
  id: string
  variant_id: string
  quantity: number
  product: { id: string, slug: string, title: string, image: string | null } | null
  sku: string | null
  attributes: Record<string, string>
  unit_price: Money | null
  line_total: Money | null
  availability: Availability
  purchasable: boolean
}

export interface CartIssue { item_id: string, code: 'PRICE_CHANGED' | 'OUT_OF_STOCK' | 'UNAVAILABLE', old_price?: Money, new_price?: Money }

export interface Cart {
  id: string | null
  country: string
  currency: string
  items: CartLine[]
  item_count: number
  subtotal?: Money
  free_shipping?: { threshold: Money, remaining: Money, reached: boolean } | null
  issues: CartIssue[]
  valid: boolean
  guest_token?: string
}

export interface Address {
  id: string
  country: 'AE' | 'SA'
  full_name: string
  phone: string
  address_line_1: string
  address_line_2: string | null
  district: string | null
  city: string
  state: string
  postal_code: string | null
  national_address_code: string | null
  is_default: boolean
}

export interface ShippingOption { code: string, name: string, price: Money, free: boolean }

export interface Quote {
  currency: string
  items: { variant_id: string, title: string, sku: string, attributes: Record<string, string>, image: string | null, quantity: number, unit_price: Money, line_total: Money, shipment: number }[]
  shipments: { number: number, item_count: number, customs: Money, estimate: { min_days: number, max_days: number } }[]
  shipping_method: { code: string, name: string, free: boolean }
  subtotal: string
  shipping: string
  customs: string
  discount: string
  coupon?: { code: string, free_shipping: boolean } | null
  tax: string
  total: string
}

export interface CheckoutSession { id: string, status: string, quote: Quote, quote_expires_at: string }

export interface OrderSummary {
  id: string
  order_number: string
  status: string
  refund_state: 'none' | 'partial' | 'full'
  total: Money
  item_count: number
  image: string | null
  placed_at: string
}

export interface Tracking {
  shipment_group: number
  carrier: string | null
  tracking_number: string | null
  tracking_url: string | null
  status: string
  events: { code: string, description: string | null, location: string | null, occurred_at: string }[]
}

export interface OrderDetail extends OrderSummary {
  shipping_address: Record<string, string | null>
  shipping_method: { code: string | null, name: string | null }
  packages: {
    number: number
    status: string
    tracking: Tracking[]
    items: { id: string, title: string, sku: string, attributes: Record<string, string>, image: string | null, quantity: number, refunded_quantity: number, unit_price: Money, line_total: Money, status: string }[]
  }[]
  totals: Record<'subtotal' | 'shipping' | 'customs' | 'discount' | 'tax' | 'total' | 'paid' | 'refunded', Money>
  payment_due_at: string | null
  can_cancel: boolean
}

export interface Payment {
  id: string
  order_id: string
  order_number: string | null
  order_status: string | null
  provider: string
  status: string
  amount: Money
  redirect_url: string | null
  failure_code: string | null
}
