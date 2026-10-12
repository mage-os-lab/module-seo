<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\RobotsMeta\Provider;

use Magento\Framework\App\RequestInterface;
use MageOS\Seo\Api\RobotsMetaProviderInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\DirectiveComposer;

/**
 * Robots meta for paginated category listing pages (?p=N, N > 1).
 *
 * Opt-in (disabled by default): when enabled, paginated pages receive a dedicated robots value
 * (commonly NOINDEX,FOLLOW) so thin/duplicate paginated URLs stay out of the index while remaining
 * crawlable. Runs at a higher sortOrder than CategoryRobotsProvider so it wins on page 2+, and
 * returns null on page 1 (and when disabled) so the normal category default applies.
 *
 * It can only add restrictions (issue #34). Every restriction of the category's own directive, set
 * on the category, inherited from an ancestor or the Category Pages default, is carried onto the
 * paginated value: a category set to NOINDEX or NOFOLLOW keeps that on every page. A category
 * that should be indexed on its later pages is set to INDEX itself.
 *
 * Canonicalisation of paginated pages is intentionally left to Magento's native
 * catalog/seo/category_canonical_tag to avoid conflicting canonical signals.
 */
class CatalogPaginationRobotsProvider implements RobotsMetaProviderInterface
{
    /**
     * @param RequestInterface $request
     * @param Config $seoConfig
     * @param CategoryRobotsProvider $categoryRobots
     * @param DirectiveComposer $composer
     */
    public function __construct(
        private readonly RequestInterface       $request,
        private readonly Config                 $seoConfig,
        private readonly CategoryRobotsProvider $categoryRobots,
        private readonly DirectiveComposer      $composer
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_category_view'];
    }

    /**
     * @inheritdoc
     */
    public function getRobots(int $storeId): ?string
    {
        if (!$this->seoConfig->isPaginatedRobotsEnabled($storeId)) {
            return null;
        }

        if ((int) $this->request->getParam('p') <= 1) {
            return null;
        }

        $robots = $this->seoConfig->getRobotsPaginated($storeId);
        if (empty($robots)) {
            return null;
        }

        $category = $this->categoryRobots->getRobots($storeId);

        return $category === null ? $robots : $this->composer->restrictive($robots, $category);
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return 200;
    }
}
