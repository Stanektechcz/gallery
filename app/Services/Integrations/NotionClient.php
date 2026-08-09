<?php

namespace App\Services\Integrations;

use App\Models\IntegrationDocument;
use App\Models\UserIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Notion, through an internal integration token.
 *
 * Token rather than OAuth on purpose. A public OAuth app has to be registered and
 * reviewed by Notion and gives us a client secret to hold for everybody; an internal
 * integration is created by each person in their own workspace in about a minute, grants
 * exactly the pages they explicitly share with it, and can be revoked by them alone.
 * For a couples' app where each person connects their own workspace, that is both less
 * work and less power for us to hold.
 *
 * The consequence worth knowing: a fresh integration sees nothing. Notion requires the
 * person to share pages with it from inside Notion, which is why the connect screen says
 * so and why an empty first sync is normal rather than a failure.
 */
class NotionClient
{
    private const BASE = 'https://api.notion.com/v1';

    /** Pinned deliberately: Notion changes behaviour by date and unversioned would drift. */
    private const VERSION = '2022-06-28';

    /** One page of results; Notion's own maximum is 100. */
    private const PAGE_SIZE = 100;

    /** Verifies a token and reports who it belongs to, without storing anything. */
    public function probe(string $token): array
    {
        $response = $this->http($token)->get(self::BASE . '/users/me');

        if (! $response->successful()) {
            return ['ok' => false, 'error' => $this->readableError($response->json(), $response->status())];
        }

        $body = $response->json();
        $bot = $body['bot'] ?? [];

        return [
            'ok' => true,
            'account_id' => (string) ($body['id'] ?? ''),
            // The workspace name is what a person recognises; the bot name is ours.
            'account_name' => (string) ($bot['workspace_name'] ?? $body['name'] ?? 'Notion'),
            'account_avatar' => $body['avatar_url'] ?? null,
        ];
    }

    /**
     * Pulls the pages and databases shared with this integration into our index.
     *
     * @return array{synced: int, error: ?string}
     */
    public function sync(UserIntegration $integration): array
    {
        $token = $integration->credentials()['token'] ?? null;
        if (! $token) return ['synced' => 0, 'error' => 'Chybí token.'];

        $cursor = null;
        $seen = [];
        $synced = 0;

        do {
            $response = $this->http($token)->post(self::BASE . '/search', array_filter([
                'page_size' => self::PAGE_SIZE,
                'start_cursor' => $cursor,
                'sort' => ['direction' => 'descending', 'timestamp' => 'last_edited_time'],
            ]));

            if (! $response->successful()) {
                $message = $this->readableError($response->json(), $response->status());
                $integration->markFailed($message);

                return ['synced' => $synced, 'error' => $message];
            }

            foreach ($response->json('results') ?? [] as $result) {
                $document = $this->toDocument($integration, $result);
                if (! $document) continue;

                IntegrationDocument::updateOrCreate(
                    ['user_integration_id' => $integration->id, 'external_id' => $document['external_id']],
                    $document,
                );
                $seen[] = $document['external_id'];
                $synced++;
            }

            $cursor = $response->json('next_cursor');
            // Guard against a pathological workspace: five pages is 500 documents.
        } while ($cursor && $synced < self::PAGE_SIZE * 5);

        // Anything no longer shared with the integration should stop appearing here too.
        if ($seen) {
            IntegrationDocument::where('user_integration_id', $integration->id)
                ->whereNotIn('external_id', $seen)->delete();
        }

        $integration->markUsed();

        return ['synced' => $synced, 'error' => null];
    }

    /**
     * The readable content of one page, as blocks flattened to plain text.
     *
     * @return array{title: string, blocks: list<array<string, string>>, error: ?string}
     */
    public function page(UserIntegration $integration, string $pageId): array
    {
        $token = $integration->credentials()['token'] ?? null;
        if (! $token) return ['title' => '', 'blocks' => [], 'error' => 'Chybí token.'];

        $response = $this->http($token)->get(self::BASE . "/blocks/{$pageId}/children", ['page_size' => self::PAGE_SIZE]);

        if (! $response->successful()) {
            return ['title' => '', 'blocks' => [], 'error' => $this->readableError($response->json(), $response->status())];
        }

        $blocks = [];
        foreach ($response->json('results') ?? [] as $block) {
            $type = $block['type'] ?? '';
            $text = $this->plainText($block[$type]['rich_text'] ?? []);
            if ($text === '' && $type !== 'divider') continue;

            $blocks[] = ['type' => $type, 'text' => $text];
        }

        $integration->markUsed();

        return ['title' => '', 'blocks' => $blocks, 'error' => null];
    }

    /**
     * Writes a page into Notion under a parent the integration can reach.
     *
     * @return array{ok: bool, url: ?string, error: ?string}
     */
    public function createPage(UserIntegration $integration, string $parentId, string $title, string $body): array
    {
        $token = $integration->credentials()['token'] ?? null;
        if (! $token) return ['ok' => false, 'url' => null, 'error' => 'Chybí token.'];

        // Notion refuses a block over 2000 characters, so long text is split on lines.
        $paragraphs = collect(preg_split('/\n{2,}/u', $body) ?: [])
            ->flatMap(fn (string $chunk) => str_split($chunk, 1900))
            ->filter(fn (string $chunk) => trim($chunk) !== '')
            ->take(90)
            ->map(fn (string $chunk) => [
                'object' => 'block',
                'type' => 'paragraph',
                'paragraph' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $chunk]]]],
            ])->values()->all();

        $response = $this->http($token)->post(self::BASE . '/pages', [
            'parent' => ['page_id' => $parentId],
            'properties' => [
                'title' => [['type' => 'text', 'text' => ['content' => Str::limit($title, 190, '')]]],
            ],
            'children' => $paragraphs,
        ]);

        if (! $response->successful()) {
            $message = $this->readableError($response->json(), $response->status());
            $integration->markFailed($message);

            return ['ok' => false, 'url' => null, 'error' => $message];
        }

        $integration->markUsed();

        return ['ok' => true, 'url' => $response->json('url'), 'error' => null];
    }

    /** @return array<string, mixed>|null */
    private function toDocument(UserIntegration $integration, array $result): ?array
    {
        $id = $result['id'] ?? null;
        if (! $id) return null;

        $object = $result['object'] ?? 'page';
        $title = $object === 'database'
            ? $this->plainText($result['title'] ?? [])
            : $this->pageTitle($result);

        return [
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $integration->gallery_space_id,
            'provider' => 'notion',
            'external_id' => (string) $id,
            'kind' => $object === 'database' ? 'database' : 'page',
            'title' => Str::limit($title ?: 'Bez názvu', 390, ''),
            'url' => $result['url'] ?? null,
            'icon' => $result['icon']['emoji'] ?? null,
            'excerpt' => null,
            'external_updated_at' => isset($result['last_edited_time'])
                ? \Illuminate\Support\Carbon::parse($result['last_edited_time'])
                : null,
            'synced_at' => now(),
        ];
    }

    /** A page's title lives in whichever property happens to be of type "title". */
    private function pageTitle(array $page): string
    {
        foreach ($page['properties'] ?? [] as $property) {
            if (($property['type'] ?? '') === 'title') {
                return $this->plainText($property['title'] ?? []);
            }
        }

        return '';
    }

    /** @param  array<int, array<string, mixed>>  $richText */
    private function plainText(array $richText): string
    {
        return trim(collect($richText)->map(fn ($piece) => $piece['plain_text'] ?? '')->implode(''));
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->withHeaders(['Notion-Version' => self::VERSION])
            ->timeout(12)
            ->retry(1, 300);
    }

    /** Notion's own message when it gives one; a plain sentence otherwise. */
    private function readableError(mixed $body, int $status): string
    {
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        return match (true) {
            $status === 401 => 'Notion token je neplatný nebo byl odvolán.',
            $status === 403 => 'Integrace nemá k tomuto obsahu přístup. Nasdílejte jí stránku přímo v Notionu.',
            $status === 429 => 'Notion dočasně omezil počet požadavků. Zkuste to za chvíli.',
            is_string($message) && $message !== '' => Str::limit($message, 300),
            default => "Notion odpověděl chybou {$status}.",
        };
    }
}
