<?php

declare(strict_types=1);

namespace Lateo\Explorer\Config;

/**
 * The validated settings of the explorer. Load them with Config::load() from a settings file
 * (see settings.example.php) or build them from an array with Config::fromArray().
 *
 * Validation never puts a value into a message: node addresses are private.
 */
final readonly class Config
{
    private const TOP_KEYS = ['nodes', 'database', 'cache_dir', 'lock_file', 'limits'];
    private const NODE_KEYS = ['label', 'kind', 'url'];

    /**
     * @param list<NodeSpec> $nodes in settings order
     */
    private function __construct(
        public array $nodes,
        public string $database,
        public string $cacheDir,
        public string $lockFile,
        public Limits $limits,
    ) {
    }

    /** Load a settings file that returns an array. */
    public static function load(string $file): self
    {
        if (!file_exists($file)) {
            throw new ConfigException('settings file not found');
        }
        $data = (static fn (string $f): mixed => require $f)($file);
        if (!is_array($data)) {
            throw new ConfigException('settings file must return an array');
        }
        return self::fromArray($data);
    }

    /** @param array<mixed> $s */
    public static function fromArray(array $s): self
    {
        foreach (array_keys($s) as $key) {
            if (!in_array($key, self::TOP_KEYS, true)) {
                throw new ConfigException('unknown setting ' . self::shown($key));
            }
        }
        foreach (['nodes', 'database', 'cache_dir', 'lock_file'] as $required) {
            if (!array_key_exists($required, $s)) {
                throw new ConfigException('missing setting ' . $required);
            }
        }

        $nodes = self::nodes($s['nodes']);
        return new self(
            $nodes,
            self::path($s['database'], 'database'),
            self::path($s['cache_dir'], 'cache_dir'),
            self::path($s['lock_file'], 'lock_file'),
            self::limits($s['limits'] ?? []),
        );
    }

    /** @return list<NodeSpec> own nodes in settings order */
    public function ownNodes(): array
    {
        return array_values(array_filter($this->nodes, static fn (NodeSpec $n): bool => !$n->isFallback()));
    }

    /** @return list<NodeSpec> */
    public function fallbackNodes(): array
    {
        return array_values(array_filter($this->nodes, static fn (NodeSpec $n): bool => $n->isFallback()));
    }

    /** @return list<NodeSpec> own nodes in settings order, then the fallback */
    public function orderedNodes(): array
    {
        return [...$this->ownNodes(), ...$this->fallbackNodes()];
    }

    public function node(string $label): ?NodeSpec
    {
        foreach ($this->nodes as $n) {
            if ($n->label === $label) {
                return $n;
            }
        }
        return null;
    }

    /** @return list<NodeSpec> */
    private static function nodes(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new ConfigException('nodes must be a non-empty list');
        }
        $nodes = [];
        $labels = [];
        foreach ($raw as $i => $entry) {
            $where = 'nodes[' . $i . ']';
            if (!is_array($entry)) {
                throw new ConfigException($where . ' must be an array');
            }
            foreach (array_keys($entry) as $key) {
                if (!in_array($key, self::NODE_KEYS, true)) {
                    throw new ConfigException($where . ': unknown key ' . self::shown($key));
                }
            }
            $label = $entry['label'] ?? null;
            if (!is_string($label) || preg_match('/\A[a-z0-9-]{1,20}\z/', $label) !== 1) {
                throw new ConfigException($where . '.label must match [a-z0-9-]{1,20}');
            }
            if (isset($labels[$label])) {
                throw new ConfigException($where . '.label is used twice');
            }
            $labels[$label] = true;
            $kind = is_string($entry['kind'] ?? null) ? NodeKind::tryFrom($entry['kind']) : null;
            if ($kind === null) {
                throw new ConfigException($where . '.kind must be own or fallback');
            }
            $nodes[] = new NodeSpec($label, $kind, self::url($entry['url'] ?? null, $where . '.url'));
        }
        $own = array_filter($nodes, static fn (NodeSpec $n): bool => !$n->isFallback());
        if ($own === []) {
            throw new ConfigException('at least one node of kind own is required');
        }
        return $nodes;
    }

    private static function url(mixed $url, string $where): string
    {
        if (!is_string($url) || preg_match('/\A[\x21-\x7e]{1,300}\z/', $url) !== 1) {
            throw new ConfigException($where . ' must be a printable url');
        }
        $parts = parse_url($url);
        if ($parts === false) {
            throw new ConfigException($where . ' is not a valid url');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ConfigException($where . ': scheme must be http or https');
        }
        if (($parts['host'] ?? '') === '') {
            throw new ConfigException($where . ' needs a host');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigException($where . ' must not contain a user or password');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigException($where . ' must not contain a query or fragment');
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            throw new ConfigException($where . ' must not contain a path');
        }
        return rtrim($url, '/');
    }

    private static function path(mixed $path, string $name): string
    {
        if (
            !is_string($path)
            || $path === ''
            || $path[0] !== '/'
            || str_contains($path, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $path) === 1
        ) {
            throw new ConfigException($name . ' must be an absolute path');
        }
        return $path;
    }

    private static function limits(mixed $raw): Limits
    {
        if (!is_array($raw)) {
            throw new ConfigException('limits must be an array');
        }
        $known = [
            'page_max_calls', 'fallback_calls_per_minute', 'header_batch', 'backfill_pause_seconds',
            'run_seconds', 'reorg_max_depth', 'unsettled_depth',
        ];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $known, true)) {
                throw new ConfigException('limits: unknown key ' . self::shown($key));
            }
        }
        $d = new Limits();
        $limits = new Limits(
            self::intSetting($raw, 'page_max_calls', $d->pageMaxCalls, 1, 20),
            self::intSetting($raw, 'fallback_calls_per_minute', $d->fallbackCallsPerMinute, 1, 300),
            self::intSetting($raw, 'header_batch', $d->headerBatch, 1, 1000),
            self::floatSetting($raw, 'backfill_pause_seconds', $d->backfillPauseSeconds, 0.0, 60.0),
            self::intSetting($raw, 'run_seconds', $d->runSeconds, 1, 3600),
            self::intSetting($raw, 'reorg_max_depth', $d->reorgMaxDepth, 1, 10000),
            self::intSetting($raw, 'unsettled_depth', $d->unsettledDepth, 0, 10000),
        );
        if ($limits->unsettledDepth > $limits->reorgMaxDepth) {
            throw new ConfigException('limits.unsettled_depth must not exceed reorg_max_depth');
        }
        return $limits;
    }

    /** @param array<mixed> $raw */
    private static function intSetting(array $raw, string $key, int $default, int $min, int $max): int
    {
        $v = $raw[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new ConfigException("limits.$key must be an integer from $min to $max");
        }
        return $v;
    }

    /** @param array<mixed> $raw */
    private static function floatSetting(array $raw, string $key, float $default, float $min, float $max): float
    {
        $v = $raw[$key] ?? $default;
        if ((!is_int($v) && !is_float($v)) || is_nan((float) $v) || $v < $min || $v > $max) {
            throw new ConfigException("limits.$key must be a number from $min to $max");
        }
        return (float) $v;
    }

    private static function shown(int|string $key): string
    {
        $s = (string) $key;
        return preg_match('/\A[A-Za-z0-9_]{1,40}\z/', $s) === 1 ? $s : '(invalid)';
    }
}
