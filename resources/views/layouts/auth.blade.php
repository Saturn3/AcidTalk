@extends('layouts.public')
@section('body-class', 'auth')

@section('content')
<aside class="auth-side">
  <a class="brand" href="/"><span class="logo" data-icon="bot" data-cls="s"></span>AcidTalk</a>
  <div>
    <h2>@yield('side-title', 'Lo spazio di lavoro di '.$org->name)</h2>
    <p>@yield('side-text', 'Canali, messaggi diretti, thread, allegati e un assistente AI sempre disponibile. Tutto sui server della tua organizzazione.')</p>
    <div class="quote">
      <div class="m"><div class="av"><span data-icon="sparkles" data-cls="s"></span></div><div><b>Assistente AI</b><p>Ho riassunto il thread: tre decisioni prese, due attività assegnate a Marco, consegna prevista venerdì.</p></div></div>
    </div>
  </div>
  <small>© {{ date('Y') }} {{ $org->name }} · AcidTalk 2.0</small>
</aside>
<main class="auth-main">
  <div class="auth-card">
    @yield('card')
  </div>
</main>
@endsection
