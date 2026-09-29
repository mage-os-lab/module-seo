<?php

declare(strict_types=1);

namespace MageOS\Seo\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\StructuredData\CategoryItemList;

/**
 * Emits the category ItemList JSON-LD node, at end of body.
 *
 * Separate from the head block on purpose. The node describes the products the listing is
 * showing, and the listing has not been built yet while `<head>` renders — reading the layer's
 * collection there forces the catalogue query to run a second time, and gives a node
 * reconstructed from request parameters rather than one describing the page. Rendered here, it
 * reads the collection the page has already loaded. JSON-LD is valid anywhere in the document,
 * which is the same reason FaqJsonLd renders late.
 */
class ItemListJsonLd extends Template
{
    /**
     * @param Context $context
     * @param CategoryItemList $itemList
     * @param Config $seoConfig
     * @param mixed[] $data
     */
    public function __construct(
        Context                           $context,
        private readonly CategoryItemList $itemList,
        private readonly Config           $seoConfig,
        array                             $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Build the ItemList JSON-LD string, or an empty string when there is nothing to emit.
     *
     * Memoised: _toHtml() asks whether there is anything to render and the template then asks
     * again, so without this the collection is walked and encoded twice per page.
     *
     * @return string
     */
    public function getJsonLd(): string
    {
        if ($this->hasData('mageos_seo_json')) {
            return (string) $this->getData('mageos_seo_json');
        }

        $json = $this->buildJsonLd();
        $this->setData('mageos_seo_json', $json);

        return $json;
    }

    /**
     * Build the node and encode it.
     *
     * @return string
     */
    private function buildJsonLd(): string
    {
        if (!$this->seoConfig->isStructuredDataEnabled()) {
            return '';
        }

        $schema = $this->itemList->build();
        if ($schema === []) {
            return '';
        }

        // JSON_HEX_TAG/JSON_HEX_AMP encode <, > and & as \uXXXX so neither </script>
        // nor <!-- can ever appear inside the inline <script> payload, keeping the
        // output valid JSON (a post-encode str_replace cannot guarantee that).
        $json = json_encode(
            $schema,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '' : $json;
    }

    /**
     * Render nothing when there is no ItemList to output.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if ($this->getJsonLd() === '') {
            return '';
        }

        return parent::_toHtml();
    }
}
