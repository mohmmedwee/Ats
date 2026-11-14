<?php

namespace App\Shared\Translation;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Translates product titles and descriptions to Modern Standard Arabic for Gulf shoppers with the
 * Claude API (structured JSON output). Any failure returns no translation; the import continues.
 */
final class ClaudeTranslator implements Translator
{
    private const SYSTEM = <<<'TXT'
        You translate e-commerce product text from English into Modern Standard Arabic for shoppers in the
        UAE and Saudi Arabia. Write natural, concise retail Arabic, not a word-for-word rendering. Keep brand
        names, model numbers, sizes and units as written. Remove supplier marketing filler, emojis and
        anything that is not about the product. Product titles stay under 80 characters. Return every key
        you are given, translated.
        TXT;

    public function __construct(private readonly ?Client $client = null) {}

    public function enabled(): bool
    {
        return true;
    }

    public function toArabic(array $fields): array
    {
        $fields = array_filter($fields, fn ($v) => trim($v) !== '');
        if ($fields === []) {
            return [];
        }

        try {
            $client = $this->client ?? new Client(apiKey: (string) config('gulfcart.translation.anthropic_api_key') ?: null);
            $message = $client->beta->messages->create(
                model: (string) config('gulfcart.translation.model', 'claude-opus-5-5'),
                maxTokens: 4000,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => array_map(fn () => ['type' => 'string'], $fields),
                            'required' => array_keys($fields),
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );

            if ($message->stopReason !== 'end_turn') {
                Log::info('translation.incomplete', ['stop_reason' => $message->stopReason]);

                return [];
            }
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $data = json_decode($block->text, true);

                    return is_array($data)
                        ? array_filter(array_intersect_key($data, $fields), fn ($v) => is_string($v) && trim($v) !== '')
                        : [];
                }
            }
        } catch (Throwable $e) {
            Log::warning('translation.failed', ['error' => $e->getMessage()]);
        }

        return [];
    }
}
