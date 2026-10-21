<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
<style>
    body { margin: 0; padding: 0; background: #f1f5f9; }
    img { border: 0; outline: none; text-decoration: none; }
    .body p { margin: 0 0 14px; }
    .body ul, .body ol { margin: 0 0 14px; padding-left: 22px; }
    .body li { margin: 0 0 6px; }
    .body a { color: {{ $accent }}; }
    @media (max-width: 620px) {
        .wrap { width: 100% !important; }
        .pad { padding-left: 20px !important; padding-right: 20px !important; }
        .step-label { display: none !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader }}&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;">
<tr><td align="center" style="padding:28px 12px;">
<table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

    {{-- the header: the platform's colour, its name or logo, and the headline --}}
    <tr><td style="border-radius:20px 20px 0 0;background:{{ $accent }};background-image:linear-gradient(135deg,{{ $accent }} 0%,{{ $accentDark }} 100%);padding:30px 36px 34px;" class="pad">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td style="font-size:15px;font-weight:700;letter-spacing:.2px;color:#ffffff;">
                @if (!empty($brand['logo_url']))<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}" height="30" style="height:30px;max-width:180px;display:block;">@else{{ $brand['name'] }}@endif
            </td>
            <td align="right" style="font-size:12px;color:#ffffffcc;">{{ $progress['done'] }} of {{ $progress['total'] }} steps done</td>
        </tr></table>
        @if (!empty($icon))
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:24px;"><tr><td align="center" valign="middle" width="64" height="64" style="width:64px;height:64px;border-radius:32px;background:#ffffff26;border:1px solid #ffffff40;font-size:32px;line-height:64px;">{{ $icon }}</td></tr></table>
        @endif
        <div style="margin-top:{{ !empty($icon) ? '16' : '26' }}px;font-size:30px;line-height:1.15;font-weight:800;color:#ffffff;letter-spacing:-.5px;">{{ $headline }}</div>
        <div style="margin-top:10px;height:4px;width:56px;border-radius:2px;background:#ffffff66;"></div>
    </td></tr>

    <tr><td style="background:#ffffff;padding:32px 36px 8px;" class="pad">
        <div class="body" style="font-size:16px;line-height:1.65;color:#334155;">{!! $bodyHtml !!}</div>
    </td></tr>

    {{-- the company's own progress: what is done, and the step that comes next --}}
    <tr><td style="background:#ffffff;padding:8px 36px 8px;" class="pad">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;">
            <tr><td style="padding:20px 22px 6px;">
                <div style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#64748b;">Your progress</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;"><tr>
                    <td style="height:10px;border-radius:5px;background:#e2e8f0;">
                        <table role="presentation" width="{{ max(4, $progress['percent']) }}%" cellpadding="0" cellspacing="0"><tr><td style="height:10px;border-radius:5px;background:#10b981;background-image:linear-gradient(90deg,{{ $accent }},#10b981);font-size:0;line-height:0;">&nbsp;</td></tr></table>
                    </td>
                </tr></table>
            </td></tr>
            <tr><td style="padding:12px 14px 6px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
                    @foreach ($steps as $i => $s)
                        <td align="center" valign="top" width="{{ round(100 / count($steps), 2) }}%" style="padding:0 2px;">
                            <table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" valign="middle" width="28" height="28" style="width:28px;height:28px;border-radius:14px;font-size:13px;font-weight:700;color:{{ $s['done'] || $s['next'] ? '#ffffff' : '#64748b' }};background:{{ $s['done'] ? '#10b981' : ($s['next'] ? $accent : '#e2e8f0') }};">{{ $s['done'] ? '✓' : $i + 1 }}</td></tr></table>
                            <div class="step-label" style="margin-top:5px;font-size:10px;line-height:1.2;color:{{ $s['next'] ? $accent : '#64748b' }};font-weight:{{ $s['next'] ? '700' : '400' }};">{{ $s['short'] }}</div>
                        </td>
                    @endforeach
                </tr></table>
            </td></tr>
            @if ($next)
                <tr><td style="padding:10px 22px 20px;">
                    <div style="font-size:13px;color:#475569;"><strong style="color:#0f172a;">Next up:</strong> {{ $next['title'] }}. {{ $next['nudge'] }}</div>
                </td></tr>
            @else
                <tr><td style="padding:10px 22px 20px;"><div style="font-size:13px;color:#047857;"><strong>Every step is done.</strong> Well done.</div></td></tr>
            @endif
        </table>
    </td></tr>

    {{-- one clear button --}}
    @if (!empty($buttonLabel))
    <tr><td align="center" style="background:#ffffff;padding:24px 36px 10px;" class="pad">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
            <td align="center" style="border-radius:12px;background:{{ $accent }};background-image:linear-gradient(135deg,{{ $accent }},{{ $accentDark }});">
                <a href="{{ $buttonUrl }}" style="display:inline-block;padding:15px 32px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:12px;">{{ $buttonLabel }} &rarr;</a>
            </td>
        </tr></table>
        <div style="margin-top:12px;font-size:12px;color:#94a3b8;">Takes about five minutes</div>
    </td></tr>
    @endif

    @if (!empty($tip))
    <tr><td style="background:#ffffff;padding:20px 36px 30px;" class="pad">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:14px;"><tr>
            <td width="52" align="center" valign="top" style="padding:16px 0 16px 16px;font-size:26px;line-height:1;">💡</td>
            <td style="padding:16px 18px 16px 8px;font-size:14px;line-height:1.55;color:#78350f;"><strong>Did you know?</strong><br>{{ $tip }}</td>
        </tr></table>
    </td></tr>
    @else
    <tr><td style="background:#ffffff;height:24px;font-size:0;line-height:0;">&nbsp;</td></tr>
    @endif

    <tr><td style="border-radius:0 0 20px 20px;background:#ffffff;border-top:1px solid #e2e8f0;padding:22px 36px 28px;" class="pad">
        <div style="font-size:12px;line-height:1.6;color:#94a3b8;">
            You are getting this because you created <strong style="color:#64748b;">{{ $company }}</strong> on {{ $brand['name'] }}. Questions? Just reply to this e-mail.<br>
            @if (!empty($unsubscribeUrl))<a href="{{ $unsubscribeUrl }}" style="color:#64748b;">Stop these tips</a>@endif
        </div>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
