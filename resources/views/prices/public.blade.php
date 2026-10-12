<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $list->title }}</title>
    <style>
        body{margin:0;padding:32px 16px;font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;background:#f8fafc}
        main{max-width:820px;margin:auto}h1{margin:0 0 4px}p.d{color:#475569;margin:0 0 24px}
        section{background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:20px;overflow:hidden}
        h2{margin:0;padding:12px 18px;font-size:15px;background:#f1f5f9}table{width:100%;border-collapse:collapse}
        td{padding:10px 18px;border-top:1px solid #f1f5f9;vertical-align:top}td.p{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.s{color:#64748b;font-size:13px}
    </style>
</head>
<body><main>
    <h1>{{ $list->title }}</h1>
    @if($list->description)<p class="d">{{ $list->description }}</p>@endif
    @php($cur = $list->data['currency'] ?? 'USD')
    @foreach(($list->data['categories'] ?? []) as $cat)
        <section><h2>{{ $cat['name'] }}</h2>
            <table>@foreach(($cat['items'] ?? []) as $it)
                <tr><td>{{ $it['name'] }}@if(!empty($it['description']))<div class="s">{{ $it['description'] }}</div>@endif</td>
                    <td class="p">@if(isset($it['price']) && $it['price'] !== null){{ number_format((float) $it['price'], 2) }} {{ $cur }}@if(!empty($it['unit'])) <span class="s">/ {{ $it['unit'] }}</span>@endif @elseif(!empty($it['formula']))<span class="s">{{ $it['formula'] }}</span>@endif</td></tr>
            @endforeach</table></section>
    @endforeach
</main></body>
</html>
