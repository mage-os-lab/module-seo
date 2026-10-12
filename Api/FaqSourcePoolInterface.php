<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Every registered FAQ source, read as one.
 *
 * A source registers itself from its own module's di.xml:
 *
 *     <type name="MageOS\Seo\Api\FaqSourcePoolInterface">
 *         <arguments>
 *             <argument name="sources" xsi:type="array">
 *                 <item name="my_faqs" xsi:type="object">Vendor\Module\Model\FaqSource</item>
 *             </argument>
 *         </arguments>
 *     </type>
 *
 * @api
 */
interface FaqSourcePoolInterface
{
    /**
     * Every source's FAQ entries for a group identifier, in the order the sources were registered.
     *
     * @param string $identifier
     * @param int $storeId
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqs(string $identifier, int $storeId): array;

    /**
     * Every group identifier any source has FAQs for, each once.
     *
     * @return string[]
     */
    public function getIdentifiers(): array;
}
