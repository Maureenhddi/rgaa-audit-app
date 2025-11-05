<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Cache service for AI analysis results to avoid redundant API calls
 */
class AiAnalysisCacheService
{
    private array $cache = [];
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Generate a fingerprint for an error based on its characteristics
     * GENERIC approach: Group similar errors together to maximize cache hits
     *
     * Example: All "Images sans alt" errors get the SAME fingerprint
     * → 1 Gemini analysis reused for ALL images without alt across ALL pages
     */
    public function generateFingerprint(string $errorType, array $issueData): string
    {
        // For most errors, just use the error type itself!
        // This creates a GENERIC cache entry for each error type
        // Example:
        // - "Playwright - Missing alt text" → Always same fingerprint
        // - "Axe-core - color-contrast" → Always same fingerprint
        // - "A11yLint - Incomplete form label" → Always same fingerprint

        $source = $issueData['source'] ?? 'unknown';

        // Simple fingerprint: source + errorType
        // This groups ALL similar errors together
        $fingerprintData = [
            'source' => $source,
            'type' => $errorType,
        ];

        return md5(json_encode($fingerprintData));
    }

    /**
     * Generalize CSS selector by removing dynamic parts
     * Example: "div#user-123 > img.avatar" -> "div > img.avatar"
     */
    private function generalizeSelector(string $selector): string
    {
        // Remove IDs (keep structure)
        $selector = preg_replace('/#[\w-]+/', '', $selector);

        // Remove nth-child indices
        $selector = preg_replace('/:(nth-child|nth-of-type)\(\d+\)/', '', $selector);

        // Normalize whitespace
        $selector = preg_replace('/\s+/', ' ', trim($selector));

        return $selector;
    }

    /**
     * Extract pattern from error message (structure, not specific values)
     */
    private function extractMessagePattern(string $message): string
    {
        // Remove URLs
        $message = preg_replace('/https?:\/\/[^\s]+/', '[URL]', $message);

        // Remove specific attribute values
        $message = preg_replace('/(alt|title|aria-label)="[^"]*"/', '$1="[VALUE]"', $message);

        // Remove numbers
        $message = preg_replace('/\d+/', '[NUM]', $message);

        // Normalize to first 100 chars
        return substr($message, 0, 100);
    }

    /**
     * Check if we have a cached AI analysis for this error fingerprint
     */
    public function has(string $fingerprint): bool
    {
        return isset($this->cache[$fingerprint]);
    }

    /**
     * Get cached AI analysis for this error fingerprint
     */
    public function get(string $fingerprint): ?array
    {
        if (isset($this->cache[$fingerprint])) {
            $this->hits++;
            $this->logger->info("🎯 AI Cache HIT: fingerprint={$fingerprint}");
            return $this->cache[$fingerprint];
        }

        $this->misses++;
        return null;
    }

    /**
     * Store AI analysis in cache for this error fingerprint
     */
    public function set(string $fingerprint, array $analysis): void
    {
        $this->cache[$fingerprint] = $analysis;
        $this->logger->info("💾 AI Cache STORE: fingerprint={$fingerprint}");
    }

    /**
     * Get cache statistics (useful for logging/debugging)
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        $hitRate = $total > 0 ? round(($this->hits / $total) * 100, 2) : 0;

        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total' => $total,
            'hit_rate' => $hitRate,
            'cache_size' => count($this->cache),
        ];
    }

    /**
     * Log cache statistics (call at end of audit)
     */
    public function logStats(): void
    {
        $stats = $this->getStats();

        $this->logger->info(
            "📊 AI CACHE STATS: " .
            "Hits={$stats['hits']}, " .
            "Misses={$stats['misses']}, " .
            "Hit Rate={$stats['hit_rate']}%, " .
            "Cache Size={$stats['cache_size']} entries"
        );

        if ($stats['hit_rate'] > 0) {
            $apiCallsSaved = $stats['hits'];
            $this->logger->info("💰 SAVED {$apiCallsSaved} Gemini API calls thanks to cache!");
        }
    }

    /**
     * Clear the cache (call between different audits)
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->hits = 0;
        $this->misses = 0;
    }
}
