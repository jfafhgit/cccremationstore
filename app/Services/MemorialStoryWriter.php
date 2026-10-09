<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Drafts a memorial story (obituary) with Google Gemini from what the
 * family has told us, for them to review and edit before submitting.
 */
class MemorialStoryWriter
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * @param  array<string, string>  $facts  What the family shared, keyed by a readable label.
     * @param  string|null  $tone  How it should read, e.g. "Warm and personal".
     * @param  bool  $hasPassed  False when the arrangement is made ahead of time.
     *
     * @throws RuntimeException When Gemini can't be reached or returns no story.
     */
    public function write(array $facts, ?string $tone, bool $hasPassed): string
    {
        $factLines = collect($facts)
            ->filter(fn (?string $value) => filled($value))
            ->map(fn (string $value, string $label) => "- {$label}: {$value}")
            ->implode("\n");

        try {
            $response = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->timeout(60)
                ->post(sprintf(self::ENDPOINT, config('services.gemini.model')), [
                    'system_instruction' => ['parts' => [['text' => $this->instructions($tone, $hasPassed)]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => "Write the memorial story from these details:\n\n{$factLines}"]]]],
                    'generationConfig' => ['temperature' => 0.7],
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach Gemini.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Gemini returned HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500));
        }

        $story = collect($response->json('candidates.0.content.parts', []))
            ->reject(fn (array $part) => $part['thought'] ?? false)
            ->pluck('text')
            ->implode('');

        if (blank($story)) {
            throw new RuntimeException('Gemini returned no story (finish reason: '.($response->json('candidates.0.finishReason') ?? 'unknown').').');
        }

        return trim($story);
    }

    private function instructions(?string $tone, bool $hasPassed): string
    {
        $timing = $hasPassed
            ? 'The person has passed away.'
            : 'The arrangements are being made ahead of time and the person may still be living, so do not state or guess a date or place of passing.';

        return <<<TEXT
        You write memorial stories (obituaries) for a funeral home, on behalf of a grieving family.

        - Use only the details provided. Never invent names, dates, places, relatives, jobs, or anecdotes. Leave out anything that isn't given rather than guessing.
        - Write 3 to 5 paragraphs of plain prose, roughly 250 to 400 words. No title, headings, bullet points, markdown, or placeholders in brackets.
        - Open with their name (and the name they went by, if given) and, if known, when and where they were born and passed.
        - Weave in their life, work, service, passions, faith, and what they will be remembered for.
        - Near the end, list who they are survived by and preceded in death by, if given.
        - Mention service plans only if the details describe a specific plan, and end with the memorial donation request if one is given.
        - Tone: {$this->toneGuidance($tone)}
        - {$timing}
        TEXT;
    }

    private function toneGuidance(?string $tone): string
    {
        return match ($tone) {
            'Celebration of life' => 'uplifting and celebratory, focusing on the joy of their life.',
            'Faith-centered' => 'reverent, reflecting their faith where the details support it.',
            'Warm and personal' => 'warm, personal, and conversational, as a loved one would tell it.',
            default => 'traditional, dignified, and respectful.',
        };
    }
}
