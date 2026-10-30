@extends('layouts.auth')
@section('title', 'Registrati · AcidTalk')
@section('side-title', 'Entra nel team di '.$org->name)

@section('card')
<h1>Crea il tuo account</h1>
@if ($invite)
  <p class="lead">Sei stato invitato come <b>{{ $invite->role === 'guest' ? 'ospite' : 'membro' }}</b>: completa la registrazione con l'indirizzo <b>{{ $invite->email }}</b>.</p>
@else
  <p class="lead">L'iscrizione autonoma è riservata agli indirizzi <b>&#64;{{ $org->domain }}</b>. Se sei un collaboratore esterno, chiedi un invito.</p>
@endif

@if ($errors->any())<div class="callout danger">{{ $errors->first() }}</div>@endif

<form method="POST" action="/register" novalidate>
  @csrf
  @if ($invite)<input type="hidden" name="invite_token" value="{{ $invite->token }}">@endif
  <div class="field @error('name') has-error @enderror">
    <label for="name">Nome e cognome</label>
    <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name">
  </div>
  <div class="field @error('email') has-error @enderror">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email', $invite?->email) }}" placeholder="nome&#64;{{ $org->domain }}" required autocomplete="email" @if($invite) readonly @endif>
  </div>
  <div class="field @error('password') has-error @enderror">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="new-password" minlength="8">
    <div class="help">Almeno 8 caratteri.</div>
  </div>
  <div class="field">
    <label for="password_confirmation">Conferma password</label>
    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
  </div>
  <button class="btn primary lg block" type="submit">Registrati</button>
</form>

<p class="alt-link">Hai già un account? <a href="/login">Accedi</a></p>
@endsection
