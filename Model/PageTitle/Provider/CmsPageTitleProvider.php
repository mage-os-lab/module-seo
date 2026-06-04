<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageTitle\Provider;

use MageOS\Seo\Api\PageTitleProviderInterface;
use MageOS\Seo\Model\Cms\CmsPageResolver;

class CmsPageTitleProvider implements PageTitleProviderInterface
{
    /**
     * @param CmsPageResolver $cmsPageResolver
     */
    public function __construct(
        private readonly CmsPageResolver $cmsPageResolver
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['cms_page_view'];
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return 100;
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        try {
            $page = $this->cmsPageResolver->resolve();
            if ($page === null) {
                return '';
            }

            return (string) $page->getTitle();
        } catch (\Exception) {
            return '';
        }
    }
}
