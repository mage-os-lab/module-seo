<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Sitemap;

use Magento\Framework\Model\AbstractModel;
use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Model\Sitemap\FileRemover;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted Site Map entry's files — core's Delete action removes only its index.
 *
 * On the resource model, as QueueFirstBuild is: core's Sitemap model has no event prefix of its
 * own, and the resource model is the one place every delete passes — the admin's Delete, a data
 * patch, anything else. Whichever generator wrote the files, they go: core's own numbered files
 * are left behind by core just the same.
 */
class RemoveFilesOnDelete
{
    /**
     * @param FileRemover $fileRemover
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FileRemover     $fileRemover,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Remove the deleted entry's files.
     *
     * @param SitemapResource $subject
     * @param SitemapResource $result
     * @param AbstractModel $object
     * @return SitemapResource
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterDelete(SitemapResource $subject, $result, AbstractModel $object)
    {
        if (!$object instanceof Sitemap) {
            return $result;
        }

        try {
            $this->fileRemover->removeFilesOf($object);
        } catch (\Throwable $e) {
            // The entry is deleted; its files are left for someone to remove by hand.
            $this->logger->error(
                'MageOS_Seo: could not remove the files of deleted sitemap ' . $object->getId() . ': '
                . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return $result;
    }
}
