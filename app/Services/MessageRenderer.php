<?php

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Converte il Markdown dei messaggi in HTML sicuro.
 * - CommonMark + GFM (tabelle, strikethrough, task list, autolink)
 * - HTMLPurifier per rimuovere script/attributi pericolosi
 * - @menzioni evidenziate
 */
class MessageRenderer
{
    private MarkdownConverter $converter;

    private HTMLPurifier $purifier;

    public function __construct()
    {
        $env = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $env->addExtension(new AutolinkExtension());
        $env->addExtension(new DisallowedRawHtmlExtension());
        $this->converter = new MarkdownConverter($env);

        $cfg = HTMLPurifier_Config::createDefault();
        $cache = storage_path('app/purifier');
        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $cfg->set('Cache.SerializerPath', $cache);
        $cfg->set('HTML.Allowed', 'p,br,strong,em,del,s,code,pre[class],blockquote,ul,ol[start],li,a[href|title|rel|target],h1,h2,h3,h4,table,thead,tbody,tr,th,td,hr,span[class]');
        $cfg->set('Attr.AllowedFrameTargets', ['_blank']);
        $cfg->set('AutoFormat.Linkify', false);
        $cfg->set('HTML.Nofollow', true);
        $cfg->set('HTML.TargetBlank', true);
        $this->purifier = new HTMLPurifier($cfg);
    }

    public function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        try {
            $html = (string) $this->converter->convert(self::chatBlocks($markdown));
            // Le task list GFM producono <input type="checkbox">: le trasformiamo in simboli
            $html = preg_replace(['~<input[^>]*checked[^>]*>~i', '~<input[^>]*>~i'], ['☑', '☐'], $html);
            $html = $this->purifier->purify($html);
        } catch (\Throwable $e) {
            report($e);
            $html = '<p>'.nl2br(e($markdown)).'</p>';
        }

        return $this->transformText($html);
    }

    /**
     * Elenchi "da chat": una riga di testo non rientrata subito dopo una voce d'elenco chiude l'elenco
     * (in Markdown standard diventerebbe la continuazione dell'ultima voce), e una voce d'elenco dopo
     * una riga di testo apre sempre un elenco. I blocchi di codice restano invariati.
     */
    public static function chatBlocks(string $md): string
    {
        $item = '~^\s{0,3}(?:[-*+•]|\d{1,9}[.)])\s~u';
        $out = [];
        $fence = false;
        $prev = null; // 'item' | 'text' | null
        foreach (preg_split('~\r?\n~', $md) as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line)) {
                $fence = ! $fence;
                $out[] = $line;
                $prev = null;
                continue;
            }
            if ($fence) {
                $out[] = $line;
                continue;
            }
            $kind = trim($line) === '' ? null : (preg_match($item, $line) ? 'item' : 'text');
            $indented = preg_match('~^\s~', $line) === 1;
            if (($prev === 'item' && $kind === 'text' && ! $indented) || ($prev === 'text' && $kind === 'item')) {
                $out[] = '';
            }
            $out[] = preg_replace('~^(\s{0,3})•(\s)~u', '$1-$2', $line);
            if ($kind !== 'text' || ! $indented || $prev !== 'item') {
                $prev = $kind;
            }
        }

        return implode("\n", $out);
    }

    private static ?array $shortcodes = null;

    /** @var array<string,array{0:int,1:string}>|null slug => [id, nome] dei canali dell'organizzazione (azzerata a ogni modifica dei canali) */
    public static ?array $channelRefs = null;

    /** Canali citabili con #slug (pubblici e privati, non archiviati esclusi: il link apre comunque il canale). */
    public static function channelRefs(): array
    {
        if (self::$channelRefs !== null) {
            return self::$channelRefs;
        }
        try {
            $org = \App\Models\Organization::current();
            $rows = \App\Models\Channel::query()->where('organization_id', $org->id)->whereIn('type', ['public', 'private'])
                ->whereNotNull('slug')->get(['id', 'slug', 'name']);
            $map = [];
            foreach ($rows as $c) {
                $map[mb_strtolower($c->slug)] = [$c->id, $c->name];
            }

            return self::$channelRefs = $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Emoticon testuali convertite in emoji (solo se separate da spazi o punteggiatura). */
    private const EMOTICONS = [
        ':-)' => '🙂', ':)' => '🙂', ':-D' => '😄', ':D' => '😄', ';-)' => '😉', ';)' => '😉',
        ':-(' => '🙁', ':(' => '🙁', ':-P' => '😛', ':P' => '😛', ':p' => '😛', ':-O' => '😮', ':O' => '😮', ':o' => '😮',
        ':|' => '😐', ":'(" => '😢', ':&#039;(' => '😢', ':*' => '😘', ':/' => '😕', 'XD' => '😆', '&lt;3' => '❤️', '&lt;/3' => '💔',
        '8-)' => '😎', 'B-)' => '😎', '^^' => '😊', ':-|' => '😐',
    ];

    public static function shortcodes(): array
    {
        return self::$shortcodes ??= (array) require resource_path('emoji/shortcodes.php');
    }

    /**
     * Trasforma solo i nodi di testo fuori da code/pre/a: @menzioni evidenziate, #canali come link, :codice: ed emoticon in emoji.
     * Un messaggio composto solo da emoji (fino a 6) viene mostrato in grande.
     */
    private function transformText(string $html): string
    {
        $emoji = true;
        try {
            $emoji = (bool) \App\Models\Organization::current()->setting('emoji_autoconvert', true);
        } catch (\Throwable) {
        }
        $parts = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $skip = 0;
        $out = '';
        foreach ($parts as $part) {
            if ($part[0] === '<') {
                if (preg_match('~^<(code|pre|a)\b~i', $part)) {
                    $skip++;
                } elseif (preg_match('~^</(code|pre|a)>~i', $part)) {
                    $skip = max(0, $skip - 1);
                }
                $out .= $part;

                continue;
            }
            if ($skip > 0) {
                $out .= $part;

                continue;
            }
            if ($emoji) {
                $part = $this->emojify($part);
            }
            $part = preg_replace_callback('~(?<![\w/])@([\p{L}\d._-]+)~u', function ($m) {
                $cls = strtolower($m[1]) === 'ai' ? 'mention mention-ai' : 'mention';

                return '<span class="'.$cls.'">@'.$m[1].'</span>';
            }, $part);
            // #canale -> link al canale (solo se esiste; "&#039;" e simili sono esclusi dal lookbehind)
            $out .= preg_replace_callback('~(?<![\w/&#])#([\p{L}\d_-]*[\p{L}_-][\p{L}\d_-]*)~u', function ($m) {
                $refs = self::channelRefs();
                $slug = mb_strtolower(rtrim($m[1], '-_'));
                $tail = mb_substr($m[1], mb_strlen(rtrim($m[1], '-_')));
                if (! isset($refs[$slug])) {
                    return $m[0];
                }
                [$id] = $refs[$slug];

                return '<a class="chan-ref" href="/app/c/'.$id.'" data-channel-id="'.$id.'">#'.e($slug).'</a>'.$tail;
            }, $part);
        }

        // solo emoji: dimensione grande come in Slack
        if (preg_match('~^<p>([^<]+)</p>\s*$~u', $out, $m)) {
            $text = trim(html_entity_decode($m[1]));
            $noSpace = preg_replace('~\s+~u', '', $text);
            if ($noSpace !== '' && preg_match('~^(?:[\p{Extended_Pictographic}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}\x{FE0F}\x{200D}\x{20E3}#*0-9])+$~u', $noSpace)
                && preg_match('~\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]~u', $noSpace)
                && preg_match_all('~\X~u', $noSpace) <= 6 && ! preg_match('~^[#*0-9]+$~', $noSpace)) {
                $out = '<p class="jumbo">'.$m[1].'</p>';
            }
        }

        return $out;
    }

    public function emojify(string $text): string
    {
        $codes = self::shortcodes();
        $text = preg_replace_callback('~:([a-z0-9_+\-]{1,40}):~i', fn ($m) => $codes[strtolower($m[1])] ?? $m[0], $text);
        static $re = null;
        $re ??= '~(?<=^|\s)('.implode('|', array_map(fn ($e) => preg_quote($e, '~'), array_keys(self::EMOTICONS))).')(?=$|\s|[.,!?;])~u';

        return preg_replace_callback($re, fn ($m) => self::EMOTICONS[$m[1]] ?? $m[0], $text);
    }
}
