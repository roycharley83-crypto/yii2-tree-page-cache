<?php

namespace YiiComponents\TreePageCache;

use Yii;
use yii\base\ActionFilter;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\web\Response;

/**
 * Full-page cache filter using [[TreePageCache]] tree file storage.
 *
 * Configure [[cacheKey]] to build a colon-separated logical path from the current request.
 */
class TreePageCacheFilter extends ActionFilter
{
    /**
     * @var string|array|TreePageCache Application component ID, config, or instance.
     */
    public $cache = 'treePageCache';

    /**
     * @var int|null TTL in seconds; null uses the component's [[TreePageCache::defaultDuration]].
     */
    public $duration;

    /**
     * @var callable Function `(Action $action): string` returning a colon-separated cache path.
     */
    public $cacheKey;

    /**
     * @var bool|callable Whether caching is enabled. Callable returns bool.
     */
    public $enabled = true;

    /**
     * @var bool|array `false` — do not cache headers. `true` — cache all. Array — header names only.
     */
    public $cacheHeaders = false;

    /**
     * @var bool Replace CSRF masked token in HTML with a placeholder when storing, restore on read.
     */
    public $enableCsrfReplacement = true;

    /**
     * @var TreePageCache|null
     */
    private $_cache;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        if ($this->cacheKey === null || !is_callable($this->cacheKey)) {
            throw new InvalidConfigException('TreePageCacheFilter::cacheKey must be a callable.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function beforeAction($action)
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $cache = $this->getCache();
        $path = call_user_func($this->cacheKey, $action);
        if ($path === null || $path === '') {
            return true;
        }

        $data = $cache->get($path);
        if ($data !== false) {
            $this->restoreResponse($data);
            return false;
        }

        $response = Yii::$app->getResponse();
        $response->on(
            Response::EVENT_BEFORE_SEND,
            [$this, 'cacheResponse'],
            ['path' => $path]
        );

        return true;
    }

    /**
     * Runs before response is sent; stores response content in the tree cache.
     *
     * @param \yii\base\Event $event
     */
    public function cacheResponse($event)
    {
        $path = $event->data['path'] ?? null;
        if ($path === null || $path === '') {
            return;
        }

        $response = Yii::$app->getResponse();
        $response->off(Response::EVENT_BEFORE_SEND, [$this, 'cacheResponse']);

        $content = (string) $response->content;
        if ($content === '') {
            return;
        }

        if ((int) $response->statusCode !== 200) {
            return;
        }

        $cache = $this->getCache();
        $contentToStore = $content;

        if (
            $this->enableCsrfReplacement
            && Yii::$app->has('request', true)
            && Yii::$app->request->enableCsrfValidation
        ) {
            $token = Yii::$app->request->getCsrfToken();
            $contentToStore = $this->replaceCsrfToken($contentToStore, $token);
        }

        $payload = [
            'content' => $contentToStore,
            'statusCode' => $response->statusCode,
            'statusText' => $response->statusText,
            'format' => $response->format,
            'headers' => $this->serializeHeaders($response),
        ];

        $duration = $this->duration !== null ? $this->duration : $cache->defaultDuration;
        $cache->set($path, $payload, $duration);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function restoreResponse(array $data)
    {
        $response = Yii::$app->getResponse();

        $response->format = $data['format'] ?? Response::FORMAT_HTML;
        $response->statusCode = isset($data['statusCode']) ? (int) $data['statusCode'] : 200;
        $response->statusText = $data['statusText'] ?? '';

        $content = (string) ($data['content'] ?? '');
        if (
            $this->enableCsrfReplacement
            && Yii::$app->has('request', true)
            && Yii::$app->request->enableCsrfValidation
        ) {
            $content = $this->restoreCsrfToken($content);
        }

        $response->content = $content;

        if (!empty($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $name => $values) {
                $normalized = (array) $values;
                if ($normalized === []) {
                    continue;
                }
                $response->headers->set($name, $normalized);
            }
        }
    }

    /**
     * @return TreePageCache
     */
    protected function getCache()
    {
        if ($this->_cache === null) {
            $this->_cache = Instance::ensure($this->cache, TreePageCache::class);
        }
        return $this->_cache;
    }

    /**
     * @return bool
     */
    protected function isEnabled()
    {
        if ($this->enabled instanceof \Closure) {
            return (bool) call_user_func($this->enabled);
        }
        if (is_array($this->enabled) && is_callable($this->enabled, true)) {
            return (bool) call_user_func($this->enabled);
        }
        return (bool) $this->enabled;
    }

    /**
     * @param Response $response
     * @return array<string, array<int, string>>
     */
    protected function serializeHeaders($response)
    {
        if ($this->cacheHeaders === false) {
            return [];
        }

        $headers = $response->getHeaders();
        $out = [];

        if ($this->cacheHeaders === true) {
            foreach ($headers->toArray() as $name => $values) {
                $out[$name] = is_array($values) ? $values : [$values];
            }
        } elseif (is_array($this->cacheHeaders)) {
            foreach ($this->cacheHeaders as $name) {
                if ($headers->has($name)) {
                    $out[$name] = (array) $headers->get($name, [], false);
                }
            }
        }

        return $out;
    }

    /**
     * Replace current masked CSRF token with placeholder for storage.
     *
     * @param string $content
     * @param string $token Masked token from [[\yii\web\Request::getCsrfToken()]].
     * @return string
     */
    protected function replaceCsrfToken($content, $token)
    {
        if ($token === '') {
            return $content;
        }
        return str_replace($token, TreePageCache::CSRF_PLACEHOLDER, $content);
    }

    /**
     * Replace placeholder with the masked token for the current request.
     *
     * @param string $content
     * @return string
     */
    protected function restoreCsrfToken($content)
    {
        $token = Yii::$app->request->getCsrfToken();
        if ($token === '') {
            return $content;
        }
        return str_replace(TreePageCache::CSRF_PLACEHOLDER, $token, $content);
    }
}
