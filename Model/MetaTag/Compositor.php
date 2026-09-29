<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag;

use Magento\Framework\View\LayoutInterface;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Model\Pool\HandleMatcher;

/**
 * Collects the page's meta tags from the providers whose handles match, then adds the X (Twitter)
 * card tags the page's Open Graph tags imply.
 *
 * The card is decided here, where every provider's tags meet, because no single provider knows the
 * whole page: `summary_large_image` when the page has an og:image, `summary` when it has an og:title
 * but no image, and none when it has no og:title (cart, checkout — nothing to share). twitter:title,
 * twitter:description and twitter:image repeat og:title, og:description and og:image. X would fall
 * back to the Open Graph tags without them, but they are published for readers that don't. A
 * twitter:* tag a provider set itself is kept, and not repeated.
 */
class Compositor
{
    /**
     * The Open Graph tags repeated for X, by the twitter:* name they are repeated under.
     */
    private const TWITTER_COPIES = [
        'twitter:title'       => 'og:title',
        'twitter:description' => 'og:description',
        'twitter:image'       => 'og:image',
    ];
    /**
     * @param LayoutInterface $layout
     * @param HandleMatcher $handleMatcher
     * @param array<mixed> $providers
     */
    public function __construct(
        private readonly LayoutInterface        $layout,
        private readonly HandleMatcher $handleMatcher,
        private readonly array         $providers = []
    ) {
    }

    /**
     * Collect all meta tag definitions from matching providers.
     *
     * @return mixed[]
     */
    public function getMetaTags(): array
    {
        $activeHandles = $this->layout->getUpdate()->getHandles();
        $tags = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof MetaTagProviderInterface) {
                continue;
            }
            if (!$this->handleMatcher->matches($provider->getHandles(), $activeHandles)) {
                continue;
            }
            foreach ($provider->getMetaTags() as $tag) {
                if (!empty($tag['content'])) {
                    $tags[] = $tag;
                }
            }
        }

        return $this->withTwitterCard($tags);
    }

    /**
     * Add the X card tags the page's Open Graph tags imply, keeping any a provider set itself.
     *
     * @param mixed[] $tags
     * @return mixed[]
     */
    private function withTwitterCard(array $tags): array
    {
        $contents = [];
        foreach ($tags as $tag) {
            $key = $tag['property'] ?? $tag['name'] ?? null;
            if (\is_string($key) && !isset($contents[$key])) {
                $contents[$key] = $tag['content'];
            }
        }

        if (!isset($contents['og:title'])) {
            return $tags;
        }

        if (!isset($contents['twitter:card'])) {
            $card   = isset($contents['og:image']) ? 'summary_large_image' : 'summary';
            $tags[] = ['name' => 'twitter:card', 'content' => $card];
        }
        foreach (self::TWITTER_COPIES as $twitter => $openGraph) {
            if (isset($contents[$openGraph]) && !isset($contents[$twitter])) {
                $tags[] = ['name' => $twitter, 'content' => $contents[$openGraph]];
            }
        }

        return $tags;
    }
}
