@extends('layouts.auth')
@section('title', 'Invito · AcidTalk')
@section('side-title', 'Sei stato invitato in '.$org->name)
@section('side-text', 'Accetta l\'invito per entrare nei canali condivisi con te e iniziare a collaborare con il team.')

@section('card')
@if (! $invite || ! $invite->isValid())
  <h1>Invito non valido</h1>
  <p class="lead">Il link che hai aperto è scaduto, è già stato utilizzato oppure non esiste.</p>
  <div class="callout">Chiedi a chi ti ha invitato di generare un nuovo link dall'area Amministrazione → Inviti.</div>
  <a class="btn primary lg block" href="/login">Vai all'accesso</a>
@else
  <h1>Hai un invito</h1>
  <p class="lead">Un membro di {{ $org->name }} ti ha invitato a partecipare come <b>{{ $invite->role === 'guest' ? 'ospite' : 'membro' }}</b>.</p>
  <div class="invite-box">
    <div class="av"><span data-icon="mail"></span></div>
    <div>
      <b>{{ $invite->email }}</b>
      <span>Invitato da {{ $invite->inviter?->name ?? 'un amministratore' }} · scade il {{ optional($invite->expires_at)->format('d/m/Y') }}</span>
      @if (! empty($invite->channel_ids))
        <div class="chips">
          @foreach (\App\Models\Channel::whereIn('id', $invite->channel_ids)->get() as $ch)
            <span><span data-icon="{{ $ch->type === 'private' ? 'lock' : 'hash' }}" data-cls="s"></span>{{ $ch->name }}</span>
          @endforeach
        </div>
      @endif
    </div>
  </div>
  @auth
    @if (strcasecmp(auth()->user()->email, $invite->email) === 0)
      <div class="callout ok">Sei già registrato con questo indirizzo: l'invito è stato applicato al tuo account.</div>
      <a class="btn primary lg block" href="/app">Apri AcidTalk</a>
    @else
      <div class="callout">Sei collegato come <b>{{ auth()->user()->email }}</b>. Esci e registrati con l'indirizzo dell'invito.</div>
      <form method="POST" action="/logout">@csrf<button class="btn primary lg block" type="submit">Esci</button></form>
    @endif
  @else
    <a class="btn primary lg block" href="/register?invite={{ $token }}">Accetta e crea l'account</a>
    <p class="alt-link">Hai già un account con questa email? <a href="/login">Accedi</a></p>
  @endauth
@endif
@endsection
