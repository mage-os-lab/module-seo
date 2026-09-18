<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Catalog\Category;

use Magento\Catalog\Model\Category\DataProvider;
use MageOS\Seo\Ui\DataProvider\Category\Form\Modifier\SeoModifier;

/**
 * Loads the stored category SEO config into the category edit form.
 *
 * Core's category form data provider overrides getData() without applying the modifier
 * pool, so the SEO modifier's modifyData() never runs through the pool (its modifyMeta()
 * does). Without this plugin the fieldset always renders empty, and every category save
 * would post those empty values back over the stored config.
 *
 * Registered in etc/adminhtml/di.xml only.
 */
class AddSeoConfigToFormDataPlugin
{
    /**
     * @param SeoModifier $seoModifier
     */
    public function __construct(
        private readonly SeoModifier $seoModifier
    ) {
    }

    /**
     * Add the stored SEO config to the loaded category form data.
     *
     * @param DataProvider $subject
     * @param mixed $result Form data keyed by category ID, or null when no category is loaded
     * @return mixed
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterGetData(DataProvider $subject, mixed $result): mixed
    {
        if (!\is_array($result) || $result === []) {
            return $result;
        }

        return $this->seoModifier->modifyData($result);
    }
}
