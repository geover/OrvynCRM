<?php

namespace App\Services\Tenancy;

use App\Models\PlatformSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * What the public website says that the platform decides: the contact e-mail, the business address, the legal entity and the dates of the
 * Privacy Policy and Terms, and the address of the demo form. The website reads them when it is built (from a public endpoint); a field left
 * empty here keeps the website's own default from its .env. An optional build hook (a Netlify, Cloudflare Pages or Vercel address) rebuilds the
 * website when something here changes.
 */
class WebsiteSettings
{
    public const FIELDS = ['contact_email', 'business_address', 'legal_entity', 'legal_effective', 'legal_updated', 'demo_form_url'];

    private const DATES = ['legal_effective', 'legal_updated'];

    /** @return array<string, mixed> what the page shows; the build hook itself is never given back */
    public function current(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = (string) PlatformSetting::get("website.{$f}");
        }

        return $out + [
            'hook_set' => $this->hook() !== null,
            'last_rebuild' => PlatformSetting::get('website.last_rebuild_at') ? [
                'at' => PlatformSetting::get('website.last_rebuild_at'),
                'ok' => PlatformSetting::get('website.last_rebuild_ok') === '1',
                'message' => (string) PlatformSetting::get('website.last_rebuild_message'),
            ] : null,
        ];
    }

    /**
     * What the public endpoint returns: only values the platform set (null otherwise, so the website keeps its own default). Dates read as text.
     *
     * @return array<string, string|null>
     */
    public function publicValues(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string) PlatformSetting::get("website.{$f}"));
            $out[$f] = $v === '' ? null : (in_array($f, self::DATES, true) ? Carbon::parse($v)->format('F j, Y') : $v);
        }

        return $out;
    }

    /**
     * An empty hook keeps the saved one; `clear_hook` removes it.
     *
     * @param  array<string, mixed>  $d
     * @return bool whether any public value changed
     */
    public function save(array $d): bool
    {
        $before = $this->publicValues();
        foreach (self::FIELDS as $f) {
            $v = trim((string) ($d[$f] ?? ''));
            PlatformSetting::put("website.{$f}", $v === '' ? null : $v);
        }
        if (! empty($d['build_hook'])) {
            PlatformSetting::put('website.build_hook', Crypt::encryptString(trim((string) $d['build_hook'])));
        }
        if (! empty($d['clear_hook'])) {
            PlatformSetting::put('website.build_hook', null);
        }

        return $before !== $this->publicValues();
    }

    public function hook(): ?string
    {
        $saved = PlatformSetting::get('website.build_hook');
        if (! $saved) {
            return null;
        }
        try {
            return Crypt::decryptString($saved);
        } catch (Throwable) {
            return null;                                   // saved under another APP_KEY: counts as not set
        }
    }

    /**
     * Ask the host to build the website again. Never throws: the outcome is remembered and returned.
     *
     * @return array{ok: bool, message: string}
     */
    public function rebuild(): array
    {
        try {
            $url = $this->hook() ?? throw new RuntimeException('No build hook is saved.');
            $this->assertReachable($url);
            $response = Http::timeout(10)->withBody('', 'application/json')->post($url);
            $ok = $response->successful();
            $message = $ok ? 'The host accepted the request and is building the website.' : 'The host answered '.$response->status().'.';
        } catch (Throwable $e) {
            $ok = false;
            $message = str($e->getMessage())->squish()->limit(160)->value();
        }
        PlatformSetting::put('website.last_rebuild_at', now()->toIso8601String());
        PlatformSetting::put('website.last_rebuild_ok', $ok ? '1' : '0');
        PlatformSetting::put('website.last_rebuild_message', $message);

        return ['ok' => $ok, 'message' => $message];
    }

    /** A build hook is a public https address: never this server or the network behind it. */
    public function assertReachable(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || $host === 'localhost' || preg_match('/\.(local|internal|test|localhost|invalid)$/', $host)) {
            throw new RuntimeException('A build hook must be a public https address.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new RuntimeException('The build hook address could not be found.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('A build hook must be a public https address.');
            }
        }
    }
}
