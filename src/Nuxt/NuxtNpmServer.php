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
    private bool $isNpmRunDev = false;
    private bool $isNpmRunBuild = false;

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
    private readonly string $frontendBaseDirectory;

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
        public int $port,
        ?HttpServer $httpServer = null,
    ) {
        $this->frontendBaseDirectory = $this->findFrontendDirectory();
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

        $this->ensureNuxtBuilt();

        // Ensure HTTP server is running and get API URL
        $this->httpServer->bootstrap();
        $this->apiServerUrl = $this->httpServer->rewrite('/');

        if ($this->isNpmRunDev) {
            $this->port = 3000;

//            $envFilePath = "$this->frontendBaseDirectory/.env";
//            $contents = file_get_contents($envFilePath);
//            $contents = str_replace('NUXT_PUBLIC_BASE_URL', '#NUXT_PUBLIC_BASE_URL', $contents);
//            $contents = str_replace('NUXT_PUBLIC_SANCTUM_BASE_URL', '#NUXT_PUBLIC_SANCTUM_BASE_URL', $contents);
//            file_put_contents($envFilePath, $contents);
//
//            $configFilePath = "$this->frontendBaseDirectory/nuxt.config.ts";
//            $contents = file_get_contents($configFilePath);
//            $contents = str_replace(
//                "baseUrl: '', // can be overridden by NUXT_PUBLIC_SANCTUM_BASE_URL environment variable",
//                "baseUrl: '$this->apiServerUrl', // can be overridden by NUXT_PUBLIC_SANCTUM_BASE_URL environment variable",
//                $contents
//            );
//            file_put_contents($configFilePath, $contents);

            $this->systemProcess = SystemProcess::fromShellCommandline('tail');
            $this->systemProcess->setTimeout(0);
            $this->systemProcess->start();

            return;
        }

        $this->systemProcess = SystemProcess::fromShellCommandline(
            'node .output/server/index.mjs',
            $this->frontendBaseDirectory,
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
        $frontendEnvPath = "$this->frontendBaseDirectory/.env";

        $contents = file_get_contents($frontendEnvPath);

        file_put_contents(
            $frontendEnvPath,
            trim(\Str::before($contents, '# Injected by Pest'))."\n",
        );

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
                    'nuxtDirectory' => $this->frontendBaseDirectory,
                    'host' => $this->host,
                    'port' => $this->port,
                ]))
            );
        }

        return match (true) {
            $this->isNpmRunDev => 'http://tratta.test:3000',
            $this->isNpmRunBuild => sprintf('http://%s:%d', $this->host, $this->port),
        };
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
    private function findFrontendDirectory(): string
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
        $npmRunBuildPath = "$this->frontendBaseDirectory/.output/server/index.mjs";
        $npmRunDevPath = "$this->frontendBaseDirectory/.nuxt/dev/index.mjs";

        if (is_file($npmRunBuildPath)) {
            $this->isNpmRunBuild = true;

            return;
        }

        if (is_file($npmRunDevPath)) {
            $this->isNpmRunDev = true;

            return;
        }

        // $this->buildNuxt();

        throw new ServerNotFoundException('Nuxt project is not built. Please build the project first.');
    }

    /**
     * Build the Nuxt project with the correct environment variables.
     */
    private function buildNuxt(): void
    {
        $buildProcess = new SystemProcess(['npx', 'nuxi', 'build'], $this->frontendBaseDirectory);
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
