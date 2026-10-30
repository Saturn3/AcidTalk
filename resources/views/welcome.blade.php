@extends('layouts.public')
@section('title', 'AcidTalk · Comunicazione aziendale per '.$org->name)

@section('content')
<header class="nav">
  <div class="wrap">
    <a class="brand" href="/"><span class="logo" data-icon="bot" data-cls="s"></span>AcidTalk</a>
    <nav class="links" aria-label="Sezioni">
      <a href="#funzioni">Funzioni</a>
      <a href="#ai">Assistente AI</a>
      <a href="#api">API &amp; MCP</a>
      <a href="#avvio">Come iniziare</a>
    </nav>
    <span class="sp"></span>
    @auth
      <a class="btn white" href="/app">Apri l'app <span data-icon="chevL" data-cls="s" style="transform:rotate(180deg)"></span></a>
    @else
      <a class="btn ghost" href="/login">Accedi</a>
      <a class="btn white" href="/register">Registrati</a>
    @endauth
  </div>
</header>

<section class="hero">
  <div class="wrap">
    <div>
      <span class="eyebrow"><span class="dot" data-icon="check" data-cls="s"></span> Spazio di lavoro di {{ $org->name }}</span>
      <h1>Tutta la comunicazione del team, <em>in un unico posto</em>.</h1>
      <p class="lead">Canali pubblici e privati, messaggi diretti, thread, allegati con anteprima, note vocali e videochiamate. Con un assistente AI che risponde direttamente in chat, collegato a Ollama, OpenAI o Anthropic.</p>
      <div class="cta">
        @auth
          <a class="btn white lg" href="/app">Vai alla chat</a>
        @else
          <a class="btn white lg" href="/register">Crea il tuo account</a>
          <a class="btn ghost lg" href="/login">Ho già un account</a>
          <small>Iscrizione libera per gli indirizzi <b>&#64;{{ $org->domain }}</b>. Gli ospiti esterni entrano su invito.</small>
        @endauth
      </div>
      <div class="trust">
        <div><b>Self-hosted</b><span>I dati restano sui tuoi server</span></div>
        <div><b>3 provider AI</b><span>Ollama · OpenAI · Anthropic</span></div>
        <div><b>API + MCP</b><span>Pronto per Claude e Perplexity</span></div>
        <div><b>Mobile</b><span>App Android e iOS</span></div>
      </div>
    </div>

    <div class="mock" aria-hidden="true">
      <div class="sb">
        <div class="org"><span data-icon="bot" data-cls="s"></span> {{ $org->name }}</div>
        <div class="sec">Canali</div>
        <div class="it on"><span data-icon="hash"></span> generale</div>
        <div class="it"><span data-icon="hash"></span> sviluppo <span class="n">3</span></div>
        <div class="it"><span data-icon="lock"></span> direzione</div>
        <div class="sec">Messaggi diretti</div>
        <div class="it"><span data-icon="sparkles"></span> Assistente AI</div>
        <div class="it"><span data-icon="user"></span> Giulia Bianchi</div>
        <div class="it"><span data-icon="user"></span> Marco Rossi</div>
      </div>
      <div class="ch">
        <div class="hd"><span data-icon="hash" data-cls="s"></span> generale <span>· 12 membri</span></div>
        <div class="msgs">
          <div class="m"><div class="av" style="background:#1264a3">GB</div><div><b>Giulia Bianchi</b><time>09:41</time><p>Ho caricato il PDF del nuovo listino: l'anteprima si apre direttamente qui in chat.</p><div class="react"><span class="on">👍 3</span><span>🎉 1</span></div></div></div>
          <div class="m"><div class="av" style="background:#e8912d">MR</div><div><b>Marco Rossi</b><time>09:44</time><p>Perfetto. <code>@ai</code> riassumi le novità rispetto al listino precedente?</p></div></div>
          <div class="m ai"><div class="av"><span data-icon="sparkles" data-cls="s"></span></div><div><b>Assistente AI</b><time>09:44</time><p>Tre differenze principali: prezzi aggiornati su 14 articoli, nuova linea “Pro” e sconti volume dal 5% al 7%.</p></div></div>
        </div>
        <div class="comp"><span data-icon="plus"></span> Scrivi in #generale… <span style="margin-left:auto" data-icon="send"></span></div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="funzioni">
  <div class="wrap">
    <div class="center">
      <h2>Tutto quello che ti aspetti da una chat di lavoro</h2>
      <p class="sub">Un'interfaccia familiare, pensata per restare concentrati, con le funzioni che servono davvero ogni giorno.</p>
    </div>
    <div class="features">
      <article class="feat"><div class="ico"><span data-icon="hash"></span></div><h3>Canali pubblici e privati</h3><p>Organizza i progetti in canali, invita chi serve, archivia quando hai finito. Thread per non perdere il filo.</p></article>
      <article class="feat"><div class="ico acc"><span data-icon="edit"></span></div><h3>Messaggi ricchi e modificabili</h3><p>Formattazione Markdown, blocchi di codice, emoji e reazioni. Modifica o elimina i tuoi messaggi quando vuoi.</p></article>
      <article class="feat"><div class="ico ok"><span data-icon="clip"></span></div><h3>Allegati con anteprima</h3><p>Immagini, PDF visualizzati in linea, documenti e note vocali registrate direttamente dal browser.</p></article>
      <article class="feat"><div class="ico warn"><span data-icon="video"></span></div><h3>Videochiamate in un click</h3><p>Crea una stanza Jitsi o un link Google Meet dal canale; i link a Meet, Zoom e Teams diventano schede “Partecipa”.</p></article>
      <article class="feat"><div class="ico danger"><span data-icon="bell"></span></div><h3>Notifiche intelligenti</h3><p>Menzioni, DM e thread con preferenze per utente e per canale, notifiche desktop e riepilogo via email.</p></article>
      <article class="feat"><div class="ico"><span data-icon="shield"></span></div><h3>Amministrazione completa</h3><p>Utenti, ruoli, dominio aziendale per l'iscrizione autonoma, inviti per gli ospiti esterni e impostazioni dell'organizzazione.</p></article>
    </div>
  </div>
</section>

<section class="section alt" id="ai">
  <div class="wrap split">
    <div>
      <h2>Un assistente AI che lavora nei tuoi canali</h2>
      <p class="sub" style="margin-bottom:0">Scegli il provider dall'area amministrativa: un modello locale via <b>Ollama</b>, <b>OpenAI</b> oppure <b>Claude</b> di Anthropic. Il bot risponde nei messaggi diretti e quando viene menzionato nei canali, tenendo conto del contesto della conversazione.</p>
      <ul>
        <li><span data-icon="check"></span><div><b>Menzione in canale.</b> Scrivi <code>@ai</code> in qualsiasi canale pubblico o privato per ottenere una risposta visibile a tutti.</div></li>
        <li><span data-icon="check"></span><div><b>Chat privata.</b> Ogni utente ha un DM dedicato con l'assistente per domande riservate.</div></li>
        <li><span data-icon="check"></span><div><b>Istruzioni personalizzate.</b> Definisci tono, lingua e regole del bot con un prompt di sistema a livello di organizzazione.</div></li>
      </ul>
    </div>
    <div class="code">
      <div class="bar"><i></i><i></i><i></i></div>
      <div><span class="c"># .env — oppure dal pannello Amministrazione → Assistente AI</span></div>
      <div><span class="k">AI_PROVIDER</span>=ollama <span class="c"># ollama | openai | anthropic</span></div>
      <div><span class="k">AI_MODEL</span>=<span class="s">llama3.1</span></div>
      <div><span class="k">OLLAMA_BASE_URL</span>=<span class="s">http://127.0.0.1:11434</span></div>
      <div><span class="k">OPENAI_API_KEY</span>=<span class="s">sk-…</span></div>
      <div><span class="k">ANTHROPIC_API_KEY</span>=<span class="s">sk-ant-…</span></div>
      <br>
      <div><span class="c"># In chat</span></div>
      <div>&gt; <span class="s">@ai</span> riassumi le decisioni di questo thread</div>
    </div>
  </div>
</section>

<section class="section" id="api">
  <div class="wrap split">
    <div class="code">
      <div class="bar"><i></i><i></i><i></i></div>
      <div><span class="c"># Collega Claude Desktop / Claude Code al server MCP</span></div>
      <div>claude mcp add --transport http acidtalk \</div>
      <div>&nbsp;&nbsp;<span class="s">{{ url('/api/mcp') }}</span> \</div>
      <div>&nbsp;&nbsp;--header <span class="s">"Authorization: Bearer &lt;token&gt;"</span></div>
      <br>
      <div><span class="c"># API REST</span></div>
      <div><span class="k">POST</span> /api/auth/login → token</div>
      <div><span class="k">GET</span>&nbsp; /api/channels/{id}/messages</div>
      <div><span class="k">POST</span> /api/channels/{id}/messages</div>
      <div><span class="k">PUT</span>&nbsp; /api/messages/{id} · <span class="k">DELETE</span> /api/messages/{id}</div>
    </div>
    <div>
      <h2>API REST, server MCP e app mobile</h2>
      <p class="sub" style="margin-bottom:0">Ogni funzione è disponibile via API con token personali. Il server <b>MCP</b> integrato permette a Claude.ai, Claude Desktop e Perplexity di leggere i canali, cercare messaggi e pubblicare, rispettando i permessi dell'utente.</p>
      <ul>
        <li><span data-icon="check"></span><div><b>Tool MCP:</b> <code>list_channels</code>, <code>read_channel</code>, <code>read_thread</code>, <code>search_messages</code>, <code>post_message</code>, <code>list_users</code>.</div></li>
        <li><span data-icon="check"></span><div><b>Realtime</b> con Laravel Reverb (WebSocket) e fallback automatico in polling.</div></li>
        <li><span data-icon="check"></span><div><b>App Flutter</b> per Android e iOS che usa le stesse API con autenticazione Bearer.</div></li>
      </ul>
    </div>
  </div>
</section>

<section class="section alt" id="avvio">
  <div class="wrap">
    <div class="center">
      <h2>In produzione in pochi minuti</h2>
      <p class="sub">Laravel 12, PHP 8.3, SQLite o MySQL/PostgreSQL. Nessun servizio esterno obbligatorio.</p>
    </div>
    <div class="steps">
      <div class="step"><h3>Installa</h3><p><code>composer install</code> poi <code>composer run setup</code>: crea <code>.env</code>, la chiave, il database e i dati demo.</p></div>
      <div class="step"><h3>Avvia</h3><p><code>php artisan serve --port=8787</code> e, in un altro terminale, <code>php artisan queue:work</code> per AI e anteprime link.</p></div>
      <div class="step"><h3>Configura</h3><p>Accedi come amministratore, imposta dominio, provider AI e videochiamate, poi invita il team.</p></div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="wrap">
    <div class="cta-band">
      <div><h2>Pronto a parlare con il tuo team?</h2><p>Accedi con l'email aziendale oppure chiedi un invito a un amministratore.</p></div>
      <div class="btns">
        @auth
          <a class="btn white lg" href="/app">Apri AcidTalk</a>
        @else
          <a class="btn white lg" href="/register">Registrati</a>
          <a class="btn ghost lg" href="/login">Accedi</a>
        @endauth
      </div>
    </div>
  </div>
</section>

<footer>
  <div class="wrap">
    <div>© {{ date('Y') }} {{ $org->name }} · AcidTalk 2.0</div>
    <div><a href="/login">Accedi</a><a href="/register">Registrati</a><a href="{{ url('/api/health') }}">Stato API</a></div>
  </div>
</footer>
@endsection
