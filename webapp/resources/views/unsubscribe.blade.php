<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $state === 'confirm' ? 'Unsubscribe' : ($state === 'back' ? 'Subscribed again' : 'Unsubscribed') }} · {{ $company }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;background:#f8fafc}
        main{width:100%;max-width:440px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;text-align:center;box-shadow:0 10px 30px -12px rgba(15,23,42,.15)}
        .mark{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px;background:#eef2ff;color:#4f46e5;font-size:26px}
        .ok{background:#ecfdf5;color:#059669}h1{font-size:22px;margin:0 0 8px}p{margin:0 0 18px;color:#475569}strong{color:#0f172a;word-break:break-all}
        button{width:100%;background:#4f46e5;color:#fff;border:0;border-radius:10px;padding:12px 18px;font:inherit;font-weight:600;cursor:pointer}
        button.quiet{background:#fff;color:#4f46e5;border:1px solid #c7d2fe}small{display:block;margin-top:16px;color:#94a3b8;font-size:13px}
    </style>
</head>
<body>
<main>
    @if($state === 'confirm')
        <div class="mark">✉</div>
        <h1>Unsubscribe?</h1>
        <p>Stop e-mails from <strong>{{ $company }}</strong> to <strong>{{ $email }}</strong>.</p>
        <form method="post" action="{{ $url }}"><button type="submit">Yes, unsubscribe me</button></form>
        <small>If you pressed the link by mistake, just close this page.</small>
    @elseif($state === 'done')
        <div class="mark ok">✓</div>
        <h1>You are unsubscribed</h1>
        <p><strong>{{ $email }}</strong> will no longer get e-mails from {{ $company }}.</p>
        <form method="post" action="{{ $url }}/resubscribe"><button type="submit" class="quiet">I changed my mind, subscribe me again</button></form>
    @else
        <div class="mark ok">✓</div>
        <h1>Welcome back</h1>
        <p><strong>{{ $email }}</strong> will get e-mails from {{ $company }} again.</p>
    @endif
</main>
</body>
</html>
