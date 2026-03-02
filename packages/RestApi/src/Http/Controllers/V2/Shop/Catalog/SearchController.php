<?php

declare(strict_types=1);

namespace Webkul\RestApi\Http\Controllers\V2\Shop\Catalog;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Marketing\Repositories\SearchTermRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\SearchRepository;
use Webkul\RestApi\Http\Resources\V2\Shop\Catalog\ProductResource;

class SearchController extends Controller
{
    /** ---------------- TUNING (Amazon-like) ---------------- */

    // Cache results per query to reduce repeated scoring work
    private const CACHE_TTL_SECONDS = 300;

    // Candidate pull: keep small + index-friendly for millions of rows
    private const VECTOR_LIMIT = 350;

    // Suggestions count
    private const MAX_SUGGESTIONS = 6;
    private const MAX_QUERY_SUGGESTIONS = 8;

    // How many product terms we will attempt for DB search fallback
    private const MAX_PRODUCT_TERMS_TO_TRY = 6;

    // Click learning: prevent abuse spikes from one client
    private const CLICK_RATE_LIMIT_SECONDS = 2;

    public function __construct(
        protected SearchTermRepository $searchTermRepository,
        protected ProductRepository $productRepository,
        protected SearchRepository $searchRepository
    ) {}

    /**
     * GET /api/v2/search?q=
     *
     * Optional click-learning (same endpoint):
     *  - clicked_type=brand|category|product|concept
     *  - clicked_target=slug-or-target
     *
     * Response includes:
     *  - data: products
     *  - suggestions: categories, brands, queries
     *  - meta: amazon-like search metadata (did_you_mean, intent, etc)
     */
    public function index(Request $request)
    {
        $request->validate([
            'q'     => ['nullable', 'string'],
            'query' => ['nullable', 'string'],
        ]);

        $raw = trim((string) ($request->query('q') ?? $request->query('query')));
        $q   = $this->normalize($raw);

        $channel = core()->getCurrentChannel();
        $locale  = app()->getLocale();

        // --- Optional click-learning (does not change result logic) ---
        $this->handleClickLearning($request, $channel->id, $locale, $q);

        if ($q === '') {
            return response()->json([
                'data' => [],
                'suggestions' => [
                    'brands' => [],
                    'categories' => [],
                    'queries' => [],
                ],
                'meta' => [
                    'query' => null,
                    'normalized' => null,
                    'effective_query' => null,
                    'did_you_mean' => null,
                    'intent' => null,
                ],
            ]);
        }

        // --- Curated redirect rules (Amazon-style: "go to a landing page") ---
        // If you already use SearchTerm redirect_url, keep it.
        $configured = $this->searchTermRepository->findOneWhere([
            'term'       => $q,
            'channel_id' => $channel->id,
            'locale'     => $locale,
        ]);

        if ($configured?->redirect_url) {
            $configured->increment('uses');

            return response()->json([
                'data' => [],
                'suggestions' => [
                    'brands' => [],
                    'categories' => [],
                    'queries' => [],
                ],
                'meta' => [
                    'redirect_url' => $configured->redirect_url,
                    'query' => $raw,
                    'normalized' => $q,
                    'effective_query' => $q,
                    'did_you_mean' => null,
                    'intent' => [
                        'type' => 'redirect',
                        'confidence' => 1.0,
                    ],
                    'total' => 0,
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) ($request->query('limit') ?? $request->query('per_page') ?? 40),
                ],
            ]);
        }

        // Base params for product search
        $baseParams = array_merge($request->query(), [
            'channel_id'           => $channel->id,
            'status'               => 1,
            'visible_individually' => 1,
        ]);

        // Amazon-like “search brain” (fast + cached)
        $intel = Cache::remember(
            'search:amazon:' . sha1($channel->id . '|' . $locale . '|' . $q),
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildAmazonIntel($q, $channel->id, $locale)
        );

        // Determine product query candidates to try (fallbacks)
        $termsToTry = $this->buildProductTermCandidates(
            $q,
            $intel['effective_query'],
            $intel['did_you_mean'] ?? null,
            $intel['product_terms']
        );

        // Run product search with fallbacks
        [$products, $effectiveQueryUsed] = $this->searchProductsWithFallbacks($termsToTry, $baseParams);

        // Pagination meta (don’t rely on Laravel default meta merging)
        $meta = $this->buildPaginationMeta($products, $request);

        // Log original search term (uses + results)
        $totalResults = (int) ($meta['total'] ?? 0);
        $this->logSearchTerm($q, $channel->id, $locale, $totalResults);

        // Final response
        return ProductResource::collection($products)->additional([
            'suggestions' => [
                // Simple arrays (easy for UI)
                'brands'     => array_column($intel['brands'], 'slug'),
                'categories' => array_column($intel['categories'], 'slug'),
                'queries'    => $intel['query_suggestions'],

                // Rich objects (Amazon-like)
                'brand_items'     => $intel['brands'],
                'category_items'  => $intel['categories'],
                'quick_links'     => $intel['quick_links'], // URLs for SEO
            ],
            'meta' => array_merge($meta, [
                'query'           => $raw,
                'normalized'      => $q,
                'effective_query' => $effectiveQueryUsed,
                'did_you_mean'    => $intel['did_you_mean'],
                'intent'          => $intel['intent'],
                'engine'          => 'database+search_vectors',
                'request_id'      => $intel['request_id'],
            ]),
        ]);
    }

    /**
     * POST /api/v2/search/image
     */
    public function upload(Request $request)
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120'],
        ]);

        return response()->json([
            'data' => $this->searchRepository->uploadSearchImage($request->all()),
        ], 201);
    }

    /* ============================================================
       Amazon-like intelligence (typos + suggestions + intent)
       ============================================================ */

    private function buildAmazonIntel(string $q, int $channelId, string $locale): array
    {
        $requestId = (string) Str::uuid();

        // 1) Pull a SMALL candidate set using only index-friendly LIKEs
        $rows = $this->vectorCandidates($q);

        // 2) Score candidates in PHP (levenshtein only on small set)
        $scored = $this->scoreCandidates($q, $rows);

        // 3) Build brand/category suggestions + product_terms + did_you_mean
        $brands = [];
        $categories = [];
        $productTerms = [];

        foreach ($scored as $r) {
            if ($r['type'] === 'brand') {
                $brands[] = [
                    'slug'  => $r['target'],
                    'name'  => $r['term'] ?: $r['target'],
                    'score' => $r['score'],
                    'url'   => "/brands/" . $r['target'],
                ];
                continue;
            }

            if ($r['type'] === 'category') {
                $categories[] = [
                    'slug'  => $r['target'],
                    'name'  => $r['term'] ?: $r['target'],
                    'score' => $r['score'],
                    'url'   => "/categories/" . $r['target'],
                ];
                continue;
            }

            if (in_array($r['type'], ['product', 'concept'], true)) {
                // For products, searching by the human term works better than slug.
                $productTerms[] = $r['term'] ?: $r['target'];
            }
        }

        $brands     = $this->dedupeObjectsByKey($brands, 'slug');
        $categories = $this->dedupeObjectsByKey($categories, 'slug');
        $productTerms = $this->dedupeStrings($productTerms);

        $brands     = array_slice($brands, 0, self::MAX_SUGGESTIONS);
        $categories = array_slice($categories, 0, self::MAX_SUGGESTIONS);
        $productTerms = array_slice($productTerms, 0, self::MAX_PRODUCT_TERMS_TO_TRY);

        // 4) “Did you mean”
        $didYouMean = $this->chooseDidYouMean($q, $scored);

        // 5) Query suggestions (Amazon-like)
        $querySuggestions = $this->buildQuerySuggestions($q, $scored, $channelId, $locale);

        // 6) Intent detection (Amazon-style: show department/brand quick links)
        $intent = $this->detectIntent($brands, $categories, $q, $didYouMean);

        // 7) Quick links list (for SEO-friendly UI)
        $quickLinks = [];
        foreach ($categories as $c) $quickLinks[] = ['type' => 'category', 'label' => $c['name'], 'url' => $c['url']];
        foreach ($brands as $b)     $quickLinks[] = ['type' => 'brand',    'label' => $b['name'], 'url' => $b['url']];
        $quickLinks = array_slice($quickLinks, 0, self::MAX_SUGGESTIONS);

        // Effective query: if we have a strong did_you_mean, use it first in fallback chain,
        // but we still try the original query first in product search.
        $effectiveQuery = $productTerms[0] ?? ($didYouMean ?: $q);

        return [
            'request_id'        => $requestId,
            'effective_query'   => $effectiveQuery,
            'did_you_mean'      => $didYouMean,
            'intent'            => $intent,
            'brands'            => $brands,
            'categories'        => $categories,
            'product_terms'     => $productTerms,
            'query_suggestions' => $querySuggestions,
            'quick_links'       => $quickLinks,
        ];
    }

    /**
     * Candidate pull: only index-friendly conditions.
     * Works well on millions of rows because `normalized` is indexed and LIKE 'abc%' uses it.
     */
    private function vectorCandidates(string $q)
    {
        $len = mb_strlen($q);

        $p3 = $len >= 3 ? mb_substr($q, 0, 3) : $q;
        $p2 = $len >= 2 ? mb_substr($q, 0, 2) : $q;

        return DB::table('search_vectors')
            ->select(['id', 'type', 'target', 'term', 'normalized', 'weight', 'clicks'])
            ->where(function ($qq) use ($q, $p3, $p2) {
                $qq->where('normalized', $q)
                   ->orWhere('normalized', 'like', $q . '%')
                   ->orWhere('normalized', 'like', $p3 . '%')
                   ->orWhere('normalized', 'like', $p2 . '%');
            })
            ->orderByRaw(
                "CASE
                    WHEN normalized = ? THEN 4
                    WHEN normalized LIKE ? THEN 3
                    WHEN normalized LIKE ? THEN 2
                    WHEN normalized LIKE ? THEN 1
                    ELSE 0
                 END DESC",
                [$q, $q.'%', $p3.'%', $p2.'%']
            )
            ->orderByDesc('weight')
            ->orderByDesc('clicks')
            ->limit(self::VECTOR_LIMIT)
            ->get();
    }

    private function scoreCandidates(string $q, $rows): array
    {
        $len = mb_strlen($q);

        // Dynamic tolerance like Amazon: short queries are strict, longer allow more typos.
        $maxDist = match (true) {
            $len <= 4  => 1,
            $len <= 7  => 2,
            $len <= 12 => 3,
            default    => 4,
        };

        $scored = [];

        foreach ($rows as $row) {
            $norm = (string) $row->normalized;
            if ($norm === '') continue;

            $dist = levenshtein($q, $norm);

            // Hard cutoff (prevents “wrong” suggestions)
            if ($dist > $maxDist) continue;

            $weight = (int) $row->weight;
            $clicks = (int) $row->clicks;

            $exactBoost  = ($norm === $q) ? 800 : 0;
            $prefixBoost = (str_starts_with($norm, $q) || str_starts_with($q, $norm)) ? 250 : 0;

            // Score: weight dominates, clicks help, distance penalizes
            $score =
                $exactBoost +
                $prefixBoost +
                ($weight * 100) +
                (min($clicks, 300) * 2) -
                ($dist * 80);

            $scored[] = [
                'id'         => (int) $row->id,
                'type'       => (string) $row->type,
                'target'     => (string) $row->target,
                'term'       => (string) $row->term,
                'normalized' => $norm,
                'distance'   => $dist,
                'score'      => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    private function chooseDidYouMean(string $q, array $scored): ?string
    {
        foreach ($scored as $r) {
            // Prefer concept/product-like term as correction
            if (!in_array($r['type'], ['concept', 'product', 'category'], true)) continue;

            $candidate = $r['normalized'];
            if ($candidate !== '' && $candidate !== $q && $r['distance'] <= 2 && $r['score'] >= 600) {
                return $candidate;
            }
        }

        return null;
    }

    private function buildQuerySuggestions(string $q, array $scored, int $channelId, string $locale): array
    {
        $out = [];

        // A) from vectors (concept/product/category terms)
        foreach ($scored as $r) {
            $t = $r['term'] ?: $r['normalized'];
            if ($t !== '' && $t !== $q) $out[] = $t;
            if (count($out) >= self::MAX_QUERY_SUGGESTIONS) break;
        }

        // B) from search_terms (popular queries)
        // NOTE: search_terms is usually smaller than products, safe to query.
        try {
            $popular = DB::table('search_terms')
                ->where('channel_id', $channelId)
                ->where('locale', $locale)
                ->where('term', 'like', $q . '%')
                ->orderByDesc('uses')
                ->limit(self::MAX_QUERY_SUGGESTIONS)
                ->pluck('term')
                ->all();

            $out = array_merge($out, $popular);
        } catch (\Throwable $e) {
            // ignore
        }

        $out = $this->dedupeStrings($out);

        // Keep it small & clean
        return array_slice($out, 0, self::MAX_QUERY_SUGGESTIONS);
    }

    private function detectIntent(array $brands, array $categories, string $q, ?string $didYouMean): array
    {
        // Simple Amazon-like intent:
        // - if top brand exists and is very strong -> brand
        // - else if top category strong -> category
        // - else -> product search
        $bestBrandScore = $brands[0]['score'] ?? 0;
        $bestCatScore   = $categories[0]['score'] ?? 0;

        if ($bestBrandScore >= 900) {
            return [
                'type' => 'brand',
                'confidence' => 0.9,
                'primary' => $brands[0],
            ];
        }

        if ($bestCatScore >= 900) {
            return [
                'type' => 'category',
                'confidence' => 0.85,
                'primary' => $categories[0],
            ];
        }

        // If spelling correction exists, show “did you mean” intent hint
        if ($didYouMean && $didYouMean !== $q) {
            return [
                'type' => 'corrected_product',
                'confidence' => 0.7,
                'primary' => [
                    'label' => $didYouMean,
                    'url' => '/products?query=' . rawurlencode($didYouMean),
                ],
            ];
        }

        return [
            'type' => 'product',
            'confidence' => 0.6,
            'primary' => [
                'label' => $q,
                'url' => '/products?query=' . rawurlencode($q),
            ],
        ];
    }

    /* ============================================================
       Product search with fallbacks
       ============================================================ */

    private function buildProductTermCandidates(string $q, string $effective, ?string $didYouMean, array $vectorTerms): array
    {
        $cands = [];

        // Always try what user typed first (Amazon does this)
        $cands[] = $q;

        // Then try spelling correction (if exists)
        if ($didYouMean && $didYouMean !== $q) {
            $cands[] = $didYouMean;
        }

        // Then top vector-based term
        if ($effective !== '' && $effective !== $q) {
            $cands[] = $effective;
        }

        // Then all vector product_terms
        foreach ($vectorTerms as $t) {
            $t = $this->normalize((string) $t);
            if ($t !== '') $cands[] = $t;
        }

        // Extra variants (diacritics + one-char trim + duplicate char trim)
        foreach ($this->extraVariants($q) as $v) $cands[] = $v;

        $cands = $this->dedupeStrings($cands);

        return array_slice($cands, 0, self::MAX_PRODUCT_TERMS_TO_TRY);
    }

    private function searchProductsWithFallbacks(array $terms, array $baseParams): array
    {
        $engine = 'database';

        foreach ($terms as $term) {
            $params = array_merge($baseParams, [
                'q'     => $term,
                'query' => $term,
            ]);

            $result = $this->productRepository
                ->setSearchEngine($engine)
                ->getAll($params);

            $total = method_exists($result, 'total')
                ? (int) $result->total()
                : (is_countable($result) ? count($result) : 0);

            if ($total > 0) {
                return [$result, $term];
            }
        }

        // nothing found: return last attempt result (shape consistent)
        $fallback = $terms[0] ?? '';
        $params = array_merge($baseParams, ['q' => $fallback, 'query' => $fallback]);

        $result = $this->productRepository
            ->setSearchEngine($engine)
            ->getAll($params);

        return [$result, $fallback];
    }

    private function buildPaginationMeta($products, Request $request): array
    {
        // Bagisto typically returns a paginator for getAll()
        if (method_exists($products, 'total')) {
            return [
                'total'        => (int) $products->total(),
                'per_page'     => (int) $products->perPage(),
                'current_page' => (int) $products->currentPage(),
                'last_page'    => (int) $products->lastPage(),
            ];
        }

        $limit = (int) ($request->query('limit') ?? $request->query('per_page') ?? 40);
        $count = is_countable($products) ? count($products) : 0;

        return [
            'total'        => $count,
            'per_page'     => $limit,
            'current_page' => 1,
            'last_page'    => 1,
        ];
    }

    /* ============================================================
       Click-based learning (same endpoint, optional params)
       ============================================================ */

    private function handleClickLearning(Request $request, int $channelId, string $locale, string $q): void
    {
        $type   = (string) $request->query('clicked_type', '');
        $target = (string) $request->query('clicked_target', '');

        if ($type === '' || $target === '') return;

        if (!in_array($type, ['brand', 'category', 'product', 'concept'], true)) return;

        // Simple rate-limit key per IP + target
        $ip = (string) ($request->ip() ?? '0.0.0.0');
        $key = 'search:click:' . sha1($ip . '|' . $type . '|' . $target);

        if (Cache::has($key)) return;
        Cache::put($key, 1, self::CLICK_RATE_LIMIT_SECONDS);

        try {
            DB::table('search_vectors')
                ->where('type', $type)
                ->where('target', $target)
                ->increment('clicks', 1);
        } catch (\Throwable $e) {
            // ignore click failures
        }
    }

    /* ============================================================
       Helpers
       ============================================================ */

    private function normalize(?string $value): string
    {
        $v = trim((string) $value);
        $v = preg_replace('/\s+/u', ' ', $v);
        return trim(mb_strtolower((string) $v));
    }

    private function extraVariants(string $q): array
    {
        $out = [];

        // diacritics removal (ë -> e, ç -> c)
        $out[] = $this->stripDiacritics($q);

        $len = mb_strlen($q);

        // one-char trim (lopta -> lopt)
        if ($len >= 4) $out[] = mb_substr($q, 0, $len - 1);

        // duplicate last char trim (shovelss -> shovels)
        if ($len >= 3) {
            $last = mb_substr($q, -1);
            $prev = mb_substr($q, -2, 1);
            if ($last === $prev) $out[] = mb_substr($q, 0, $len - 1);
        }

        // basic plural-ish trim for latin 's' (optional)
        if ($len >= 4 && mb_substr($q, -1) === 's') {
            $out[] = mb_substr($q, 0, $len - 1);
        }

        $out = array_map([$this, 'normalize'], $out);
        return $this->dedupeStrings($out);
    }

    private function stripDiacritics(string $s): string
    {
        $map = [
            'ë' => 'e', 'Ë' => 'e',
            'ç' => 'c', 'Ç' => 'c',
            'á' => 'a','à' => 'a','â' => 'a','ä' => 'a','ã' => 'a',
            'é' => 'e','è' => 'e','ê' => 'e','ë' => 'e',
            'í' => 'i','ì' => 'i','î' => 'i','ï' => 'i',
            'ó' => 'o','ò' => 'o','ô' => 'o','ö' => 'o','õ' => 'o',
            'ú' => 'u','ù' => 'u','û' => 'u','ü' => 'u',
            'ý' => 'y','ÿ' => 'y',
        ];

        return strtr($s, $map);
    }

    private function dedupeStrings(array $items): array
    {
        $items = array_filter(array_map('strval', $items), fn ($x) => $x !== '');
        return array_values(array_unique($items));
    }

    private function dedupeObjectsByKey(array $items, string $key): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $item) {
            $k = (string) ($item[$key] ?? '');
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $item;
        }

        return $out;
    }

    private function logSearchTerm(string $q, int $channelId, string $locale, int $results): void
    {
        try {
            $existing = $this->searchTermRepository->findOneWhere([
                'term'       => $q,
                'channel_id' => $channelId,
                'locale'     => $locale,
            ]);

            if ($existing) {
                $existing->results = $results;
                $existing->increment('uses');
                $existing->save();
                return;
            }

            $this->searchTermRepository->create([
                'term'       => $q,
                'results'    => $results,
                'uses'       => 1,
                'locale'     => $locale,
                'channel_id' => $channelId,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
