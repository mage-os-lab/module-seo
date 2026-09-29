<?php

declare(strict_types=1);

namespace MageOS\Seo\Ui\DataProvider\Faq;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use MageOS\Seo\Model\ResourceModel\Faq\CollectionFactory;

/**
 * Supplies the FAQ edit form with the current record's data, hydrating saved values back from the
 * data persistor when a previous save failed.
 */
class FormDataProvider extends AbstractDataProvider
{
    /**
     * @var array<int, mixed>|null
     */
    private ?array $loadedData = null;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param DataPersistorInterface $dataPersistor
     * @param mixed[] $meta
     * @param mixed[] $data
     */
    public function __construct(
        string                                  $name,
        string                                  $primaryFieldName,
        string                                  $requestFieldName,
        CollectionFactory                       $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array                                   $meta = [],
        array                                   $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    /**
     * Form data keyed by entity_id, with data-persistor fallback after a failed save.
     *
     * @return array<int, mixed>
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];
        foreach ($this->collection->getItems() as $faq) {
            $this->loadedData[(int) $faq->getId()] = $faq->getData();
        }

        $persisted = $this->dataPersistor->get('mageos_faq');
        if (!empty($persisted)) {
            $faqId = (int) ($persisted['entity_id'] ?? 0);
            $this->loadedData[$faqId] = $persisted;
            $this->dataPersistor->clear('mageos_faq');
        }

        return $this->loadedData;
    }
}
