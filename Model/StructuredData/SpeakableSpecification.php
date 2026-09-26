<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData;

use MageOS\Seo\Model\Config;

/**
 * The page's SpeakableSpecification, for the node that describes the page to carry.
 *
 * Google: "Speakable is used by the Article or Webpage object", and its example puts `speakable`
 * on the page's WebPage beside `name` and `url` — so it goes on the page's own node (a CMS page's
 * WebPage, a category's CollectionPage, a blog post's BlogPosting, a product page's WebPage), never
 * on a node of its own. `cssSelector` only: Google takes cssSelector or xPath, not both.
 *
 * Google's use is narrow — beta, users in the U.S. with Google Home devices set to English, news
 * content read by Google Assistant — which is why **Enable Speakable Schema** is off by default.
 */
class SpeakableSpecification
{
    /**
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly Config $seoConfig
    ) {
    }

    /**
     * The spec, or null when speakable is off or has no selectors.
     *
     * @return array{'@type': string, cssSelector: string[]}|null
     */
    public function get(): ?array
    {
        if (!$this->seoConfig->isSpeakableEnabled()) {
            return null;
        }

        $selectors = $this->seoConfig->getSpeakableCssSelectors();
        if ($selectors === []) {
            return null;
        }

        return [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => $selectors,
        ];
    }
}
