<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageCache;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The SEO settings the page being built has read, as page cache tags.
 *
 * The repositories record what they are asked for — a category's settings together with every
 * category it inherits from, a product's, a CMS page's and its translation group's — and
 * Block\JsonLd hands the tags to the page cache with the page. Recording at the source means no
 * reader can forget to: the robots directive, the JSON-LD, the ItemList and the hreflang links are
 * all read through the same repositories. The full page cache collects block identities after the
 * page's output is rendered, so every read has been recorded by then.
 *
 * Records in the frontend area only (etc/frontend/di.xml): elsewhere nothing is cached by page, and
 * sitemap and feed builds would otherwise collect a tag for every entity they read.
 *
 * Request-scoped, like Faq's collector: one shared instance, cleared between worker-mode requests.
 */
class DependencyCollector implements ResetAfterRequestInterface
{
    /**
     * @var array<string,true>
     */
    private array $tags = [];

    /**
     * @param bool $recording Whether reads are recorded: on in the frontend area
     */
    public function __construct(
        private readonly bool $recording = false
    ) {
    }

    /**
     * Record that the page read the settings behind these tags.
     *
     * @param string[] $tags
     * @return void
     */
    public function record(array $tags): void
    {
        if (!$this->recording) {
            return;
        }

        foreach ($tags as $tag) {
            $this->tags[$tag] = true;
        }
    }

    /**
     * The tags recorded so far.
     *
     * @return string[]
     */
    public function tags(): array
    {
        return array_keys($this->tags);
    }

    /**
     * Forget what the previous worker-mode request read.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->tags = [];
    }
}
