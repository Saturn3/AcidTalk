@extends('layouts.auth')
@section('title', 'Accedi · AcidTalk')

@section('card')
<h1>Bentornato</h1>
<p class="lead">Accedi allo spazio di lavoro di {{ $org->name }}.</p>

@if (session('status'))<div class="callout ok">{{ session('status') }}</div>@endif
@if ($errors->any())<div class="callout danger">{{ $errors->first() }}</div>@endif

<form method="POST" action="/login" novalidate>
  @csrf
  <div class="field @error('email') has-error @enderror">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="nome&#64;{{ $org->domain }}" required autofocus autocomplete="username">
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
  </div>
  <div class="row">
    <label><input type="checkbox" name="remember" value="1" checked> Resta collegato</label>
  </div>
  <button class="btn primary lg block" type="submit">Accedi</button>
</form>

<p class="alt-link">Non hai un account? <a href="/register">Registrati</a></p>

@if (config('app.debug'))
<div class="demo">Dati demo: <code>admin&#64;{{ $org->domain }}</code> · password <code>password</code></div>
@endif
@endsection
