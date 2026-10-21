{{ $headline }}

{{ $textBody }}

Your progress: {{ $progress['done'] }} of {{ $progress['total'] }} steps done.
@if ($next)
Next up: {{ $next['title'] }}. {{ $next['nudge'] }}
@endif

@if (!empty($buttonLabel))
{{ $buttonLabel }}: {{ $buttonUrl }}
@endif
@if (!empty($tip))

Did you know? {{ $tip }}
@endif

--
You are getting this because you created {{ $company }} on {{ $brand['name'] }}.
@if (!empty($unsubscribeUrl))
Stop these tips: {{ $unsubscribeUrl }}
@endif
