<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;

/**
 * A CMS page's SEO settings, per store view.
 *
 * A change purges the page's own pages. A page joining, leaving or moving between translation
 * groups also purges the pages of the group it was in and of the one it is in now: every page of a
 * group lists the others in its head. A deleted row takes the page out of its group.
 */
class CmsPageConfig extends AbstractSeoRowResource
{
    /**
     * Initialize the main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mageos_seo_cms_page_config', 'entity_id');
    }

    /**
     * @inheritDoc
     */
    protected function pageTags(AbstractModel $row, bool $removed): array
    {
        $tags = [$this->cacheTags->cmsPage((int) $row->getData('page_id'))];
        // The group as stored: a loaded row remembers it; a row deleted without being loaded is
        // taken as it stands, and a new one had none.
        $stored   = $row->getOrigData('hreflang_group') ?? ($removed ? $row->getData('hreflang_group') : null);
        $previous = (string) $stored;
        $current  = $removed ? '' : (string) $row->getData('hreflang_group');

        if ($previous === $current) {
            return $tags;
        }
        foreach ([$previous, $current] as $group) {
            if ($group !== '') {
                $tags[] = $this->cacheTags->hreflangGroup($group);
            }
        }

        return $tags;
    }
}
