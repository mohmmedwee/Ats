<?php

return [
    'greeting' => 'Hello :name,',
    'order_confirmed' => ['subject' => 'Order :order confirmed', 'lines' => ['Thank you! We received your payment of :total for order :order.', 'We are now placing your items with our suppliers. Items may arrive in separate packages.'], 'action' => 'View order'],
    'order_shipped' => ['subject' => 'A package from order :order is on its way', 'lines' => ['Your package has shipped with :carrier.', 'Tracking number: :tracking'], 'action' => 'Track package'],
    'order_delivered' => ['subject' => 'Order :order delivered', 'lines' => ['All items of order :order have been delivered. We hope you enjoy them!'], 'action' => 'View order'],
    'order_cancelled' => ['subject' => 'Order :order cancelled', 'lines' => ['Your order :order has been cancelled. Any payment will be refunded to your original payment method.'], 'action' => 'View order'],
    'refund_issued' => ['subject' => 'Refund for order :order', 'lines' => ['We have refunded :amount to your original payment method.', 'Depending on your bank, it can take 5–14 days to appear.'], 'action' => 'View order'],
    'return_approved' => ['subject' => 'Return approved for order :order', 'lines' => ['Your return request has been approved. We will email the return instructions shortly.'], 'action' => 'View order'],
    'return_rejected' => ['subject' => 'Return request for order :order', 'lines' => ['Unfortunately your return request could not be approved. Please contact support for details.'], 'action' => 'View order'],
    'ops_procurement_exception' => ['subject' => 'Procurement exception: :reason', 'lines' => ['Supplier order :supplier_order needs attention (:reason).', 'Open the procurement dashboard to retry, switch source or cancel and refund.']],
    'ops_manual_procurement' => ['subject' => 'Manual purchase required', 'lines' => ['Supplier order :supplier_order must be placed manually and recorded in the procurement dashboard.']],
];
