<?php

namespace YiiComponents\TreePageCache;

use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\helpers\FileHelper;

/**
 * Tree-structured file storage for full-page HTML cache entries.
 *
 * Colon-separated logical paths map to files: `a:b:c` → `{cachePath}/a/b/c.cache`
 */
class TreePageCache extends Component
{
    /**
     * @var string Root directory for cache files (supports Yii aliases).
     */
    public $cachePath = '@runtime/tree-page-cache';

    /**
     * @var int Default TTL in seconds.
     */
    public $defaultDuration = 3600;

    /**
     * @var int File permission bits for created cache files (octal).
     */
    public $filePermission = 0644;

    /**
     * @var int Directory permission bits for created directories (octal).
     */
    public $dirPermission = 0755;

    /**
     * @var int GC runs when random_int(1, gcDivisor) <= gcProbability.
     */
    public $gcProbability = 10;

    /**
     * @var int Divisor for probabilistic GC.
     */
    public $gcDivisor = 10000;

    /**
     * @var int Serialized entry format version; bump when structure changes.
     */
    public $cacheVersion = 1;

    /**
     * @var string Placeholder used in stored HTML for CSRF (filter replaces on read).
     */
    public const CSRF_PLACEHOLDER = '%%CSRF_TOKEN%%';

    /**
     * @var string Resolved absolute filesystem path to cache root.
     */
    private $_resolvedPath;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        $this->_resolvedPath = Yii::getAlias($this->cachePath);
        if ($this->_resolvedPath === false || $this->_resolvedPath === '') {
            throw new InvalidArgumentException('Invalid cachePath: ' . $this->cachePath);
        }
        if (!is_dir($this->_resolvedPath)) {
            FileHelper::createDirectory($this->_resolvedPath, $this->dirPermission);
        }
    }

    /**
     * @return string Absolute path to cache root.
     */
    public function getResolvedPath()
    {
        return $this->_resolvedPath;
    }

    /**
     * Read a cache entry; returns false if missing, expired, or invalid.
     *
     * @param string $path Colon-separated logical path (e.g. `pageCache:goods:detail:1`).
     * @return array<string, mixed>|false
     */
    public function get($path)
    {
        $this->maybeGc();

        $file = $this->buildFilePath($path);
        if (!is_file($file)) {
            return false;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return false;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data)) {
            @unlink($file);
            return false;
        }

        if (!isset($data['version']) || (int) $data['version'] !== (int) $this->cacheVersion) {
            @unlink($file);
            return false;
        }

        if (!isset($data['expireAt']) || !is_int($data['expireAt'])) {
            @unlink($file);
            return false;
        }

        if ($data['expireAt'] !== 0 && $data['expireAt'] < time()) {
            @unlink($file);
            return false;
        }

        return $data;
    }

    /**
     * Write a cache entry with exclusive lock.
     *
     * @param string $path Colon-separated logical path.
     * @param array<string, mixed> $data Must include at least `content`, `statusCode`; optional `headers`.
     * @param int|null $duration TTL in seconds; null uses [[defaultDuration]]. Use 0 for no expiry (stored as expireAt 0).
     * @return bool
     */
    public function set($path, array $data, $duration = null)
    {
        if ($duration === null) {
            $duration = (int) $this->defaultDuration;
        } else {
            $duration = (int) $duration;
        }

        $now = time();
        $expireAt = $duration === 0 ? 0 : $now + $duration;

        $payload = array_merge($data, [
            'version' => (int) $this->cacheVersion,
            'expireAt' => $expireAt,
            'createdAt' => $now,
        ]);

        $file = $this->buildFilePath($path);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            FileHelper::createDirectory($dir, $this->dirPermission);
        }

        $serialized = serialize($payload);
        $tmp = $file . '.' . uniqid('tmp', true);

        $fp = @fopen($tmp, 'xb');
        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            @unlink($tmp);
            return false;
        }

        $written = fwrite($fp, $serialized);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($written === false || $written !== strlen($serialized)) {
            @unlink($tmp);
            return false;
        }

        if (is_file($file)) {
            @unlink($file);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        @chmod($file, $this->filePermission);

        $this->maybeGc();

        return true;
    }

    /**
     * Delete one cache file for the given logical path.
     *
     * @param string $path Colon-separated logical path.
     * @return bool True if the file existed and was removed, or if already absent (idempotent).
     */
    public function invalidate($path)
    {
        $file = $this->buildFilePath($path);
        if (is_file($file)) {
            return @unlink($file);
        }
        return true;
    }

    /**
     * Remove the subtree for this prefix and the exact key file if present.
     *
     * Example: `pageCache:goods` removes `{root}/pageCache/goods/` recursively and `goods.cache` sibling logic:
     * actually file is `{root}/pageCache/goods.cache` and dir `{root}/pageCache/goods/`.
     *
     * @param string $path Colon-separated prefix.
     * @return bool
     */
    public function invalidateTree($path)
    {
        $segments = $this->parseAndSanitizePath($path);
        if ($segments === []) {
            return false;
        }

        $file = $this->buildFilePathFromSegments($segments);
        $ok = true;
        if (is_file($file)) {
            $ok = @unlink($file) && $ok;
        }

        $dir = $this->_resolvedPath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        if (is_dir($dir)) {
            try {
                FileHelper::removeDirectory($dir);
            } catch (\Throwable $e) {
                Yii::warning($e->getMessage(), __METHOD__);
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Remove all files under the cache root.
     *
     * @return bool
     */
    public function invalidateAll()
    {
        if (!is_dir($this->_resolvedPath)) {
            return true;
        }
        try {
            foreach (scandir($this->_resolvedPath) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = $this->_resolvedPath . DIRECTORY_SEPARATOR . $item;
                if (is_dir($full)) {
                    FileHelper::removeDirectory($full);
                } else {
                    @unlink($full);
                }
            }
        } catch (\Throwable $e) {
            Yii::warning($e->getMessage(), __METHOD__);
            return false;
        }
        return true;
    }

    /**
     * Delete expired `.cache` files under a subtree or entire cache.
     *
     * @param string|null $path Optional colon-separated prefix; null = entire tree.
     * @return int Number of files removed.
     */
    public function gc($path = null)
    {
        $base = $this->_resolvedPath;
        if ($path !== null && $path !== '') {
            $segments = $this->parseAndSanitizePath($path);
            if ($segments === []) {
                return 0;
            }
            $base = $this->_resolvedPath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        }

        if (is_dir($base)) {
            $removed = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) {
                    continue;
                }
                if (substr($fileInfo->getFilename(), -6) !== '.cache') {
                    continue;
                }
                if ($this->removeIfExpired($fileInfo->getPathname())) {
                    $removed++;
                }
            }
            return $removed;
        }

        // Prefix may refer to a single `.cache` file (no subdirectory with same name).
        $cacheFile = $base . '.cache';
        if (is_file($cacheFile)) {
            return $this->removeIfExpired($cacheFile) ? 1 : 0;
        }

        return 0;
    }

    /**
     * Build absolute filesystem path to the `.cache` file for a logical path.
     *
     * @param string $path Colon-separated path.
     * @return string
     */
    public function buildFilePath($path)
    {
        $segments = $this->parseAndSanitizePath($path);
        if ($segments === []) {
            throw new InvalidArgumentException('Cache path cannot be empty.');
        }
        return $this->buildFilePathFromSegments($segments);
    }

    /**
     * @param string[] $segments Sanitized non-empty segments.
     */
    protected function buildFilePathFromSegments(array $segments)
    {
        $last = array_pop($segments);
        $relative = $segments === []
            ? $last . '.cache'
            : implode(DIRECTORY_SEPARATOR, $segments) . DIRECTORY_SEPARATOR . $last . '.cache';
        return $this->_resolvedPath . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * @return string[]
     */
    protected function parseAndSanitizePath($path)
    {
        if (!is_string($path) || $path === '') {
            return [];
        }
        $parts = explode(':', $path);
        $segments = [];
        foreach ($parts as $part) {
            $s = $this->sanitizeSegment($part);
            if ($s !== '') {
                $segments[] = $s;
            }
        }
        return $segments;
    }

    /**
     * @param string $segment Single path segment (before colon split).
     * @return string Sanitized segment or empty if invalid.
     */
    protected function sanitizeSegment($segment)
    {
        $s = str_replace(["\0", '/', '\\'], '', $segment);
        $s = preg_replace('#\.\.+#', '', $s);
        return trim($s);
    }

    /**
     * @param string $file Absolute path to a `.cache` file.
     */
    protected function removeIfExpired($file)
    {
        if (!is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            @unlink($file);
            return true;
        }
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || !isset($data['expireAt'], $data['version'])) {
            @unlink($file);
            return true;
        }
        if ((int) $data['version'] !== (int) $this->cacheVersion) {
            @unlink($file);
            return true;
        }
        $expireAt = (int) $data['expireAt'];
        if ($expireAt !== 0 && $expireAt < time()) {
            @unlink($file);
            return true;
        }
        return false;
    }

    protected function maybeGc()
    {
        if ($this->gcDivisor < 1) {
            return;
        }
        if ($this->gcProbability < 1) {
            return;
        }
        try {
            if (random_int(1, $this->gcDivisor) <= $this->gcProbability) {
                $this->gc();
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
