<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\LayoutInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;

class BreadcrumbListProvider implements StructuredDataProviderInterface
{
    /**
     * @param LayoutInterface $layout
     */
    public function __construct(
        private readonly LayoutInterface $layout
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['*'];
    }

    private const EXCLUDED_HANDLES = [
        'makers_profile_view',
        'makers_index_index',
        'makers_enroll_index',
    ];

    /**
     * @inheritdoc
     */
    public function getSchemas(): array
    {
        // Vendor pages manage their own breadcrumbs since the layout breadcrumbs
        // block is not server-rendered on Hyva vendor pages.
        $activeHandles = $this->layout->getUpdate()->getHandles();
        foreach (self::EXCLUDED_HANDLES as $excluded) {
            if (\in_array($excluded, $activeHandles, true)) {
                return [];
            }
        }

        try {
            $breadcrumbBlock = $this->layout->getBlock('breadcrumbs');
            if (!$breadcrumbBlock instanceof BlockInterface) {
                return [];
            }

            // Hyva breadcrumbs block exposes getCrumbs() returning an array keyed by crumb label
            $crumbsMethod = 'getCrumbs';
            if (!method_exists($breadcrumbBlock, $crumbsMethod)) {
                return [];
            }

            $crumbs = $breadcrumbBlock->$crumbsMethod();
            if (empty($crumbs)) {
                return [];
            }

            $items = [];
            $position = 1;
            foreach ($crumbs as $crumb) {
                $item = [
                    '@type'    => 'ListItem',
                    'position' => $position,
                    'name'     => (string) ($crumb['label'] ?? ''),
                ];
                if (!empty($crumb['link'])) {
                    $item['item'] = (string) $crumb['link'];
                }
                $items[]  = $item;
                $position++;
            }

            if (empty($items)) {
                return [];
            }

            return [[
                '@context'        => 'https://schema.org',
                '@type'           => 'BreadcrumbList',
                'itemListElement' => $items,
            ]];
        } catch (\Exception) {
            return [];
        }
    }
}
