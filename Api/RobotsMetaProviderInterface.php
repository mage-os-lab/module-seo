<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contributes a robots meta value for the current page.
 *
 * Providers are collected into a pool and resolved on a first-wins basis: the matching provider
 * with the highest sortOrder that returns a non-empty value wins. A second module can add robots
 * meta support for a new page type (blog post, vendor profile, …) simply by registering an
 * implementation of this interface in its own di.xml — MageOS_Seo is never modified.
 *
 * @api
 */
interface RobotsMetaProviderInterface
{
    /**
     * Layout handles this provider applies to. ['*'] = every page.
     *
     * @return string[]
     */
    public function getHandles(): array;

    /**
     * Resolve the robots meta value for the current page, or null when this provider has no opinion.
     *
     * @param int $storeId
     * @return string|null
     */
    public function getRobots(int $storeId): ?string;

    /**
     * Precedence within the pool. Higher wins. Built-in providers use 100; bridges use 200+.
     *
     * @return int
     */
    public function getSortOrder(): int;
}
