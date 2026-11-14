<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GulfCart</title></head>
<body style="margin:0;background:#f5f5f4;font-family:Arial,'Noto Sans Arabic',sans-serif;color:#1c1917">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:8px;padding:28px;text-align:{{ $dir === 'rtl' ? 'right' : 'left' }}">
        <tr><td style="font-size:20px;font-weight:bold;padding-bottom:16px">GulfCart</td></tr>
        <tr><td style="font-size:16px;padding-bottom:12px">{{ $greeting }}</td></tr>
        @foreach ($lines as $line)
          <tr><td style="font-size:14px;line-height:1.6;padding-bottom:8px">{{ $line }}</td></tr>
        @endforeach
        @if ($actionUrl)
          <tr><td style="padding-top:16px"><a href="{{ $actionUrl }}" style="background:#1c1917;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">{{ $actionText }}</a></td></tr>
        @endif
      </table>
    </td></tr>
  </table>
</body>
</html>
