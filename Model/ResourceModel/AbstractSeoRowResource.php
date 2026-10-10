<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use MageOS\Seo\Model\PageCache\Purger;
use MageOS\Seo\Model\PageCache\SeoCacheTags;

/**
 * A table of per-entity SEO settings, whose writes purge the cached pages built from them.
 *
 * Every write reaches the database through save() or delete() here — the repositories, the admin
 * forms' observers, the migration importers, and anything calling the resource model directly — so
 * this is the one place the purge is asked for. The pages to purge are named by the tags the
 * subclass gives (pageTags()), as Model\PageCache\DependencyCollector recorded them on the pages.
 *
 * The purge is asked for once the write has returned, so the Purger can wait for the commit of a
 * transaction still open around it and purge at once when there is none. Not from _afterSave() and
 * _afterDelete(): a delete commits its own transaction past the commit callbacks, so a purge
 * deferred from inside it would wait for some later, unrelated commit.
 *
 * A save that changes no setting purges nothing. The admin forms save an entity's SEO row with
 * every save of the entity, and a category's tag is on every page beneath it.
 */
abstract class AbstractSeoRowResource extends AbstractDb
{
    /**
     * Columns that identify a row or that the database keeps, rather than settings.
     */
    private const NOT_SETTINGS = ['entity_id', 'category_id', 'product_id', 'page_id', 'store_id', 'updated_at'];

    /**
     * @param Context $context
     * @param SeoCacheTags $cacheTags
     * @param Purger $purger
     * @param string|null $connectionName
     */
    public function __construct(
        Context                         $context,
        protected readonly SeoCacheTags $cacheTags,
        private readonly Purger         $purger,
        $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * Save the row, then purge the pages built from it if a setting changed.
     *
     * @param AbstractModel $object
     * @throws \Exception
     * @return $this
     */
    public function save(AbstractModel $object)
    {
        // Named before the write: the row's previous values are needed to tell what changed.
        $tags = $this->changesSettings($object) ? $this->pageTags($object, false) : [];

        parent::save($object);
        $this->purger->purge($tags);

        return $this;
    }

    /**
     * Delete the row, then purge the pages built from it.
     *
     * @param AbstractModel $object
     * @throws \Exception
     * @return $this
     */
    public function delete(AbstractModel $object)
    {
        $tags = $this->pageTags($object, true);

        parent::delete($object);
        $this->purger->purge($tags);

        return $this;
    }

    /**
     * The tags of the pages built from the row, as it was and as it is to be.
     *
     * Asked before the write, so the row still holds what it held: $removed says whether it is
     * about to go.
     *
     * @param AbstractModel $row
     * @param bool $removed Whether the row is being deleted
     * @return string[]
     */
    abstract protected function pageTags(AbstractModel $row, bool $removed): array;

    /**
     * Whether saving the row changes any setting it holds.
     *
     * An unset value and an empty one say the same — nothing configured — so changing one into
     * the other changes no page.
     *
     * @param AbstractModel $row
     * @return bool
     */
    private function changesSettings(AbstractModel $row): bool
    {
        $before = (array) ($row->getOrigData() ?? []);
        $after  = (array) $row->getData();

        foreach (array_keys($before + $after) as $column) {
            if (\in_array($column, self::NOT_SETTINGS, true)) {
                continue;
            }
            if ($this->setting($before[$column] ?? null) !== $this->setting($after[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stored value as it is compared: null for none.
     *
     * @param mixed $value
     * @return string|null
     */
    private function setting(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}
