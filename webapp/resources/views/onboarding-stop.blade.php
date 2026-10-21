<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $state === 'confirm' ? 'Stop these tips' : ($state === 'back' ? 'Tips are back' : 'No more tips') }} · {{ $brand }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;background:#f8fafc}
        main{width:100%;max-width:440px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;text-align:center;box-shadow:0 10px 30px -12px rgba(15,23,42,.15)}
        .mark{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px;background:#eef2ff;color:#4f46e5;font-size:26px}
        .ok{background:#ecfdf5;color:#059669}h1{font-size:22px;margin:0 0 8px}p{margin:0 0 18px;color:#475569}strong{color:#0f172a}
        button{width:100%;background:#4f46e5;color:#fff;border:0;border-radius:10px;padding:12px 18px;font:inherit;font-weight:600;cursor:pointer}
        button.quiet{background:#fff;color:#4f46e5;border:1px solid #c7d2fe}small{display:block;margin-top:16px;color:#94a3b8;font-size:13px}
    </style>
</head>
<body>
<main>
    @if($state === 'confirm')
        <div class="mark">💡</div>
        <h1>Stop the getting-started tips?</h1>
        <p>You will no longer get these e-mails about <strong>{{ $company }}</strong>. Your workspace and everything in it stay as they are.</p>
        <form method="post" action="{{ $url }}"><button type="submit">Yes, stop the tips</button></form>
        <small>If you pressed the link by mistake, just close this page.</small>
    @elseif($state === 'done')
        <div class="mark ok">✓</div>
        <h1>No more tips</h1>
        <p>We will not send you these e-mails about <strong>{{ $company }}</strong> any more.</p>
        <form method="post" action="{{ $url }}/resume"><button type="submit" class="quiet">I changed my mind, send them again</button></form>
    @else
        <div class="mark ok">✓</div>
        <h1>Welcome back</h1>
        <p>You will get the getting-started tips for <strong>{{ $company }}</strong> again.</p>
    @endif
</main>
</body>
</html>
