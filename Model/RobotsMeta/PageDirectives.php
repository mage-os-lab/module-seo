<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\RobotsMeta;

use MageOS\Seo\Api\Robots\PageDirectivesInterface;
use MageOS\Seo\Model\Category\PathResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use MageOS\Seo\Model\RobotsMeta\Provider\ProductRobotsProvider;
use MageOS\Seo\Model\Sitemap\Robots\Directive;

/**
 * The robots directive product and category pages get, a whole set at a time.
 *
 * Asked of the same providers the page head asks: a product's override, else the Product Pages
 * default; a category's own or inherited override, else the Category Pages default. Where this
 * module has nothing to say, core's Design → Search Engine Robots applies, as on the page.
 *
 * The one copy of that rule for whole catalogues: the sitemap leaves NOINDEX pages out with it, and
 * MageOS_Aeo's feeds (review M4) do the same.
 */
class PageDirectives implements PageDirectivesInterface
{
    /**
     * @param ProductRobotsProvider $productRobots
     * @param CategoryRobotsProvider $categoryRobots
     * @param PathResolver $categoryPathResolver
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly ProductRobotsProvider  $productRobots,
        private readonly CategoryRobotsProvider $categoryRobots,
        private readonly PathResolver           $categoryPathResolver,
        private readonly Config                 $seoConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function forProducts(array $productIds, int $storeId): array
    {
        if ($productIds === []) {
            return [];
        }

        $coreDefault = $this->seoConfig->getRobotsCoreDefault($storeId);

        return array_map(
            static fn (?string $directive): string => $directive ?? $coreDefault,
            $this->productRobots->forProducts($productIds, $storeId)
        );
    }

    /**
     * @inheritdoc
     */
    public function forCategories(array $categoryIds, int $storeId): array
    {
        if ($categoryIds === []) {
            return [];
        }

        // One query for every path; each category then inherits through its own ancestors.
        $coreDefault = $this->seoConfig->getRobotsCoreDefault($storeId);
        $directives  = [];
        foreach ($this->categoryPathResolver->forCategoryIds($categoryIds) as $categoryId => $path) {
            $directives[$categoryId] = $this->categoryRobots->forCategory($categoryId, $path, $storeId)
                ?? $coreDefault;
        }

        return $directives;
    }

    /**
     * @inheritdoc
     *
     * The sitemap's Directive holds the rule, so it is written once.
     */
    public function isNoindex(string $directive): bool
    {
        return (new Directive($directive))->isNoindex();
    }
}
