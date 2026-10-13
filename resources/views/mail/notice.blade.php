<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }}</title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Inter,'Segoe UI',Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;padding:32px;">
            <tr><td style="font-size:14px;font-weight:700;letter-spacing:.02em;color:#4f46e5;padding-bottom:18px;">{{ $brand }}</td></tr>
            <tr><td style="font-size:20px;font-weight:700;line-height:1.3;padding-bottom:14px;">{{ $title }}</td></tr>
            @foreach ($lines as $line)
                <tr><td style="font-size:15px;line-height:1.6;color:#374151;padding-bottom:12px;">{!! nl2br(e($line)) !!}</td></tr>
            @endforeach
            @if ($button)
                <tr><td style="padding:12px 0 8px;"><a href="{{ $button[1] }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 22px;border-radius:10px;">{{ $button[0] }}</a></td></tr>
                <tr><td style="font-size:12px;line-height:1.5;color:#6b7280;padding-top:10px;">If the button does not work, copy this address into your browser:<br><span style="word-break:break-all;">{{ $button[1] }}</span></td></tr>
            @endif
        </table>
        <p style="font-size:12px;color:#9ca3af;margin:16px 0 0;">Sent by {{ $brand }}</p>
    </td></tr>
</table>
</body>
</html>
