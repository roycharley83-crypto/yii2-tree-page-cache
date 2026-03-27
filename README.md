# Yii2 Tree Page Cache

一个基于文件系统的 Yii2 页面缓存组件，按“树状路径”存储缓存文件，支持：

- 按单个路径失效
- 按路径前缀（子树）失效
- TTL 过期自动失效（懒删除 + 概率 GC）
- 针对 CSRF Token 的占位符替换，降低全页缓存导致的 token 失效风险

适用于你希望自定义缓存 Key 结构、并需要精细化失效控制的页面缓存场景。

---

## 1. 功能概览

- **树状存储**
  - 逻辑路径采用 `:` 分隔，例如 `pageCache:goods:detail:1:red`
  - 自动映射为文件路径：`{cachePath}/pageCache/goods/detail/1/red.cache`
- **多级失效**
  - `invalidate($path)`：失效单个文件
  - `invalidateTree($prefix)`：失效某个前缀下整棵子树 + 同名前缀文件
  - `invalidateAll()`：清空整个缓存目录
- **过期处理**
  - 每条缓存存储 `expireAt`
  - `get()` 时过期自动删除
  - `maybeGc()` 以概率触发 `gc()` 清理过期文件
- **CSRF 处理**
  - 写入缓存前把当前 masked token 替换成 `%%CSRF_TOKEN%%`
  - 命中缓存时再用当前请求 token 回填

---

## 2. 环境要求

- PHP `>=7.4`
- Yii2 `~2.0.45`

`composer.json` 已定义：

```json
{
  "require": {
    "php": ">=7.4",
    "yiisoft/yii2": "~2.0.45"
  },
  "autoload": {
    "psr-4": {
      "YiiComponents\\TreePageCache\\": "src/"
    }
  }
}
```

---

## 3. 安装方式

如果这是独立仓库，推荐通过 Composer path repository 或 VCS repository 引入。

### 3.1 Path Repository（本地联调）

在你的 Yii2 项目 `composer.json` 增加：

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../tree-page-cache"
    }
  ],
  "require": {
    "yii-components/tree-page-cache": "*"
  }
}
```

然后执行：

```bash
composer update yii-components/tree-page-cache
```

### 3.2 当前仓库开发

在本仓库内开发时，至少执行一次：

```bash
composer dump-autoload
```

---

## 4. 快速开始

### 4.1 注册组件（`config/web.php`）

```php
'components' => [
    'treePageCache' => [
        'class' => \YiiComponents\TreePageCache\TreePageCache::class,
        'cachePath' => '@runtime/tree-page-cache',
        'defaultDuration' => 3600,
        'gcProbability' => 10,
        'gcDivisor' => 10000,
        'filePermission' => 0644,
        'dirPermission' => 0755,
    ],
],
```

### 4.2 在控制器中启用过滤器

```php
public function behaviors()
{
    return [
        'pageCache' => [
            'class' => \YiiComponents\TreePageCache\TreePageCacheFilter::class,
            'cache' => 'treePageCache',
            'only' => ['index', 'detail'],
            'duration' => 3600,
            'enabled' => !YII_DEBUG,
            'cacheHeaders' => false, // true / ['Content-Type', ...]
            'enableCsrfReplacement' => true,
            'cacheKey' => function ($action) {
                $r = \Yii::$app->request;
                return implode(':', array_filter([
                    'pageCache',
                    \Yii::$app->language,
                    $action->controller->id,
                    $action->id,
                    $r->get('id'),
                    $r->get('color'),
                ], static function ($v) {
                    return $v !== null && $v !== '';
                }));
            },
        ],
    ];
}
```

---

## 5. 路径映射规则

逻辑路径最后一段作为文件名，其余段作为目录名。

示例：

- `pageCache:help:detail:shipping:delivery-time`
  - `{cachePath}/pageCache/help/detail/shipping/delivery-time.cache`
- `pageCache:index:index`
  - `{cachePath}/pageCache/index/index.cache`
- `pageCache:goods:detail:1`
  - `{cachePath}/pageCache/goods/detail/1.cache`
- `pageCache:goods:detail:1:red`
  - `{cachePath}/pageCache/goods/detail/1/red.cache`
- `pageCache:en:index:index`
  - `{cachePath}/pageCache/en/index/index.cache`

> 该设计允许 `1.cache` 和 `1/` 目录同时存在，互不冲突。

---

## 6. 失效策略

### 6.1 单条失效

```php
\Yii::$app->treePageCache->invalidate('pageCache:goods:detail:1');
```

只删除：

- `{cachePath}/pageCache/goods/detail/1.cache`

### 6.2 子树失效

```php
\Yii::$app->treePageCache->invalidateTree('pageCache:goods');
```

会删除：

- `{cachePath}/pageCache/goods.cache`（如果存在）
- `{cachePath}/pageCache/goods/` 目录整棵子树

### 6.3 全量失效

```php
\Yii::$app->treePageCache->invalidateAll();
```

---

## 7. 过期与 GC 机制

### 7.1 TTL

- `set()` 时记录 `expireAt`
- `duration = 0` 表示不过期（`expireAt = 0`）

### 7.2 懒删除

- `get()` 命中时会检查 `expireAt`
- 如果已过期，立即删除并返回 `false`

### 7.3 概率 GC

- 每次 `get()/set()` 都可能触发 `maybeGc()`
- 当 `random_int(1, gcDivisor) <= gcProbability` 时执行 `gc()`
- 默认约 0.1% 概率（`10/10000`）

可以在低峰期主动调用：

```php
\Yii::$app->treePageCache->gc();                 // 全量清理过期
\Yii::$app->treePageCache->gc('pageCache:goods'); // 指定前缀
```

---

## 8. CSRF Token 处理说明

Yii2 的 CSRF token 是 masked token，页面缓存会把某一时刻 token 固化到 HTML。组件通过占位符机制降低风险：

1. **写缓存前**
   - 从 `Yii::$app->request->getCsrfToken()` 获取 token
   - 把 HTML 中该 token 替换为 `%%CSRF_TOKEN%%`
2. **读缓存时**
   - 获取当前请求的 token
   - 将 `%%CSRF_TOKEN%%` 回填为新 token

通常会覆盖：

- 表单隐藏字段 `_csrf`
- `<meta name="csrf-token" ...>` 中的 token 值

可通过 `enableCsrfReplacement = false` 关闭。

---

## 9. `cacheKey` 设计建议（非常关键）

缓存命中正确性主要取决于 `cacheKey`。建议至少考虑：

- `language`（多语言站点）
- `controller/action`
- 关键业务参数（如 `id`, `slug`, `color`）
- 页面是否登录态相关（用户维度、角色维度）
- A/B 实验、客户端类型（如有）

推荐模式（你的示例）：

- `pageCache:help:detail:shipping:delivery-time`
- `pageCache:index:index`
- `pageCache:goods:detail:1`
- `pageCache:goods:detail:1:red`
- `pageCache:en:index:index`

如果页面内容与用户强相关，请把用户维度纳入 key，或不要对该页面做全页缓存。

---

## 10. 缓存数据结构

每个 `.cache` 文件保存序列化数组，典型结构：

```php
[
    'version'    => 1,
    'content'    => '...HTML with %%CSRF_TOKEN%%...',
    'statusCode' => 200,
    'statusText' => 'OK',
    'format'     => 'html',
    'headers'    => ['Content-Type' => ['text/html; charset=UTF-8']],
    'expireAt'   => 1711584000,
    'createdAt'  => 1711580400,
]
```

当 `version` 与当前 `cacheVersion` 不一致时，会自动判定失效并清除旧缓存。

---

## 11. 公开 API

`TreePageCache`：

- `get(string $path): array|false`
- `set(string $path, array $data, ?int $duration = null): bool`
- `invalidate(string $path): bool`
- `invalidateTree(string $path): bool`
- `invalidateAll(): bool`
- `gc(?string $path = null): int`
- `buildFilePath(string $path): string`
- `getResolvedPath(): string`

`TreePageCacheFilter` 关键配置项：

- `cache`：组件 ID / 配置 / 实例（默认 `treePageCache`）
- `cacheKey`：必填，可调用，返回逻辑路径
- `duration`：当前过滤器 TTL（为空时使用组件默认值）
- `enabled`：布尔值（当前实现同时支持 `Closure` / callable array）
- `cacheHeaders`：`false | true | string[]`
- `enableCsrfReplacement`：默认 `true`

---

## 12. 注意事项与最佳实践

1. **只缓存 200 且有内容的响应**
   - 当前过滤器已内置该行为
2. **避免缓存用户个性化页面**
   - 或将用户维度纳入 `cacheKey`
3. **确保缓存目录可写**
   - `cachePath` 对应目录需要 PHP 进程写权限
4. **路径会被基础净化**
   - 会去除 `\0`、`/`、`\`、`..`，防止目录穿越
5. **Header 缓存按需开启**
   - 默认 `false`，若业务需要再缓存特定 header
6. **发布变更可升级 `cacheVersion`**
   - 可快速淘汰旧格式缓存

---

## 13. 常见问题（FAQ）

### Q1：为什么我改了页面但还命中旧缓存？

- 可能 TTL 未过期
- 需要在内容变更点调用 `invalidate()` / `invalidateTree()`
- 或升级 `cacheVersion` 强制格式失效

### Q2：`invalidateTree('pageCache:goods')` 会删哪些？

- `pageCache/goods.cache`
- `pageCache/goods/` 下所有层级文件和目录

### Q3：CSRF 一定安全吗？

该组件已做 token 占位符替换，能覆盖大多数全页缓存导致的 token 固化问题。但如果页面还含有其他强动态敏感内容（用户信息、一次性 nonce 等），仍需额外策略（如不缓存整页或拆分动态片段）。

---

## 14. 目录结构

```text
tree-page-cache/
  composer.json
  README.md
  src/
    TreePageCache.php
    TreePageCacheFilter.php
```

---

## 15. License

BSD-3-Clause

