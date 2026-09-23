<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer\Adminhtml;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Cms\ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Persists the CMS page "Search Engine Optimization" fieldset.
 *
 * Registered in the admin area only, like the product and category equivalents: the observer acts
 * on admin form data, and a global registration would let a REST, GraphQL or import save steer it
 * with a request payload.
 *
 * The value is written against the store view the page is being edited in, so a page shown in
 * several store views can carry a different directive in each.
 */
class SaveCmsPageSeoConfig implements ObserverInterface
{
    /**
     * The form field, named so it cannot collide with a core or third-party cms_page column.
     */
    public const FIELD_ROBOTS_META = 'mageos_seo_robots_meta';

    /**
     * @param ConfigRepository $configRepository
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface  $logger
    ) {
    }

    /**
     * Save the page's robots override.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /* @var Magento\Cms\Model\Page $page */
        $page = $observer->getEvent()->getData('object') ?? $observer->getEvent()->getData('page');
        if (!$page instanceof PageInterface) {
            return;
        }

        $pageId     = (int) $page->getId();
        $robotsMeta = $page->getData(self::FIELD_ROBOTS_META);

        // A save that never carried the field — a REST call, an import — leaves the stored value
        // alone rather than blanking it.
        if ($pageId <= 0 || $robotsMeta === null) {
            return;
        }

        try {
            $this->configRepository->save(
                $pageId,
                ['robots_meta' => (string) $robotsMeta ?: null],
                $this->storeId($page)
            );
        } catch (\Throwable $e) {
            // The page itself is already saved; a failure in the SEO table must not make the
            // whole save look failed.
            $this->logger->error(
                'MageOS_Seo: could not save CMS page SEO config: ' . $e->getMessage(),
                ['exception' => $e, 'page_id' => $pageId]
            );
            $this->messageManager->addWarningMessage(
                (string) __('The page was saved, but its SEO settings could not be saved.')
            );
        }
    }

    /**
     * The store view the override belongs to.
     *
     * A CMS page's store assignment is a list; `0` in it means "all store views", which is the
     * global row. Editing a page assigned to exactly one store view writes that store view's row.
     *
     * @param PageInterface $page
     * @return int
     */
    private function storeId(PageInterface $page): int
    {
        $stores = $page->getData('store_id');
        if (!\is_array($stores)) {
            return max(0, (int) $stores);
        }

        if (\count($stores) !== 1) {
            return 0;
        }

        return max(0, (int) reset($stores));
    }
}
