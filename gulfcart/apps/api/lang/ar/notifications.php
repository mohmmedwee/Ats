<?php

return [
    'greeting' => 'مرحباً :name،',
    'order_confirmed' => ['subject' => 'تم تأكيد الطلب :order', 'lines' => ['شكراً لك! استلمنا دفعتك بقيمة :total للطلب :order.', 'نقوم الآن بطلب منتجاتك من الموردين. قد تصل المنتجات في طرود منفصلة.'], 'action' => 'عرض الطلب'],
    'order_shipped' => ['subject' => 'طرد من الطلب :order في الطريق إليك', 'lines' => ['تم شحن طردك عبر :carrier.', 'رقم التتبع: :tracking'], 'action' => 'تتبع الطرد'],
    'order_delivered' => ['subject' => 'تم توصيل الطلب :order', 'lines' => ['تم توصيل جميع منتجات الطلب :order. نتمنى أن تنال إعجابك!'], 'action' => 'عرض الطلب'],
    'order_cancelled' => ['subject' => 'تم إلغاء الطلب :order', 'lines' => ['تم إلغاء طلبك :order. سيتم رد أي مبلغ مدفوع إلى وسيلة الدفع الأصلية.'], 'action' => 'عرض الطلب'],
    'refund_issued' => ['subject' => 'استرداد مبلغ للطلب :order', 'lines' => ['قمنا برد مبلغ :amount إلى وسيلة الدفع الأصلية.', 'قد يستغرق ظهور المبلغ من 5 إلى 14 يوماً حسب البنك.'], 'action' => 'عرض الطلب'],
    'return_approved' => ['subject' => 'تمت الموافقة على إرجاع الطلب :order', 'lines' => ['تمت الموافقة على طلب الإرجاع. سنرسل لك تعليمات الإرجاع قريباً.'], 'action' => 'عرض الطلب'],
    'return_rejected' => ['subject' => 'طلب الإرجاع للطلب :order', 'lines' => ['للأسف لم تتم الموافقة على طلب الإرجاع. يرجى التواصل مع خدمة العملاء للتفاصيل.'], 'action' => 'عرض الطلب'],
    'ops_procurement_exception' => ['subject' => 'Procurement exception: :reason', 'lines' => ['Supplier order :supplier_order needs attention (:reason).']],
    'ops_manual_procurement' => ['subject' => 'Manual purchase required', 'lines' => ['Supplier order :supplier_order must be placed manually.']],
];
