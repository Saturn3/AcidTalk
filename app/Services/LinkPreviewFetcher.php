<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Costruisce l'anteprima di un link (Open Graph / Twitter Card / oEmbed).
 * - YouTube e Vimeo via oEmbed (evita le pagine di consenso cookie servite ai server europei);
 * - blocca indirizzi privati/riservati (protezione SSRF: il server non deve leggere la rete interna);
 * - legge al massimo ~512 KB di HTML e rispetta il charset dichiarato.
 */
class LinkPreviewFetcher
{
    /** Risolutore DNS sostituibile nei test: fn(string $host): array<string> */
    public static ?\Closure $resolveUsing = null;

    public const UA = 'Mozilla/5.0 (compatible; AcidTalkBot/2.2; +link-preview) facebookexternalhit/1.1 Twitterbot/1.0';

    /** @return array<string,mixed>|null dati per LinkPreview, null se il link va ignorato */
    public function fetch(string $url): ?array
    {
        $url = trim($url);
        $p = parse_url($url);
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }
        $host = strtolower($p['host']);
        $base = ['url' => $url, 'kind' => 'link', 'site_name' => preg_replace('/^www\./', '', $host), 'title' => null, 'description' => null, 'image' => null, 'provider' => null];

        if ($provider = VideoCallLinks::detect($url)) {
            return array_merge($base, ['kind' => 'video_call', 'provider' => $provider, 'title' => VideoCallLinks::label($provider), 'description' => 'Clicca per partecipare alla videochiamata']);
        }
        if (! $this->isPublicHost($host)) {
            return null;
        }
        if (preg_match('/\.(png|jpe?g|gif|webp|avif)(\?|#|$)/i', $p['path'] ?? '')) {
            return array_merge($base, ['kind' => 'image', 'image' => $url, 'title' => basename($p['path'])]);
        }
        if ($yt = $this->youtubeId($url)) {
            $o = $this->oembed('https://www.youtube.com/oembed?format=json&url='.urlencode('https://www.youtube.com/watch?v='.$yt));

            return array_merge($base, ['kind' => 'video', 'provider' => 'youtube', 'site_name' => 'YouTube',
                'title' => $o['title'] ?? 'Video YouTube', 'description' => isset($o['author_name']) ? 'di '.$o['author_name'] : null,
                'image' => "https://i.ytimg.com/vi/{$yt}/hqdefault.jpg"]);
        }
        if (preg_match('~^(?:www\.|player\.)?vimeo\.com$~', $host) && preg_match('~/(\d{6,})~', $p['path'] ?? '')) {
            $o = $this->oembed('https://vimeo.com/api/oembed.json?url='.urlencode($url));
            if ($o) {
                return array_merge($base, ['kind' => 'video', 'provider' => 'vimeo', 'site_name' => 'Vimeo',
                    'title' => $o['title'] ?? 'Video Vimeo', 'description' => isset($o['author_name']) ? 'di '.$o['author_name'] : null, 'image' => $o['thumbnail_url'] ?? null]);
            }
        }

        try {
            $res = Http::timeout(6)->connectTimeout(4)->withOptions(['allow_redirects' => ['max' => 4, 'on_redirect' => function ($req, $resp, $uri) {
                if (! $this->isPublicHost($uri->getHost())) {
                    throw new \RuntimeException('redirect verso indirizzo non pubblico');
                }
            }], 'stream' => true])->withHeaders(['User-Agent' => self::UA, 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.7'])->get($url);
        } catch (\Throwable) {
            return $base + [];
        }
        $type = strtolower((string) $res->header('Content-Type'));
        if (! $res->successful() || ! str_contains($type, 'html')) {
            return str_starts_with($type, 'image/') ? array_merge($base, ['kind' => 'image', 'image' => $url]) : $base;
        }
        $html = $this->readLimited($res, 524288);
        $html = $this->toUtf8($html, $type);

        $meta = $this->metaTags($html);
        $pick = fn (string ...$keys) => collect($keys)->map(fn ($k) => $meta[$k] ?? null)->first(fn ($v) => filled($v));
        $title = $pick('og:title', 'twitter:title') ?: $this->titleTag($html);
        $image = $this->absolute($pick('og:image:secure_url', 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src'), $url);

        return array_merge($base, [
            'title' => $title ? Str::limit(trim($title), 200) : $base['site_name'],
            'description' => ($d = $pick('og:description', 'twitter:description', 'description')) ? Str::limit(trim($d), 300) : null,
            'image' => $image && $this->isPublicHost((string) parse_url($image, PHP_URL_HOST)) ? Str::limit($image, 1000, '') : null,
            'site_name' => Str::limit($pick('og:site_name', 'application-name') ?: $base['site_name'], 80),
            'provider' => ($pick('twitter:card') === 'summary_large_image' || $pick('og:type') === 'video.other') ? 'large' : null,
        ]);
    }

    public function isPublicHost(string $host): bool
    {
        $host = trim(strtolower($host), '[]');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (static::$resolveUsing ? (static::$resolveUsing)($host) : array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        ));
        if (! $ips) {
            $ip = gethostbyname($host);
            $ips = $ip !== $host ? [$ip] : [];
        }
        if (! $ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    public function youtubeId(string $url): ?string
    {
        return preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m) ? $m[1] : null;
    }

    /** @return array<string,string> chiave (property|name, minuscola) => content */
    public function metaTags(string $html): array
    {
        $out = [];
        if (! preg_match_all('~<meta\b[^>]*>~i', Str::before($html, '</head>') ?: $html, $tags)) {
            return $out;
        }
        foreach ($tags[0] as $tag) {
            preg_match_all('~([a-zA-Z:-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))~', $tag, $attrs, PREG_SET_ORDER);
            $a = [];
            foreach ($attrs as $at) {
                $a[strtolower($at[1])] = ($at[3] ?? '') !== '' ? $at[3] : ((($at[4] ?? '') !== '') ? $at[4] : ($at[5] ?? ''));
            }
            $key = strtolower($a['property'] ?? $a['name'] ?? $a['itemprop'] ?? '');
            if ($key !== '' && isset($a['content']) && ! isset($out[$key])) {
                $out[$key] = html_entity_decode($a['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $out;
    }

    private function titleTag(string $html): ?string
    {
        return preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    private function oembed(string $endpoint): ?array
    {
        try {
            $r = Http::timeout(5)->withHeaders(['User-Agent' => self::UA])->get($endpoint);

            return $r->ok() ? (array) $r->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function readLimited($res, int $max): string
    {
        $body = $res->toPsrResponse()->getBody();
        $buf = '';
        while (! $body->eof() && strlen($buf) < $max) {
            $buf .= $body->read(65536);
            if (stripos($buf, '</head>') !== false) {
                break;
            }
        }

        return $buf;
    }

    private function toUtf8(string $html, string $contentType): string
    {
        $cs = null;
        if (preg_match('~charset=([\w-]+)~i', $contentType, $m) || preg_match('~<meta[^>]+charset=["\']?([\w-]+)~i', $html, $m)) {
            $cs = strtoupper($m[1]);
        }
        if ($cs && ! in_array($cs, ['UTF-8', 'UTF8'], true)) {
            $conv = @mb_convert_encoding($html, 'UTF-8', $cs);
            if ($conv !== false) {
                return $conv;
            }
        }

        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }

    private function absolute(?string $src, string $base): ?string
    {
        if (! $src) {
            return null;
        }
        $src = trim($src);
        if (preg_match('~^https?://~i', $src)) {
            return $src;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($src, '//')) {
            return ($p['scheme'] ?? 'https').':'.$src;
        }
        if (str_starts_with($src, '/')) {
            return $origin.$src;
        }
        $dir = isset($p['path']) ? rtrim(str_ends_with($p['path'], '/') ? $p['path'] : dirname($p['path']), '/') : '';

        return $origin.$dir.'/'.$src;
    }
}
