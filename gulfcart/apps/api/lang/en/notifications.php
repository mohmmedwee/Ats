<?php

return [
    'greeting' => 'Hello :name,',
    'order_confirmed' => ['subject' => 'Order :order confirmed', 'lines' => ['Thank you! We received your payment of :total for order :order.', 'We are now placing your items with our suppliers. Items may arrive in separate packages.'], 'action' => 'View order', 'sms' => 'GulfCart: payment received for order :order (:total). We\'ll text you when it ships.'],
    'order_shipped' => ['subject' => 'A package from order :order is on its way', 'lines' => ['Your package has shipped with :carrier.', 'Tracking number: :tracking'], 'action' => 'Track package', 'sms' => 'GulfCart: a package from order :order has shipped. Tracking: :tracking'],
    'order_delivered' => ['subject' => 'Order :order delivered', 'lines' => ['All items of order :order have been delivered. We hope you enjoy them!'], 'action' => 'View order', 'sms' => 'GulfCart: order :order has been delivered. Enjoy!'],
    'order_cancelled' => ['subject' => 'Order :order cancelled', 'lines' => ['Your order :order has been cancelled. Any payment will be refunded to your original payment method.'], 'action' => 'View order', 'sms' => 'GulfCart: order :order was cancelled. Any payment will be refunded.'],
    'refund_issued' => ['subject' => 'Refund for order :order', 'lines' => ['We have refunded :amount to your original payment method.', 'Depending on your bank, it can take 5–14 days to appear.'], 'action' => 'View order', 'sms' => 'GulfCart: we refunded :amount for order :order.'],
    'return_approved' => ['subject' => 'Return approved for order :order', 'lines' => ['Your return request has been approved. We will email the return instructions shortly.'], 'action' => 'View order', 'sms' => 'GulfCart: your return for order :order is approved.'],
    'return_rejected' => ['subject' => 'Return request for order :order', 'lines' => ['Unfortunately your return request could not be approved. Please contact support for details.'], 'action' => 'View order', 'sms' => 'GulfCart: your return request for order :order was not approved. Contact us for help.'],
    'ops_procurement_exception' => ['subject' => 'Procurement exception: :reason', 'lines' => ['Supplier order :supplier_order needs attention (:reason).', 'Open the procurement dashboard to retry, switch source or cancel and refund.']],
    'ops_manual_procurement' => ['subject' => 'Manual purchase required', 'lines' => ['Supplier order :supplier_order must be placed manually and recorded in the procurement dashboard.']],
    'customer' => 'there',
    'cart_reminder' => ['subject' => 'You left something in your cart', 'lines' => ['You still have :count item(s) waiting in your cart.', 'Prices include VAT, duties and delivery shown at checkout. Popular items can sell out.'], 'action' => 'Back to my cart', 'sms' => 'GulfCart: you have :count item(s) waiting in your cart. :url'],
    'back_in_stock' => ['subject' => 'Back in stock: :product_en', 'lines' => [':product_en is available again at :price.', 'Popular items sell out fast, so order soon if you want it.'], 'action' => 'Shop now', 'sms' => 'GulfCart: :product_en is back in stock (:price). :url'],
    'price_drop' => ['subject' => 'Price drop: :product_en', 'lines' => ['Good news: :product_en is now :price (was :old_price).', 'Prices can change again, so order soon if you want it.'], 'action' => 'See the new price', 'sms' => 'GulfCart: :product_en is now :price (was :old_price). :url'],
];
