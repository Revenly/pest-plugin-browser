<?php

declare(strict_types=1);

namespace Pest\Browser\Api\Concerns;

use Pest\Browser\Api\Webpage;

/**
 * @mixin Webpage
 */
trait HasWaitCapabilities
{
    /**
     * Waits for the specified load state.
     */
    public function waitForEvent(string $state): self
    {
        $this->page->waitForLoadState($state);

        return $this;
    }

    /**
     * Waits for the selector to satisfy state option.
     *
     * @param  array<string, mixed>|null  $options  Additional options like state, strict, timeout
     */
    public function waitForSelector(string $selector, ?array $options = null): self
    {
        $this->page->waitForSelector($selector, $options);

        return $this;
    }
}
