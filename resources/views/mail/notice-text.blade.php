{{ $title }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($button)
{{ $button[0] }}: {{ $button[1] }}

@endif
-- {{ $brand }}
