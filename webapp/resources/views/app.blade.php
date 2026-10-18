<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ \App\Support\Tenancy\Company::pageFaviconUrl(request()) }}">
    <title inertia>{{ config('app.name', 'OrvynCRM') }}</title>
    @php($signIn = \App\Support\Tenancy\Company::isSignInPage(request()) ? \App\Support\Tenancy\Company::platformBranding() : null)
    @php($signInTheme = $signIn['theme'] ?? null)
    @php($signInDarkStyle = $signIn['dark_style'] ?? null)
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">try{var t={!! json_encode($signInTheme) !!}||JSON.parse(localStorage.getItem(location.pathname.indexOf('/platform')===0?'orvyn:platform-theme':'orvyn:theme')||'"system"');if(t==='dark'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark');var s={!! json_encode($signInDarkStyle) !!}||JSON.parse(localStorage.getItem(location.pathname.indexOf('/platform')===0?'orvyn:platform-dark-style':'orvyn:dark-style')||'"navy"');document.documentElement.dataset.darkStyle=s}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    @inertia
</body>
</html>
