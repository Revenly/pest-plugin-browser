<?php

declare(strict_types=1);

namespace Pest\Browser\Nuxt;

use Pest\Browser\Contracts\HttpServer;
use Pest\Browser\Exceptions\ServerNotFoundException;
use Pest\Browser\ServerManager;
use Pest\Browser\Support\Port;
use Symfony\Component\Process\Process as SystemProcess;
use Throwable;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class NuxtNpmServer implements HttpServer
{
    /**
     * The underlying process instance, if any.
     */
    private ?SystemProcess $systemProcess = null;

    /**
     * The last throwable that occurred during the server's execution.
     */
    private ?Throwable $lastThrowable = null;

    /**
     * The Nuxt project directory.
     */
    private readonly string $nuxtDirectory;

    /**
     * The unique API server URL for this instance.
     */
    private string $apiServerUrl;

    /**
     * The HTTP server instance.
     */
    private readonly HttpServer $httpServer;

    /**
     * Creates a new Nuxt HTTP server instance.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        ?HttpServer $httpServer = null,
    ) {
        $this->nuxtDirectory = $this->findNuxtDirectory();
        $this->httpServer = $httpServer ?? ServerManager::instance()->http();
        $this->apiServerUrl = ''; // Will be set when HTTP server is running
    }

    /**
     * Creates a new nuxt npm server instance with the given host, port and http server instance.
     */
    public static function create(string $host, int $port, ?HttpServer $httpServer = null): self
    {
        return new self(
            $host, $port, $httpServer
        );
    }

    /**
     * Rewrite the given URL to match the server's host and port.
     */
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

    /**
     * Start the server and listen for incoming connections.
     */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        // Ensure HTTP server is running and get API URL
        $this->httpServer->bootstrap();
        $this->apiServerUrl = $this->httpServer->rewrite('/');

        $this->ensureNuxtBuilt();

        $command = 'node .output/server/index.mjs';

        $this->systemProcess = SystemProcess::fromShellCommandline(
            $command,
            $this->nuxtDirectory,
            [
                'NUXT_PUBLIC_BASE_URL' => $this->apiServerUrl,
                'NUXT_PUBLIC_SANCTUM_BASE_URL' => $this->apiServerUrl,
                'PORT' => (string) $this->port,
                'HOST' => $this->host,
                'NODE_ENV' => 'production',
            ]
        );

        $this->systemProcess->setTimeout(0);
        $this->systemProcess->start();

        $this->systemProcess->waitUntil(
            fn (string $type, string $output): bool => str_contains($output, 'Listening on')
        );
    }

    /**
     * Stop the server and close all connections.
     */
    public function stop(): void
    {
        if ($this->systemProcess instanceof SystemProcess && $this->isRunning()) {
            $this->systemProcess->stop(
                timeout: 0.1,
                signal: PHP_OS_FAMILY === 'Windows' ? null : SIGTERM,
            );
        }

        $this->systemProcess = null;
    }

    /**
     * Flush pending requests and close all connections.
     */
    public function flush(): void
    {
        // Nuxt servers are stateless, no flushing needed
    }

    /**
     * Bootstrap the server.
     */
    public function bootstrap(): void
    {
        $this->start();
    }

    /**
     * Get the last throwable that occurred during the server's execution.
     */
    public function lastThrowable(): ?Throwable
    {
        return $this->lastThrowable;
    }

    /**
     * Throws the last throwable if it should be thrown.
     */
    public function throwLastThrowableIfNeeded(): void
    {
        if ($this->lastThrowable instanceof Throwable) {
            throw $this->lastThrowable;
        }
    }

    /**
     * Get the server URL.
     */
    public function url(): string
    {
        if (! $this->isRunning()) {
            throw new ServerNotFoundException(
                sprintf('The process with arguments [%s] is not running or has stopped unexpectedly.', json_encode([
                    'nuxtDirectory' => $this->nuxtDirectory,
                    'host' => $this->host,
                    'port' => $this->port,
                ]))
            );
        }

        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    /**
     * Get the API server URL for this instance.
     */
    public function getApiServerUrl(): string
    {
        return $this->apiServerUrl;
    }

    /**
     * Find the Nuxt project directory.
     */
    private function findNuxtDirectory(): string
    {
        $currentDir = getcwd();
        $searchDirs = [
            $currentDir.'/frontend',
            $currentDir,
            dirname($currentDir),
            dirname(dirname($currentDir)),
        ];

        foreach ($searchDirs as $dir) {
            if (file_exists($dir.'/nuxt.config.ts') || file_exists($dir.'/nuxt.config.js')) {
                return $dir;
            }
        }

        throw new ServerNotFoundException('Nuxt project directory not found. Please ensure you are in a Nuxt project or specify the directory explicitly.');
    }

    /**
     * Ensure Nuxt is built for production.
     */
    private function ensureNuxtBuilt(): void
    {
        $outputPath = $this->nuxtDirectory.'/.output/server/index.mjs';

        if (! file_exists($outputPath)) {
            // $this->buildNuxt();
            throw new ServerNotFoundException('Nuxt project is not built. Please build the project first.');
        }

    }

    /**
     * Build the Nuxt project with the correct environment variables.
     */
    private function buildNuxt(): void
    {
        $buildProcess = new SystemProcess(['npx', 'nuxi', 'build'], $this->nuxtDirectory);
        $buildProcess->setTimeout(300); // 5 minutes timeout for build

        // Set environment variables for the build
        $buildProcess->setEnv([
            'NUXT_PUBLIC_BASE_URL' => $this->apiServerUrl,
            'NUXT_PUBLIC_SANCTUM_BASE_URL' => $this->apiServerUrl,
            'BASE_URL' => $this->apiServerUrl,
        ]);

        $buildProcess->run();

        if (! $buildProcess->isSuccessful()) {
            throw new ServerNotFoundException(
                'Failed to build Nuxt project: '.$buildProcess->getErrorOutput()
            );
        }
    }

    /**
     * Checks if the process is running.
     */
    private function isRunning(): bool
    {
        return $this->systemProcess instanceof SystemProcess
            && $this->systemProcess->isRunning();
    }
}
