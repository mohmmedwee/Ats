<?php

namespace App\Modules\Content\Database\Seeders;

use App\Modules\Content\Infrastructure\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Starting texts for the policy pages. They describe how the platform actually works
 * (DDP customs, return windows, VAT-inclusive prices) but must be reviewed by a lawyer
 * and completed with the company's legal details before launch (LAUNCH_CHECKLIST.md).
 * Existing pages are never overwritten.
 */
final class PageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $order => [$slug, $titleEn, $titleAr, $en, $ar]) {
            Page::query()->firstOrCreate(['slug' => $slug], [
                'title' => ['en' => $titleEn, 'ar' => $titleAr],
                'body' => ['en' => trim($en), 'ar' => trim($ar)],
                'is_published' => true, 'in_footer' => true, 'sort_order' => $order * 10,
            ]);
        }
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private function pages(): array
    {
        return [
            ['about', 'About us', 'من نحن', <<<'EN'
GulfCart is an online store for shoppers in the United Arab Emirates and Saudi Arabia. We work with selected international suppliers to bring you fashion, home and lifestyle products at fair prices.

## What makes us different
- One clear price: VAT, customs duties and delivery are shown before you pay. Nothing to pay at the door.
- Real tracking for every parcel, in Arabic and English.
- Easy returns within the return window of your country.
- Secure payment with cards, Apple Pay and mada.

GulfCart is the seller of every order: if anything goes wrong, you deal with us, not with the supplier.
EN, <<<'AR'
جلف كارت متجر إلكتروني للمتسوقين في الإمارات العربية المتحدة والمملكة العربية السعودية. نعمل مع موردين دوليين مختارين لنقدّم لك الأزياء ومستلزمات المنزل وأسلوب الحياة بأسعار عادلة.

## ما يميّزنا
- سعر واحد واضح: ضريبة القيمة المضافة والرسوم الجمركية والتوصيل تظهر قبل الدفع. لا شيء تدفعه عند الاستلام.
- تتبّع حقيقي لكل طرد بالعربي والإنجليزي.
- إرجاع سهل خلال مدة الإرجاع المعتمدة في بلدك.
- دفع آمن بالبطاقات وApple Pay ومدى.

جلف كارت هو البائع في كل طلب: إذا حدثت أي مشكلة تتعامل معنا مباشرة وليس مع المورد.
AR],
            ['shipping', 'Shipping and delivery', 'الشحن والتوصيل', <<<'EN'
## Where we deliver
We deliver to all emirates of the UAE and all regions of Saudi Arabia.

## Delivery time
Most orders arrive within 7 to 15 business days. The estimate for each product is shown on the product page and at checkout. Items from different suppliers may arrive in separate parcels, each with its own tracking number.

## Delivery fees
Delivery fees are shown at checkout before you pay. Standard delivery is free above the amount shown on the site.

## Customs and duties
Customs duties and import taxes are calculated and paid at checkout. You will not be asked to pay anything to the courier.

## Tracking
You receive an email when each parcel ships. You can follow every parcel from "My orders" in your account.

## Delays
If a parcel is delayed beyond the estimated time, contact us and we will check with the carrier. If a parcel is lost, we resend it or refund you in full.
EN, <<<'AR'
## مناطق التوصيل
نوصل إلى جميع إمارات الدولة وجميع مناطق المملكة العربية السعودية.

## مدة التوصيل
تصل أغلب الطلبات خلال 7 إلى 15 يوم عمل. تظهر المدة المتوقعة لكل منتج في صفحة المنتج وعند إتمام الطلب. قد تصل المنتجات من موردين مختلفين في طرود منفصلة، لكل طرد رقم تتبع خاص.

## رسوم التوصيل
تظهر رسوم التوصيل عند إتمام الطلب قبل الدفع. التوصيل العادي مجاني فوق المبلغ الموضّح في الموقع.

## الجمارك والرسوم
تُحسب الرسوم الجمركية وضرائب الاستيراد وتُدفع عند إتمام الطلب. لن يُطلب منك دفع أي مبلغ لشركة الشحن.

## التتبع
يصلك بريد إلكتروني عند شحن كل طرد، ويمكنك متابعة كل طرد من "طلباتي" في حسابك.

## التأخير
إذا تأخر الطرد عن المدة المتوقعة تواصل معنا وسنتابع مع شركة الشحن. وإذا فُقد الطرد نعيد إرساله أو نسترد لك المبلغ كاملاً.
AR],
            ['returns', 'Returns and refunds', 'الإرجاع والاسترداد', <<<'EN'
## Return window
- United Arab Emirates: 14 days from delivery.
- Saudi Arabia: 7 days from delivery.

## How to return
1. Open the order in "My orders" and choose "Return".
2. Select the items, the reason and add a note if needed.
3. We confirm your request and send the return instructions.

## Conditions
Items must be unused, with original tags and packaging. For hygiene reasons, underwear, swimwear, earrings and opened beauty products cannot be returned unless they arrived damaged or wrong.

## Damaged or wrong items
If an item arrives damaged, defective or different from what you ordered, contact us within 7 days with photos. We cover the return cost and refund you in full or send a replacement.

## Refunds
Refunds go back to the original payment method within 5 to 14 business days after we receive and check the return. Delivery fees are refunded when the return is due to our mistake.
EN, <<<'AR'
## مدة الإرجاع
- الإمارات العربية المتحدة: 14 يوماً من تاريخ الاستلام.
- المملكة العربية السعودية: 7 أيام من تاريخ الاستلام.

## طريقة الإرجاع
1. افتح الطلب من "طلباتي" واختر "إرجاع".
2. اختر المنتجات والسبب وأضف ملاحظة إذا لزم.
3. نؤكد طلبك ونرسل لك تعليمات الإرجاع.

## الشروط
يجب أن تكون المنتجات غير مستخدمة ومعها البطاقات والتغليف الأصلي. لأسباب صحية لا يمكن إرجاع الملابس الداخلية وملابس السباحة والأقراط ومنتجات التجميل المفتوحة، إلا إذا وصلت تالفة أو خاطئة.

## المنتجات التالفة أو الخاطئة
إذا وصل المنتج تالفاً أو معيباً أو مختلفاً عما طلبت، تواصل معنا خلال 7 أيام مع صور. نتحمل تكلفة الإرجاع ونسترد لك المبلغ كاملاً أو نرسل بديلاً.

## الاسترداد
يُعاد المبلغ إلى وسيلة الدفع الأصلية خلال 5 إلى 14 يوم عمل بعد استلام المرتجع وفحصه. تُسترد رسوم التوصيل إذا كان الإرجاع بسبب خطأ منا.
AR],
            ['privacy', 'Privacy policy', 'سياسة الخصوصية', <<<'EN'
This policy explains what personal data GulfCart collects, why, and how we protect it.

## What we collect
- Account data: name, email, mobile number.
- Order data: delivery address, ordered items, payment status. We do not store your card number; payments are processed by our licensed payment provider.
- Technical data: device and browser information, and, if you accept, analytics and advertising cookies.

## Why we use it
- To deliver your orders and keep you updated.
- To provide customer support, returns and refunds.
- To issue tax invoices and meet legal obligations.
- With your consent, to send offers and measure our advertising.

## Who we share it with
Only with the parties needed to complete your order: the supplier and courier (name, address, phone), the payment provider, and authorities when the law requires. We never sell your data.

## Security and retention
Addresses are encrypted in our database and access is limited and logged. We keep order and invoice records for the period required by tax law, and delete other data when no longer needed.

## Your rights
You can ask to access, correct or delete your data, and to stop marketing messages at any time, by contacting us.
EN, <<<'AR'
توضح هذه السياسة البيانات الشخصية التي يجمعها جلف كارت وسبب جمعها وكيف نحميها.

## ما الذي نجمعه
- بيانات الحساب: الاسم والبريد الإلكتروني ورقم الجوال.
- بيانات الطلب: عنوان التوصيل والمنتجات وحالة الدفع. لا نخزّن رقم بطاقتك، فالمدفوعات تتم عبر مزوّد دفع مرخّص.
- بيانات تقنية: معلومات الجهاز والمتصفح، وملفات تعريف الارتباط للتحليلات والإعلانات إذا وافقت عليها.

## لماذا نستخدمها
- لتوصيل طلباتك وإبلاغك بحالتها.
- لخدمة العملاء والإرجاع والاسترداد.
- لإصدار الفواتير الضريبية والالتزام بالأنظمة.
- بموافقتك، لإرسال العروض وقياس أداء إعلاناتنا.

## مع من نشاركها
فقط مع الجهات اللازمة لإتمام طلبك: المورد وشركة الشحن (الاسم والعنوان والجوال)، ومزوّد الدفع، والجهات الرسمية عندما يفرض القانون ذلك. لا نبيع بياناتك أبداً.

## الحماية والاحتفاظ
العناوين مشفّرة في قاعدة البيانات والوصول إليها محدود ومسجّل. نحتفظ بسجلات الطلبات والفواتير للمدة التي يفرضها نظام الضريبة، ونحذف البيانات الأخرى عند انتهاء الحاجة إليها.

## حقوقك
يمكنك طلب الاطلاع على بياناتك أو تصحيحها أو حذفها، وإيقاف الرسائل التسويقية في أي وقت، بالتواصل معنا.
AR],
            ['terms', 'Terms and conditions', 'الشروط والأحكام', <<<'EN'
By using GulfCart and placing an order you agree to these terms.

## The seller
GulfCart is the seller of all products on this site. Products are sourced from authorized suppliers and shipped to you directly.

## Prices and payment
Prices are shown in your local currency and include VAT. Customs duties and delivery fees are shown at checkout. The order is confirmed only after successful payment. If a price changes after you pay, you are not charged the difference.

## Availability
If an item becomes unavailable after you pay, we inform you and refund that item in full.

## Delivery, returns and refunds
See our Shipping and delivery and Returns and refunds pages, which form part of these terms.

## Your account
Keep your login details private. You are responsible for activity on your account. We may suspend accounts used for fraud or abuse.

## Product information
We try to show products accurately. Colours may look slightly different on your screen. Sizes follow the size guide on each product.

## Liability
Nothing in these terms limits your rights under the consumer protection laws of your country.

## Governing law
These terms are governed by the laws of the country where the order is delivered.
EN, <<<'AR'
باستخدامك جلف كارت وإتمامك أي طلب فإنك توافق على هذه الشروط.

## البائع
جلف كارت هو البائع لجميع المنتجات في هذا الموقع. نحصل على المنتجات من موردين معتمدين ونشحنها إليك مباشرة.

## الأسعار والدفع
تُعرض الأسعار بعملتك المحلية وتشمل ضريبة القيمة المضافة. تظهر الرسوم الجمركية ورسوم التوصيل عند إتمام الطلب. لا يُعتمد الطلب إلا بعد نجاح الدفع، وإذا تغيّر السعر بعد الدفع فلن تُحاسب على الفرق.

## التوفّر
إذا أصبح المنتج غير متوفر بعد الدفع نُبلغك ونسترد قيمته كاملة.

## التوصيل والإرجاع والاسترداد
راجع صفحتي الشحن والتوصيل والإرجاع والاسترداد، وهما جزء من هذه الشروط.

## حسابك
حافظ على سرية بيانات الدخول، فأنت مسؤول عن النشاط في حسابك. يحق لنا إيقاف الحسابات المستخدمة في الاحتيال أو إساءة الاستخدام.

## معلومات المنتجات
نحرص على عرض المنتجات بدقة، وقد تظهر الألوان مختلفة قليلاً على شاشتك. المقاسات حسب دليل المقاسات في كل منتج.

## المسؤولية
لا شيء في هذه الشروط يحدّ من حقوقك بموجب أنظمة حماية المستهلك في بلدك.

## القانون المطبّق
تخضع هذه الشروط لقوانين الدولة التي يُسلَّم فيها الطلب.
AR],
            ['faq', 'Help and FAQ', 'المساعدة والأسئلة الشائعة', <<<'EN'
## Do I pay anything on delivery?
No. VAT, customs and delivery are included in what you pay at checkout.

## How long does delivery take?
Usually 7 to 15 business days. Each product shows its own estimate.

## Why did my order arrive in more than one parcel?
Items can come from different suppliers. Each parcel has its own tracking number in "My orders".

## Can I cancel my order?
Yes, from "My orders", until the supplier starts processing it. After that you can return it after delivery.

## How do I return an item?
Open the order in "My orders" and choose "Return". See the Returns page for details.

## Which payment methods do you accept?
Cards, Apple Pay and mada (in Saudi Arabia).
EN, <<<'AR'
## هل أدفع شيئاً عند الاستلام؟
لا. ضريبة القيمة المضافة والجمارك والتوصيل مشمولة في المبلغ الذي تدفعه عند إتمام الطلب.

## كم تستغرق مدة التوصيل؟
عادة من 7 إلى 15 يوم عمل، ويظهر لكل منتج موعده المتوقع.

## لماذا وصل طلبي في أكثر من طرد؟
قد تأتي المنتجات من موردين مختلفين، ولكل طرد رقم تتبع خاص في "طلباتي".

## هل يمكنني إلغاء طلبي؟
نعم من "طلباتي" قبل أن يبدأ المورد بتجهيزه، وبعد ذلك يمكنك إرجاعه بعد الاستلام.

## كيف أرجع منتجاً؟
افتح الطلب من "طلباتي" واختر "إرجاع"، وراجع صفحة الإرجاع للتفاصيل.

## ما طرق الدفع المتاحة؟
البطاقات وApple Pay ومدى (في السعودية).
AR],
            ['contact', 'Contact us', 'تواصل معنا', <<<'EN'
We are happy to help with orders, returns and anything else.

Have your order number ready (for example GC-10001) so we can help you faster.
EN, <<<'AR'
يسعدنا مساعدتك في الطلبات والإرجاع وأي استفسار آخر.

جهّز رقم طلبك (مثل GC-10001) لنخدمك بشكل أسرع.
AR],
        ];
    }
}
