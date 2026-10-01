<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contributes a section to /llms.txt (concise) and /llms-full.txt (full). Register an
 * implementation in Model\LlmsTxt\LlmsTxtBuilder's `sectionProviders` argument via your own di.xml;
 * sections appear in the order registered.
 *
 * @api
 */
interface LlmsTxtSectionProviderInterface
{
    /**
     * Return a concise section string for /llms.txt.
     *
     * Return an empty string to contribute nothing.
     *
     * @return string
     */
    public function getConciseSection(): string;

    /**
     * Return the full section string for /llms-full.txt.
     *
     * Return an empty string to contribute nothing.
     *
     * @return string
     */
    public function getFullSection(): string;
}
