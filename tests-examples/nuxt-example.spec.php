<?php

declare(strict_types=1);

use Pest\Browser\Browsable;

uses(Browsable::class);

test('can visit a Nuxt application', function () {
    // This will automatically start a Nuxt server and visit the application
    $this->visitNuxt('/')
        ->assertSee('Welcome to Nuxt!');
});

test('can visit a Nuxt application with custom directory', function () {
    // Specify a custom Nuxt project directory
    $this->visitNuxt('/', [], '/path/to/your/nuxt/project')
        ->assertSee('Welcome to Nuxt!');
});

test('can visit a Nuxt application with custom API server', function () {
    // Specify a custom API server URL for this instance
    $this->visitNuxt('/', [], null, 'http://localhost:3001/api')
        ->assertSee('Welcome to Nuxt!');
});

test('can test multiple Nuxt instances', function () {
    // Each test can have its own Nuxt instance with different API endpoints
    $this->visitNuxt('/', [], null, 'http://localhost:3001/api/tenant1')
        ->assertSee('Welcome to Nuxt!');
        
    $this->visitNuxt('/', [], null, 'http://localhost:3001/api/tenant2')
        ->assertSee('Welcome to Nuxt!');
});
