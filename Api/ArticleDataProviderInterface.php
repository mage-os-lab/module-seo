<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Supplies article/blog-post data for BlogPosting structured data.
 *
 * The Seo module defines the contract and the schema provider; a bridge module for whatever blog
 * extension is installed implements this interface and registers it in the ArticleSchemaProvider
 * pool via its own di.xml. MageOS_Seo is never modified.
 *
 * @api
 */
interface ArticleDataProviderInterface
{
    /**
     * Layout handles that identify an article page. ['*'] = every page.
     *
     * @return string[]
     */
    public function getHandles(): array;

    /**
     * Return article data for the current request, or null if not on an article page.
     *
     * Shape: headline, url, datePublished are required; description, dateModified, image,
     * keywords (string[]) are optional.
     *
     * @param int $storeId
     * @return array<string, mixed>|null
     */
    public function getArticle(int $storeId): ?array;
}
