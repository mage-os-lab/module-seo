<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer\Adminhtml;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Category\ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Persists the category edit form's "SEO (Structured Data)" fieldset after the category is saved.
 *
 * Core's admin category save controller saves the model directly, so a plugin on
 * CategoryRepositoryInterface never runs for this form, and core dispatches no controller
 * event after the save. This observer therefore listens to catalog_category_save_commit_after
 * (the category is committed by then) and acts only on the category the form was submitted
 * for: the controller copies the whole POST onto that category with addData(), so it is the
 * only category carrying the mageos_seo_* form fields. The values are read from the category
 * for the same reason, and so is the store view (the posted store_id lands there too).
 *
 * Registered in etc/adminhtml/events.xml only: admin form POST data must never influence
 * REST/GraphQL/import/cron saves.
 */
class SaveCategorySeoConfig implements ObserverInterface
{
    public const FIELD_SCHEMA_TEMPLATE   = 'mageos_seo_schema_template';
    public const FIELD_ENABLED_FIELDS    = 'mageos_seo_enabled_fields';
    public const FIELD_ITEM_LIST_ENABLED = 'mageos_seo_item_list_enabled';
    public const FIELD_ROBOTS_META       = 'mageos_seo_robots_meta';
    public const FIELD_OVERRIDE_FIELDS   = 'mageos_seo_override_fields';

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
     * Persist any SEO config submitted with the category edit form.
     *
     * SEO-only problems are reported as warnings: the category itself is already saved, and
     * core's save controller treats any error message as a failed save (a new category would
     * be sent back to the "add" page although it exists).
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category');
        if (!$category instanceof Category) {
            return;
        }

        $categoryId = (int) $category->getId();
        if ($categoryId <= 0 || !$this->carriesSeoFields($category)) {
            return;
        }

        $data = [];

        $schemaTemplate = $category->getData(self::FIELD_SCHEMA_TEMPLATE);
        if ($schemaTemplate !== null) {
            $data['schema_template'] = (string) $schemaTemplate;
        }

        $enabledFields = $category->getData(self::FIELD_ENABLED_FIELDS);
        if ($enabledFields !== null) {
            $data['enabled_fields'] = \is_array($enabledFields) ? $enabledFields : [];
        }

        $itemListEnabled = $category->getData(self::FIELD_ITEM_LIST_ENABLED);
        if ($itemListEnabled !== null) {
            $data['item_list_enabled'] = ($itemListEnabled === '') ? null : (int) $itemListEnabled;
        }

        $robotsMeta = $category->getData(self::FIELD_ROBOTS_META);
        if ($robotsMeta !== null) {
            $data['robots_meta'] = (string) $robotsMeta ?: null;
        }

        $overrideFields = $category->getData(self::FIELD_OVERRIDE_FIELDS);
        if ($overrideFields !== null) {
            $raw = (string) $overrideFields;
            if ($raw === '') {
                $data['override_fields'] = [];
            } else {
                $decoded = json_decode($raw, true);
                if (\is_array($decoded)) {
                    $data['override_fields'] = $decoded;
                } else {
                    // Invalid JSON: keep the stored value rather than silently wiping it.
                    $this->messageManager->addWarningMessage(
                        (string) __('SEO override fields were not saved: the value is not valid JSON.')
                    );
                }
            }
        }

        if (empty($data)) {
            return;
        }

        $storeId = max(0, (int) $category->getStoreId());
        try {
            $this->configRepository->save($categoryId, $data, $storeId);
        } catch (\Throwable $e) {
            // The category itself is already committed; a SEO-table failure must not
            // make the whole save look failed.
            $this->logger->error(
                'MageOS_Seo: could not save category SEO config: ' . $e->getMessage(),
                ['exception' => $e, 'category_id' => $categoryId, 'store_id' => $storeId]
            );
            $this->messageManager->addWarningMessage(
                (string) __('The category was saved, but its SEO settings could not be saved.')
            );
        }
    }

    /**
     * Whether the saved category carries fields posted from the SEO fieldset.
     *
     * @param Category $category
     * @return bool
     */
    private function carriesSeoFields(Category $category): bool
    {
        foreach ([
            self::FIELD_SCHEMA_TEMPLATE,
            self::FIELD_ENABLED_FIELDS,
            self::FIELD_ITEM_LIST_ENABLED,
            self::FIELD_ROBOTS_META,
            self::FIELD_OVERRIDE_FIELDS,
        ] as $field) {
            if ($category->getData($field) !== null) {
                return true;
            }
        }

        return false;
    }
}
