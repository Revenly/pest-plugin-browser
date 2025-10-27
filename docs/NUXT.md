# Pest Browser Nuxt Extension

A Pest Browser extension that adds seamless Nuxt.js integration for full-stack testing of Laravel + Nuxt applications.

## Architecture

### Three-Tier Server Architecture

The extension manages three independent servers through `ServerManager`:

1. **PlaywrightServer** - Browser automation (Playwright)
2. **HttpServer** - Backend API server (Laravel/Nullable)  
3. **NuxtNpmServer** - Frontend Nuxt application server

### Test Execution Flow

```php
test('example')->visitNuxt('/dashboard')
```

**Execution sequence:**
1. `UsesBrowserTestCaseMethodFilter` detects browser test
2. `__markAsNuxtBrowserTest()` proxy injected automatically
3. Servers bootstrap in order:
   - `ServerManager::playwright()->start()`
   - `ServerManager::http()->bootstrap()`
   - `ServerManager::nuxt()->bootstrap()`
4. `visitNuxt('/')` rewrites to `http://127.0.0.1:{port}/`
5. Environment variables injected into Nuxt process
6. Playwright navigates with proper waits

## Nuxt Server Implementation

### `NuxtNpmServer` Class

Implements `HttpServer` contract for consistency with existing server architecture:

```php
final class NuxtNpmServer implements HttpServer
{
    private readonly string $nuxtDirectory;
    private readonly HttpServer $httpServer;
    private ?SystemProcess $systemProcess = null;
}
```

**Key responsibilities:**
- Auto-discovers Nuxt projects by scanning for `nuxt.config.ts/js`
- Runs pre-built applications via `node .output/server/index.mjs`
- Manages process lifecycle (start/stop/bootstrap)
- Handles URL rewriting and environment injection

### Environment Variable Injection

```php
$env = [
    'NUXT_PUBLIC_BASE_URL' => $this->apiServerUrl,
    'NUXT_PUBLIC_SANCTUM_BASE_URL' => $this->apiServerUrl,
    'PORT' => (string) $this->port,
    'HOST' => $this->host,
    'NODE_ENV' => 'production',
];
```

### Auto-Discovery Logic

```php
private function findNuxtDirectory(): string
{
    $searchDirs = [
        getcwd().'/frontend',
        getcwd(),
        dirname(getcwd()),
        dirname(dirname(getcwd())),
    ];
    
    foreach ($searchDirs as $dir) {
        if (file_exists($dir.'/nuxt.config.ts') || 
            file_exists($dir.'/nuxt.config.js')) {
            return $dir;
        }
    }
}
```

## Integration Layer

### `Browsable` Trait

Provides `visitNuxt()` method for Nuxt-specific navigation:

```php
public function visitNuxt(array|string $url, array $options = []): ArrayablePendingAwaitablePage|PendingAwaitablePage
{
    $nuxt = ServerManager::instance()->nuxt();
    
    if (is_string($url)) {
        $nuxtUrl = $nuxt->rewrite($url);
    } else {
        $nuxtUrl = array_map(fn (string $singleUrl) => $nuxt->rewrite($singleUrl), $url);
    }
    
    $page = $this->visit($nuxtUrl, $options);
    $page->waitForEvent('domcontentloaded')->waitForEvent('networkidle')->wait(1);
    
    return $page;
}
```

### URL Rewriting

```php
public function rewrite(string $url): string
{
    if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
        $url = mb_ltrim($url, '/');
        $url = '/'.$url;
    }
    
    $parts = parse_url($url);
    $queryParameters = [];
    $path = $parts['path'] ?? '/';
    parse_str($parts['query'] ?? '', $queryParameters);
    
    return sprintf(
        'http://%s:%d%s%s',
        $this->host,
        $this->port,
        $path,
        ! empty($queryParameters) ? '?'.http_build_query($queryParameters) : ''
    );
}
```

## Server Management

### `ServerManager` Singleton

```php
final class ServerManager
{
    private ?PlaywrightServer $playwright = null;
    private ?HttpServer $http = null;
    private ?NuxtNpmServer $nuxt = null;
    
    public function nuxt(): HttpServer
    {
        $port = Port::find();
        
        $this->nuxt ??= NuxtNpmServer::create(
            self::DEFAULT_HOST,
            $port,
            $this->http(), // Pass HTTP server for API integration
        );
        
        return $this->nuxt;
    }
}
```

### Port Management

- **Dynamic allocation**: `Port::find()` ensures unique ports
- **Parallel testing**: Each worker gets independent server instances
- **Stateless design**: Nuxt servers require no flushing between tests

## Plugin Lifecycle

### Test Filter Integration

`UsesBrowserTestCaseMethodFilter` automatically:
- Detects browser tests using `BrowserTestIdentifier`
- Injects `__markAsNuxtBrowserTest()` proxy
- Starts servers on first browser test
- Handles parallel testing coordination

### Cleanup Management

```php
public function terminate(): void
{
    if (Parallel::isWorker() || Parallel::isEnabled() === false) {
        ServerManager::instance()->http()->stop();
        
        if (ServerManager::instance()->nuxt() !== null) {
            ServerManager::instance()->nuxt()->stop();
        }
        
        Playwright::close();
    }
}
```

## Usage

### Basic Nuxt Testing

```php
test('can visit Nuxt page')
    ->visitNuxt('/dashboard')
    ->assertSee('Dashboard');
```

### Multiple URLs

```php
test('can visit multiple pages')
    ->visitNuxt(['/dashboard', '/profile'])
    ->assertSee('Dashboard');
```

### With Authentication

```php
auth()->login($user, 'guard');
test('can access protected page')
    ->visitNuxt('/admin')
    ->assertSee('Admin Panel');
```

## Key Design Decisions

1. **Contract-based**: `NuxtNpmServer` implements `HttpServer` for consistency
2. **Auto-discovery**: Scans project structure to find Nuxt applications
3. **Environment injection**: Passes API server URL to Nuxt via environment variables
4. **Production mode**: Runs pre-built applications for realistic testing
5. **Stateless**: No server state management required for parallel testing
6. **URL rewriting**: Handles both relative and absolute URLs with query parameters
