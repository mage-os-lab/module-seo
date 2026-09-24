<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Sitemap;

use Magento\Framework\Model\AbstractModel;
use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use Psr\Log\LoggerInterface;

/**
 * Queues the first build of a Site Map entry saved without a file, on a store view that rebuilds
 * its sitemaps on change — so an entry saved with Save rather than Save & Generate is written
 * shortly after, not at some later change.
 *
 * On the resource model: core's Sitemap model has no event prefix, so its saves dispatch only the
 * generic `core_abstract_save_after` every prefix-less model shares. The resource model is the one
 * place every save passes — the admin's Save, a data patch, anything else.
 *
 * This module's generator saves the entry after writing its files, so its own save finds the file
 * and queues nothing; so does re-saving an entry that has one. An entry given a new path or file
 * name has no file at its new place, and is built there.
 */
class QueueFirstBuild
{
    /**
     * @param RebuildableSitemaps $rebuildableSitemaps
     * @param FeedInvalidator $feedInvalidator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RebuildableSitemaps $rebuildableSitemaps,
        private readonly FeedInvalidator     $feedInvalidator,
        private readonly LoggerInterface     $logger
    ) {
    }

    /**
     * Queue the first build when the saved entry is rebuilt on change and has no file.
     *
     * @param SitemapResource $subject
     * @param SitemapResource $result
     * @param AbstractModel $object
     * @return SitemapResource
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterSave(SitemapResource $subject, $result, AbstractModel $object)
    {
        if (!$object instanceof Sitemap) {
            return $result;
        }

        try {
            // The entries a change rebuilds have just changed: forget this request's answer.
            $this->rebuildableSitemaps->_resetState();
            if ($this->rebuildableSitemaps->isRebuiltOnChange($object)
                && $this->rebuildableSitemaps->isMissing($object)
            ) {
                $this->feedInvalidator->invalidateMissingSitemaps();
            }
        } catch (\Throwable $e) {
            // The entry is saved; its first build waits for the next change or setup run.
            $this->logger->error(
                'MageOS_Seo: could not queue the first build of sitemap ' . $object->getId() . ': '
                . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $result;
    }
}
