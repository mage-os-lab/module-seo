<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Aeo;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Settings of the answer-engine documents: /llms.txt, /llms-full.txt and /llms.jsonl, where their
 * pre-generated files are stored, and the AI crawler directives appended to robots.txt.
 *
 * The rest of the SEO settings are Model\Config's; the locale the llms documents show is read there
 * (Config::getLocaleCode()).
 */
class Config
{
    public const XML_LLMS_ENABLED         = 'mageos_aeo/llms_txt/enabled';
    public const XML_LLMS_FULL_ENABLED    = 'mageos_aeo/llms_txt/full_enabled';
    public const XML_LLMS_JSONL_ENABLED   = 'mageos_aeo/llms_txt/jsonl_enabled';
    public const XML_LLMS_FAQ_GROUPS      = 'mageos_aeo/llms_txt/faq_groups';
    public const XML_FEEDS_STORAGE_DIR    = 'mageos_aeo/feeds/storage_dir';
    public const XML_AI_ROBOTS_ENABLED    = 'mageos_aeo/ai_robots/enabled';
    public const XML_AI_ROBOTS_DISALLOWED = 'mageos_aeo/ai_robots/disallowed';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check if the llms.txt endpoint is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isLlmsTxtEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_LLMS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the llms-full.txt endpoint is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isLlmsFullTxtEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_LLMS_FULL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the /llms.jsonl product catalog endpoint is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isLlmsJsonlEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_LLMS_JSONL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the FAQ groups whose questions go into /llms.txt and /llms-full.txt, in order.
     *
     * None selected is an empty list: the documents then carry no FAQ section.
     *
     * @param int $storeId
     * @return string[]
     */
    public function getLlmsFaqGroups(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_LLMS_FAQ_GROUPS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $group): bool => $group !== ''
        ));
    }

    /**
     * Absolute directory for pre-generated feed files, or '' for the default (var/mageos_aeo).
     *
     * Scaled deployments point this at a mount shared between the web hosts and the
     * host running cron/queue consumers; var/ is host-local on multi-server setups.
     *
     * @return string
     */
    public function getFeedStorageDir(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_FEEDS_STORAGE_DIR));
    }

    /**
     * Check if AI-crawler directives are appended to robots.txt.
     *
     * @return bool
     */
    public function isAiRobotsEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_AI_ROBOTS_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Return the AI user-agents to disallow in robots.txt.
     *
     * @return string[]
     */
    public function getAiDisallowedBots(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_AI_ROBOTS_DISALLOWED, ScopeInterface::SCOPE_STORE);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
