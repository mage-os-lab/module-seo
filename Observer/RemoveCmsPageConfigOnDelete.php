<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsPageConfigRepository;
use MageOS\Seo\Model\Cms\TranslationGroupCache;
use Psr\Log\LoggerInterface;

/**
 * Removes a CMS page's SEO configuration when the page is deleted.
 *
 * mageos_seo_cms_page_config carries no foreign key to cms_page: under content staging the CMS
 * tables key on row_id and page_id is no longer unique, so the constraint cannot exist there. The
 * rows therefore have to be cleared here, or a deleted page leaves configuration behind that a
 * later page could inherit by reusing its ID.
 *
 * A page in a translation group was listed in the head of every other page of it, so those pages'
 * cached copies are purged too. Registered globally, since pages are deleted by REST and imports as
 * well as from the admin.
 *
 * Runs on the commit-after event, so the page is really gone before its configuration is.
 */
class RemoveCmsPageConfigOnDelete implements ObserverInterface
{
    /**
     * @param CmsPageConfigRepository $cmsPageConfigRepository
     * @param TranslationGroupCache $translationGroupCache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CmsPageConfigRepository $cmsPageConfigRepository,
        private readonly TranslationGroupCache   $translationGroupCache,
        private readonly LoggerInterface         $logger
    ) {
    }

    /**
     * Delete the configuration rows of the deleted page.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $page = $observer->getEvent()->getData('object') ?? $observer->getEvent()->getData('page');
        if (!$page instanceof PageInterface) {
            return;
        }

        $pageId = (int) $page->getId();
        if ($pageId === 0) {
            return;
        }

        try {
            // Read before the rows go: the group is on them.
            $group = $this->cmsPageConfigRepository->getHreflangGroup($pageId);
            $this->cmsPageConfigRepository->deleteForPages([$pageId]);
            $this->translationGroupCache->purge([$group]);
        } catch (\Throwable $e) {
            // The page itself is already gone; losing its configuration rows must not turn a
            // completed delete into an error the admin sees.
            $this->logger->error(
                'MageOS_Seo: could not remove the SEO configuration of deleted CMS page ' . $pageId,
                ['exception' => $e]
            );
        }
    }
}
