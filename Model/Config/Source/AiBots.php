<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Known AI crawler / agent user-agents for robots.txt directive management.
 *
 * The value is the literal robots.txt User-agent token.
 */
class AiBots implements OptionSourceInterface
{
    /**
     * Ordered list of known AI user-agents (value => label).
     *
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'GPTBot',              'label' => (string) __('GPTBot (OpenAI training)')],
            ['value' => 'ChatGPT-User',        'label' => (string) __('ChatGPT-User (OpenAI live)')],
            ['value' => 'OAI-SearchBot',       'label' => (string) __('OAI-SearchBot (OpenAI search)')],
            ['value' => 'ClaudeBot',           'label' => 'ClaudeBot (Anthropic)'],
            ['value' => 'anthropic-ai',        'label' => 'anthropic-ai (Anthropic)'],
            ['value' => 'PerplexityBot',       'label' => 'PerplexityBot (Perplexity)'],
            ['value' => 'Google-Extended',     'label' => (string) __('Google-Extended (Gemini training)')],
            ['value' => 'Applebot-Extended',   'label' => 'Applebot-Extended (Apple)'],
            ['value' => 'Meta-ExternalAgent',  'label' => 'Meta-ExternalAgent (Meta)'],
            ['value' => 'Amazonbot',           'label' => 'Amazonbot (Amazon)'],
            ['value' => 'cohere-ai',           'label' => 'cohere-ai (Cohere)'],
            ['value' => 'Diffbot',             'label' => 'Diffbot'],
            ['value' => 'CCBot',               'label' => 'CCBot (Common Crawl)'],
            ['value' => 'Bytespider',          'label' => 'Bytespider (ByteDance)'],
        ];
    }
}
