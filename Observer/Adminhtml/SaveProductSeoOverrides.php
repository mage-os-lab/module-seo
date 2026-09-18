<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer\Adminhtml;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use Psr\Log\LoggerInterface;

/**
 * Persists the product edit form's "Advanced SEO" fieldset after the product is saved.
 *
 * Listens to controller_action_catalog_product_save_entity_after, which core's admin product
 * save controller dispatches once, after the product save has committed, for the product the
 * form was submitted for. The admin controller saves the model directly, so a plugin on
 * ProductRepositoryInterface never runs for this form. The generic catalog_product_save_after
 * event is not used either: it also fires for the other products saved during the same request
 * (configurable variations generated from the form, "Save & Duplicate" copies, "copy to store
 * views" saves), and the posted fieldset would be written onto every one of them.
 *
 * Registered in etc/adminhtml/events.xml only: admin form POST data must never influence
 * REST/GraphQL/import/cron saves.
 */
class SaveProductSeoOverrides implements ObserverInterface
{
    /**
     * @param RequestInterface $request
     * @param ProductOverrideRepository $productOverrideRepository
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface          $request,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly ManagerInterface          $messageManager,
        private readonly LoggerInterface           $logger
    ) {
    }

    /**
     * Persist any SEO overrides submitted with the product edit form.
     *
     * SEO-only problems are reported as warnings: the product itself is already saved.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof ProductInterface) {
            return;
        }

        $productId = (int) $product->getId();
        if ($productId <= 0) {
            return;
        }

        /** @var \Magento\Framework\App\Request\Http $postRequest */
        $postRequest = $this->request;
        $postData = $postRequest->getPostValue();

        if (!isset($postData['mageos_seo_override_fields']) && !isset($postData['mageos_seo_robots_meta'])) {
            return;
        }

        // Core admin catalog passes the selected store view as the "store" request
        // parameter; the adminhtml current store is always store 0, so reading the
        // store manager here would silently pin every override to the default scope.
        $storeId = max(0, (int) $this->request->getParam('store', 0));
        $data    = [];

        if (isset($postData['mageos_seo_override_fields'])) {
            $raw = (string) $postData['mageos_seo_override_fields'];
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

        if (isset($postData['mageos_seo_robots_meta'])) {
            $data['robots_meta'] = (string) $postData['mageos_seo_robots_meta'] ?: null;
        }

        if (empty($data)) {
            return;
        }

        try {
            $this->productOverrideRepository->save($productId, $storeId, $data);
        } catch (\Throwable $e) {
            // The product itself is already committed; a SEO-table failure must not
            // make the whole save look failed.
            $this->logger->error(
                'MageOS_Seo: could not save product SEO overrides: ' . $e->getMessage(),
                ['exception' => $e, 'product_id' => $productId, 'store_id' => $storeId]
            );
            $this->messageManager->addWarningMessage(
                (string) __('The product was saved, but its SEO overrides could not be saved.')
            );
        }
    }
}
