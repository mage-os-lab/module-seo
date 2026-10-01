<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsTxt;

interface SectionProviderInterface
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
