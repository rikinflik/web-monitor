<?php

namespace App\Services;

use App\Models\SeoCheck;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Computes the five per-URL SEO checks.
 *
 * Each canonicalization dimension (www / https / trailing slash) is probed
 * independently: the service requests the OPPOSITE variant of the stored URL
 * and inspects whether it 3xx-redirects toward the canonical form. Probing the
 * stored URL alone can never reveal a redirect when the stored URL is already
 * canonical (the common case), which is why the opposite variant is probed.
 * robots.txt and sitemap.xml reachability round out the five checks.
 */
final class SeoCheckService
{
    /**
     * Per-request timeout in seconds, bounding a slow/unreachable URL.
     */
    private const TIMEOUT = 10;

    /**
     * Status codes that mean a server refuses HEAD; retried once with GET.
     */
    private const HEAD_UNSUPPORTED = [405, 501];

    /**
     * Synthetic sub-path (with a trailing slash) probed when the monitored URL
     * is the site root. The root itself never redirects onto its slash variant
     * (both "/" and "" resolve to the home), so a server-wide trailing-slash
     * policy is invisible there. A non-existent sub-path reveals the rewrite
     * rule instead of a real page's behaviour, because slash-stripping rules
     * fire before routing (even on paths that ultimately 404).
     */
    private const SLASH_PROBE_PATH = '/_seo-slash-probe/';

    public function __construct(private readonly Client $client) {}

    /**
     * Run the five SEO checks for the given entry and persist the results.
     *
     * Outbound requests: three no-follow redirect probes (one per
     * canonicalization dimension, HEAD with a GET fallback) plus one robots.txt
     * request and one sitemap request with at most one sitemap_index.xml
     * fallback — 5 in the common case, 6 worst case (more only if a server
     * rejects HEAD and forces a GET retry).
     */
    public function check(SeoCheck $seoCheck): void
    {
        $url = $seoCheck->monitor->url;
        $origin = $this->originOf($url);

        $redirects = $this->probeRedirects($url);

        $results = [
            'www_redirect' => $redirects['www'],
            'https_redirect' => $redirects['https'],
            'trailing_slash_redirect' => $redirects['trailing_slash'],
            'robots_ok' => $this->checkRobots($origin),
            'sitemap_ok' => $this->checkSitemap($origin),
        ];

        $this->recordLog($seoCheck, $results);

        $seoCheck->update($results + ['last_checked_at' => now()]);
    }

    /**
     * Probe each canonicalization dimension against the stored URL's opposite
     * variant, in the display order www / https / trailing slash.
     *
     * @return array{www: string, https: string, trailing_slash: string}
     */
    private function probeRedirects(string $url): array
    {
        return [
            'www' => $this->probeDimension($this->wwwCounterpart($url), 'www'),
            'https' => $this->probeDimension($this->schemeCounterpart($url), 'https'),
            'trailing_slash' => $this->probeDimension($this->slashCounterpart($url), 'trailing_slash'),
        ];
    }

    /**
     * Probe one opposite-variant URL and return the classification for the
     * requested dimension, or NONE when the variant does not redirect.
     */
    private function probeDimension(string $counterpart, string $dimension): string
    {
        $probe = $this->probeVariant($counterpart);

        if ($probe === null || $probe['status'] < 300 || $probe['status'] >= 400 || $probe['location'] === '') {
            return SeoCheck::NONE;
        }

        return $this->classifyRedirect($counterpart, $probe['location'])[$dimension];
    }

    /**
     * Issue one no-follow HEAD probe, falling back to GET when the server
     * rejects HEAD. Returns the status and Location header, or null on a
     * transport error.
     *
     * @return array{status: int, location: string}|null
     */
    private function probeVariant(string $url): ?array
    {
        try {
            $response = $this->client->head($url, $this->requestOptions());

            if (in_array($response->getStatusCode(), self::HEAD_UNSUPPORTED, true)) {
                $response = $this->client->get($url, $this->requestOptions());
            }

            return [
                'status' => $response->getStatusCode(),
                'location' => $response->getHeaderLine('Location'),
            ];
        } catch (GuzzleException $e) {
            return null;
        }
    }

    /**
     * Diff scheme/host/path of the probed variant against the redirect target.
     *
     * @return array{www: string, https: string, trailing_slash: string}
     */
    private function classifyRedirect(string $from, string $to): array
    {
        $fromScheme = strtolower((string) parse_url($from, PHP_URL_SCHEME));
        $toScheme = strtolower((string) parse_url($to, PHP_URL_SCHEME));
        $fromHost = strtolower((string) parse_url($from, PHP_URL_HOST));
        $toHost = strtolower((string) parse_url($to, PHP_URL_HOST));
        $fromPath = $this->normalizePath((string) parse_url($from, PHP_URL_PATH));
        $toPath = $this->normalizePath((string) parse_url($to, PHP_URL_PATH));

        return [
            'www' => $this->classifyWww($fromHost, $toHost),
            'https' => $this->classifyHttps($fromScheme, $toScheme),
            'trailing_slash' => $this->classifyTrailingSlash($fromPath, $toPath),
        ];
    }

    private function classifyWww(string $fromHost, string $toHost): string
    {
        if ($fromHost === 'www.' . $toHost) {
            return SeoCheck::WWW_TO_NO_WWW;
        }
        if ($toHost === 'www.' . $fromHost) {
            return SeoCheck::NO_WWW_TO_WWW;
        }

        return SeoCheck::NONE;
    }

    private function classifyHttps(string $fromScheme, string $toScheme): string
    {
        if ($fromScheme === 'http' && $toScheme === 'https') {
            return SeoCheck::HTTP_TO_HTTPS;
        }
        if ($fromScheme === 'https' && $toScheme === 'http') {
            return SeoCheck::HTTPS_TO_HTTP;
        }

        return SeoCheck::NONE;
    }

    private function classifyTrailingSlash(string $fromPath, string $toPath): string
    {
        if ($toPath === rtrim($fromPath, '/') . '/' && strlen($toPath) - strlen($fromPath) === 1) {
            return SeoCheck::WITH_SLASH;
        }
        if ($fromPath === rtrim($toPath, '/') . '/' && strlen($fromPath) - strlen($toPath) === 1) {
            return SeoCheck::WITHOUT_SLASH;
        }

        return SeoCheck::NONE;
    }

    /**
     * Normalize an empty path to "/" so path comparisons are well-defined.
     */
    private function normalizePath(string $path): string
    {
        return $path === '' ? '/' : $path;
    }

    /**
     * Build the www counterpart: strip a leading "www." host label, or add one
     * when absent.
     */
    private function wwwCounterpart(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $parts['host'] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;

        return $this->buildUrl($parts);
    }

    /**
     * Build the scheme counterpart: flip https <-> http.
     */
    private function schemeCounterpart(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $parts['scheme'] = $scheme === 'https' ? 'http' : 'https';

        return $this->buildUrl($parts);
    }

    /**
     * Build the trailing-slash counterpart: toggle a single trailing "/".
     *
     * For a root URL, toggling would only swap "/" for "" (or vice versa), and
     * servers never redirect between those. Instead probe SLASH_PROBE_PATH so a
     * site-wide slash-stripping policy is still detected — this reveals the
     * WITHOUT_SLASH direction only, which is the one propagated by rewrite rules
     * to arbitrary paths.
     */
    private function slashCounterpart(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';

        if ($path === '' || $path === '/') {
            $parts['path'] = self::SLASH_PROBE_PATH;
        } else {
            $parts['path'] = str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/';
        }

        return $this->buildUrl($parts);
    }

    /**
     * Reassemble a URL from parse_url() components, preserving port and query.
     *
     * @param array<string, mixed> $parts
     */
    private function buildUrl(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    /**
     * Reachability of {origin}/robots.txt (HTTP 200).
     */
    private function checkRobots(string $origin): bool
    {
        return $this->returns200($origin . '/robots.txt');
    }

    /**
     * Reachability of the sitemap: {origin}/sitemap.xml, falling back once to
     * {origin}/sitemap_index.xml only when the primary URL is not 200.
     */
    private function checkSitemap(string $origin): bool
    {
        if ($this->returns200($origin . '/sitemap.xml')) {
            return true;
        }

        return $this->returns200($origin . '/sitemap_index.xml');
    }

    /**
     * Issue a single no-follow GET and report whether it returned HTTP 200,
     * degrading to false on any transport error.
     */
    private function returns200(string $url): bool
    {
        try {
            return $this->client->get($url, $this->requestOptions())->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Scheme + host (+ port) of the given URL, e.g. "https://example.com:8080".
     */
    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /**
     * Append a history row only when at least one of the five results differs
     * from the most recent log row (mirrors MonitoringService::recordLog()).
     *
     * @param array{www_redirect: string, https_redirect: string, trailing_slash_redirect: string, robots_ok: bool, sitemap_ok: bool} $results
     */
    private function recordLog(SeoCheck $seoCheck, array $results): void
    {
        $latest = $seoCheck->logs()->latest('checked_at')->first();

        if (
            $latest
            && $latest->www_redirect === $results['www_redirect']
            && $latest->https_redirect === $results['https_redirect']
            && $latest->trailing_slash_redirect === $results['trailing_slash_redirect']
            && $latest->robots_ok === $results['robots_ok']
            && $latest->sitemap_ok === $results['sitemap_ok']
        ) {
            return;
        }

        $seoCheck->logs()->create($results + ['checked_at' => now()]);
    }

    /**
     * Shared Guzzle options: never follow redirects, never throw on HTTP errors.
     *
     * @return array<string, mixed>
     */
    private function requestOptions(): array
    {
        return [
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => self::TIMEOUT,
            'verify' => false,
        ];
    }
}
