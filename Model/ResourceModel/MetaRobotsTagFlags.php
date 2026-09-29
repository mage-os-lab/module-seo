<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

/**
 * Answers whether a retired module's columns are present on cms_page.
 *
 * Named for its first user, MageOS_MetaRobotsTag's per-page flags; MageOS_Hreflang's
 * meta_identifier is checked the same way.
 *
 * The migrations cannot ask the module manager instead: a merchant migrating away has usually
 * already disabled or removed that module, while its columns — and the merchant's settings in
 * them — are still in the database and still worth carrying over. Only the table itself can say.
 */
class MetaRobotsTagFlags extends AbstractConnectedResource
{
    /**
     * Initialize against the table the flags live on.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('cms_page', 'page_id');
    }

    /**
     * Whether every given column exists on cms_page.
     *
     * @param string[] $columns
     * @return bool
     */
    public function existOnCmsPage(array $columns): bool
    {
        $described = array_keys($this->connection()->describeTable($this->getMainTable()));

        foreach ($columns as $column) {
            if (!\in_array($column, $described, true)) {
                return false;
            }
        }

        return true;
    }
}
