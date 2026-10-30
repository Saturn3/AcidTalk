<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', $org->name.' · AcidTalk')</title>
<meta name="description" content="@yield('description', 'AcidTalk: la piattaforma di comunicazione aziendale di '.$org->name.' con canali, messaggi diretti, assistente AI e videochiamate.')">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://api.fontshare.com" crossorigin>
<link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700,900&display=swap" rel="stylesheet">
<script src="/assets/theme.js?v=2.1.0"></script>
<link rel="stylesheet" href="/assets/public.css?v=2.1.0">
@stack('head')
</head>
<body class="@yield('body-class')">
@yield('content')
<script src="/assets/icons.js"></script>
<script>document.querySelectorAll('[data-icon]').forEach(function(el){ el.innerHTML = window.atIcon(el.dataset.icon, el.dataset.cls || ''); });</script>
@stack('scripts')
</body>
</html>
