<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
  <meta charset="utf-8">
  <title>{{ $invoice->number }}</title>
  <style>
    body { font-family: Arial, 'Noto Sans Arabic', sans-serif; color: #111; margin: 32px; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border-bottom: 1px solid #ddd; padding: 6px; text-align: start; font-size: 13px; }
    .num { text-align: end; }
    .muted { color: #666; font-size: 12px; }
    .totals td { border: 0; }
  </style>
</head>
<body>
  <h1>{{ $invoice->type === 'invoice' ? ($locale === 'ar' ? 'فاتورة ضريبية مبسطة' : 'Simplified Tax Invoice') : ($locale === 'ar' ? 'إشعار دائن' : 'Credit Note') }}</h1>
  <p><strong>{{ $invoice->number }}</strong> · {{ $invoice->issued_at->format('Y-m-d H:i') }} UTC</p>
  <p>{{ $invoice->seller['legal_name'] }}<br>
     <span class="muted">{{ $locale === 'ar' ? 'الرقم الضريبي' : 'VAT number' }}: {{ $invoice->seller['vat_number'] ?: '—' }}</span><br>
     <span class="muted">{{ $invoice->seller['address'] }}</span></p>
  <p class="muted">{{ $locale === 'ar' ? 'العميل' : 'Customer' }}: {{ $invoice->buyer['name'] }} · {{ $invoice->buyer['city'] }} · {{ $invoice->country }}</p>
  <table>
    <thead><tr><th>{{ $locale === 'ar' ? 'الوصف' : 'Description' }}</th><th class="num">{{ $locale === 'ar' ? 'الكمية' : 'Qty' }}</th><th class="num">{{ $locale === 'ar' ? 'الصافي' : 'Net' }}</th><th class="num">%</th><th class="num">{{ $locale === 'ar' ? 'الضريبة' : 'VAT' }}</th><th class="num">{{ $locale === 'ar' ? 'الإجمالي' : 'Total' }}</th></tr></thead>
    <tbody>
      @foreach ($invoice->lines as $line)
        <tr><td>{{ $title($line['description']) }} @if($line['sku'])<span class="muted">({{ $line['sku'] }})</span>@endif</td>
            <td class="num">{{ $line['quantity'] }}</td><td class="num">{{ $line['net'] }}</td><td class="num">{{ $line['vat_rate'] }}</td>
            <td class="num">{{ $line['vat'] }}</td><td class="num">{{ $line['gross'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
  <table class="totals">
    <tr><td class="num">{{ $locale === 'ar' ? 'المجموع بدون ضريبة' : 'Total excl. VAT' }}</td><td class="num">{{ $invoice->net_total }} {{ $invoice->currency }}</td></tr>
    <tr><td class="num">{{ $locale === 'ar' ? 'ضريبة القيمة المضافة' : 'VAT' }}</td><td class="num">{{ $invoice->vat_total }} {{ $invoice->currency }}</td></tr>
    <tr><td class="num"><strong>{{ $locale === 'ar' ? 'الإجمالي شامل الضريبة' : 'Total incl. VAT' }}</strong></td><td class="num"><strong>{{ $invoice->gross_total }} {{ $invoice->currency }}</strong></td></tr>
  </table>
  @if ($invoice->qr_payload)
    <div class="qr" style="margin-top:16px;display:flex;gap:12px;align-items:center" data-test="zatca-qr">
      <div style="width:140px;height:140px">{!! \App\Modules\Invoices\Domain\ZatcaQr::svg($invoice->qr_payload, 140) !!}</div>
      <p class="muted" style="font-size:11px">{{ $locale === 'ar' ? 'رمز الفاتورة الضريبية المبسطة (هيئة الزكاة والضريبة والجمارك)' : 'Simplified tax invoice QR (ZATCA)' }}</p>
    </div>
  @endif
</body>
</html>
