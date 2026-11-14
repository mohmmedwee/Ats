<?php

return [
    'greeting' => 'مرحباً :name،',
    'order_confirmed' => ['subject' => 'تم تأكيد الطلب :order', 'lines' => ['شكراً لك! استلمنا دفعتك بقيمة :total للطلب :order.', 'نقوم الآن بطلب منتجاتك من الموردين. قد تصل المنتجات في طرود منفصلة.'], 'action' => 'عرض الطلب', 'sms' => 'جلف كارت: تم استلام الدفع للطلب :order (:total). سنبلغك عند الشحن.'],
    'order_shipped' => ['subject' => 'طرد من الطلب :order في الطريق إليك', 'lines' => ['تم شحن طردك عبر :carrier.', 'رقم التتبع: :tracking'], 'action' => 'تتبع الطرد', 'sms' => 'جلف كارت: تم شحن طرد من الطلب :order. رقم التتبع: :tracking'],
    'order_delivered' => ['subject' => 'تم توصيل الطلب :order', 'lines' => ['تم توصيل جميع منتجات الطلب :order. نتمنى أن تنال إعجابك!'], 'action' => 'عرض الطلب', 'sms' => 'جلف كارت: تم توصيل الطلب :order. نتمنى أن ينال إعجابك!'],
    'order_cancelled' => ['subject' => 'تم إلغاء الطلب :order', 'lines' => ['تم إلغاء طلبك :order. سيتم رد أي مبلغ مدفوع إلى وسيلة الدفع الأصلية.'], 'action' => 'عرض الطلب', 'sms' => 'جلف كارت: تم إلغاء الطلب :order وسيُسترد أي مبلغ مدفوع.'],
    'refund_issued' => ['subject' => 'استرداد مبلغ للطلب :order', 'lines' => ['قمنا برد مبلغ :amount إلى وسيلة الدفع الأصلية.', 'قد يستغرق ظهور المبلغ من 5 إلى 14 يوماً حسب البنك.'], 'action' => 'عرض الطلب', 'sms' => 'جلف كارت: تم استرداد :amount للطلب :order.'],
    'return_approved' => ['subject' => 'تمت الموافقة على إرجاع الطلب :order', 'lines' => ['تمت الموافقة على طلب الإرجاع. سنرسل لك تعليمات الإرجاع قريباً.'], 'action' => 'عرض الطلب', 'sms' => 'جلف كارت: تمت الموافقة على إرجاع الطلب :order.'],
    'return_rejected' => ['subject' => 'طلب الإرجاع للطلب :order', 'lines' => ['للأسف لم تتم الموافقة على طلب الإرجاع. يرجى التواصل مع خدمة العملاء للتفاصيل.'], 'action' => 'عرض الطلب', 'sms' => 'جلف كارت: لم تتم الموافقة على طلب الإرجاع للطلب :order. تواصل معنا للمساعدة.'],
    'ops_procurement_exception' => ['subject' => 'Procurement exception: :reason', 'lines' => ['Supplier order :supplier_order needs attention (:reason).']],
    'ops_manual_procurement' => ['subject' => 'Manual purchase required', 'lines' => ['Supplier order :supplier_order must be placed manually.']],
    'customer' => 'عميلنا العزيز',
    'cart_reminder' => ['subject' => 'نسيت شيئاً في سلتك', 'lines' => ['لديك :count منتج بانتظارك في السلة.', 'الأسعار تشمل الضريبة والجمارك والتوصيل الظاهرة عند الدفع. المنتجات المطلوبة قد تنفد.'], 'action' => 'العودة إلى السلة', 'sms' => 'جلف كارت: لديك :count منتج بانتظارك في السلة. :url'],
];
