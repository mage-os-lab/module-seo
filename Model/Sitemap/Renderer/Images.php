<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Renderer;

use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use MageOS\Seo\Api\Sitemap\RowRendererInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;

/**
 * Product and category images, exactly as core's sitemap writes them.
 *
 * An `<image:image>` per image, then the PageMap thumbnail Google web search reads — the same
 * markup and escaping as `Magento\Sitemap\Model\Sitemap::_getSitemapRow()`. Whether an item has
 * images at all is decided by core's image-inclusion setting, which its resource models apply.
 */
class Images implements RowRendererInterface
{
    /**
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getNamespaces(): array
    {
        return ['image' => 'http://www.google.com/schemas/sitemap-image/1.1'];
    }

    /**
     * @inheritdoc
     */
    public function render(SitemapItemInterface $item, int $storeId): string
    {
        // Core's interface documents array|null; its resource models set a DataObject.
        /** @var mixed $images */
        $images = $item->getImages();
        if (!$images instanceof DataObject) {
            return '';
        }

        $title = (string) $images->getData('title');
        $row   = '';
        foreach ((array) $images->getData('collection') as $image) {
            $row .= '<image:image>';
            $row .= '<image:loc>' . $this->escaper->escapeUrl((string) $image->getData('url')) . '</image:loc>';
            $row .= '<image:title>' . $this->escapeXmlText($title) . '</image:title>';
            if ($image->getData('caption')) {
                $row .= '<image:caption>' . $this->escapeXmlText((string) $image->getData('caption'))
                    . '</image:caption>';
            }
            $row .= '</image:image>';
        }

        $row .= '<PageMap xmlns="http://www.google.com/schemas/sitemap-pagemap/1.0"><DataObject type="thumbnail">';
        $row .= '<Attribute name="name" value="' . $this->escaper->escapeHtmlAttr($title) . '"/>';
        $row .= '<Attribute name="src" value="'
            . $this->escaper->escapeUrl((string) $images->getData('thumbnail')) . '"/>';
        $row .= '</DataObject></PageMap>';

        return $row;
    }

    /**
     * Escape text for an XML element, as core's private `escapeXmlText()` does.
     *
     * @param string $text
     * @return string
     */
    private function escapeXmlText(string $text): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $fragment = $document->createDocumentFragment();
        $fragment->appendChild($document->createTextNode($text));

        return (string) $document->saveXML($fragment);
    }
}
