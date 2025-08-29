<?php

namespace Rcalicdan\FiberAsync\ProxyClient;

use Rcalicdan\FiberAsync\Api\Http;
use Rcalicdan\FiberAsync\Promise\Interfaces\PromiseInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scrapes for high-quality proxies. (Internal helper class)
 */
class ProxyScraper
{
    private string $url;
    private int $maxMinutes;

    public function __construct(string $url = 'https://free-proxy-list.net/en/', int $maxMinutes = 5)
    {
        $this->url = $url;
        $this->maxMinutes = $maxMinutes;
    }

    public function scrape(): PromiseInterface
    {
        return async(function () {
            echo "Scraping for proxy list from {$this->url}...\n";
            $response = await(Http::userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')->get($this->url));
            if (! $response->ok()) {
                throw new RuntimeException('Failed to fetch page, status: '.$response->status());
            }

            return $this->parseAndFilter($response->body());
        });
    }

    private function parseAndFilter(string $html): array
    {
        $proxies = [];
        $crawler = new Crawler($html);
        $rows = $crawler->filter('table tbody tr');
        $rows->each(function (Crawler $row) use (&$proxies) {
            $cells = $row->filter('td');
            if ($cells->count() < 8) {
                return;
            }
            $anonymity = strtolower(trim($cells->eq(4)->text()));
            $https = strtolower(trim($cells->eq(6)->text()));
            if ($anonymity !== 'elite proxy' || $https !== 'yes') {
                return;
            }
            if ($this->isFresh($cells->eq(7)->text())) {
                $proxies[] = $cells->eq(0)->text().':'.$cells->eq(1)->text();
            }
        });

        return $proxies;
    }

    private function isFresh(string $lastCheckedText): bool
    {
        $text = strtolower($lastCheckedText);
        if (str_contains($text, 'hour') || str_contains($text, 'day')) {
            return false;
        }
        if (str_contains($text, 'sec')) {
            return true;
        }
        if (preg_match('/(\d+)\s+mins?/i', $text, $m)) {
            return (int) $m[1] <= $this->maxMinutes;
        }

        return false;
    }
}
