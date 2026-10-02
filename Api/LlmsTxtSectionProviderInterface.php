<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Contributes content to /llms.txt and /llms-full.txt. Register an implementation in
 * Model\LlmsTxt\LlmsTxtBuilder's `sectionProviders` argument via your own di.xml.
 *
 * LlmsTxtBuilder places the returned markdown by its first line:
 *
 * - Output starting with "## " is an H2 section. It is appended after the built-in sections.
 *   In the llms.txt format an H2 section is a "file list": every item must be a markdown link,
 *   "- [name](url)", optionally followed by ": notes". Put no other content under the heading.
 * - Any other output is details: paragraphs or lists, with no headings at all. It is placed
 *   before the first H2, after the summary.
 *
 * Either kind keeps the order the providers are registered in. Return an empty string to
 * contribute nothing.
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
