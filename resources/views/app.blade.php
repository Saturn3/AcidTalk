<!DOCTYPE html>
<html lang="it" data-theme-pref="{{ $bootstrap['user']['notification_prefs']['theme'] ?? '' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#3f0e40">
<title>{{ $bootstrap['org']['name'] }} · AcidTalk</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://api.fontshare.com" crossorigin>
<link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&f[]=jet-brains-mono@400,700&display=swap" rel="stylesheet">
<script src="/assets/theme.js?v={{ $bootstrap['version'] }}"></script>
<link rel="stylesheet" href="/assets/app.css?v={{ $bootstrap['version'] }}">
@if ($bootstrap['reverb'])
<script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js" defer></script>
@endif
</head>
<body>
<div id="app" aria-live="polite"></div>
<noscript><div style="padding:40px;text-align:center;font-family:system-ui">AcidTalk richiede JavaScript abilitato.</div></noscript>
<script>window.ACIDTALK = @json($bootstrap);</script>
<script src="/assets/icons.js?v={{ $bootstrap['version'] }}"></script>
<script src="/assets/emoji-data.js?v={{ $bootstrap['version'] }}" defer></script>
<script src="/assets/app.js?v={{ $bootstrap['version'] }}" defer></script>
<script src="/assets/app-pages.js?v={{ $bootstrap['version'] }}" defer></script>
</body>
</html>
