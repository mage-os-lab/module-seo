<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

interface PageTitleProviderInterface
{
    /**
     * Return the page title string, or an empty string to abstain.
     *
     * The compositor picks the non-empty provider with the highest sortOrder.
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * Layout handles this provider applies to.
     *
     * Return ['*'] to run on every page.
     *
     * @return string[]
     */
    public function getHandles(): array;

    /**
     * Higher number = higher priority.
     *
     * Built-in providers use 0–50. Third-party providers should use 100+.
     *
     * @return int
     */
    public function getSortOrder(): int;
}
