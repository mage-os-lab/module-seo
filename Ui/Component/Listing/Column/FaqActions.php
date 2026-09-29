<?php

declare(strict_types=1);

namespace MageOS\Seo\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit/Delete action links for the FAQ grid.
 */
class FaqActions extends Column
{
    private const URL_EDIT   = 'mageos_faq/faq/edit';
    private const URL_DELETE = 'mageos_faq/faq/delete';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param mixed[] $components
     * @param mixed[] $data
     */
    public function __construct(
        ContextInterface                $context,
        UiComponentFactory              $uiComponentFactory,
        private readonly UrlInterface   $urlBuilder,
        array                           $components = [],
        array                           $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Add edit/delete actions to each row.
     *
     * @param array<string,mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            if (empty($item['entity_id'])) {
                continue;
            }
            $id = (int) $item['entity_id'];
            $item[$name]['edit'] = [
                'href'  => $this->urlBuilder->getUrl(self::URL_EDIT, ['entity_id' => $id]),
                'label' => (string) __('Edit'),
            ];
            $item[$name]['delete'] = [
                'href'    => $this->urlBuilder->getUrl(self::URL_DELETE, ['entity_id' => $id]),
                'label'   => (string) __('Delete'),
                'confirm' => [
                    'title'   => (string) __('Delete FAQ'),
                    'message' => (string) __('Are you sure you want to delete this FAQ?'),
                ],
                // Deletes must go through POST so backend form-key validation applies.
                'post'    => true,
            ];
        }

        return $dataSource;
    }
}
