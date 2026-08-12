<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\IntegrationDocument;
use App\Models\UserIntegration;
use App\Services\Integrations\DiscordClient;
use App\Models\StorageConnection;
use App\Services\Integrations\NotionClient;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Storage\StorageResolver;
use App\Services\Storage\WebDavClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The connections a person holds: their own, and the ones their space shares.
 *
 * Reads follow readableBy() — mine or shared — while every write checks ownership, so a
 * shared connection can be used by both members but disconnected only by whoever brought
 * it. Tokens are never returned by any endpoint here, in any shape.
 */
class IntegrationConnectionController extends Controller
{
    private const PROVIDERS = ['notion', 'discord'];

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $user = $request->user();
        $space = $this->space($request);

        $connections = UserIntegration::with('owner:id,name')
            ->where('gallery_space_id', $space->id)
            ->readableBy($user)
            ->orderBy('provider')->get();

        return response()->json([
            'connections' => $connections->map(fn (UserIntegration $row) => $this->payload($row, $user->id))->values(),
            'documents' => $this->documents($space->id, $connections->pluck('id')->all()),
            'discord_ready' => app(DiscordClient::class)->configured(),
            // The catalogue travels with the list, so the screen can only ever offer a
            // service the server knows how to accept.
            'catalogue' => app(ProviderRegistry::class)->catalogue(),
            'storage' => $this->storage($space->id),
            // How full the server disk is. The limit was enforced on upload but shown
            // nowhere, so the first anybody heard of it was a refused photograph.
            'quota' => app(\App\Services\Billing\EntitlementService::class)->storageUsage($space),
        ]);
    }

    /**
     * Connects a service that authorises with a token the person pastes.
     *
     * One endpoint rather than one per service. Notion keeps its own because it verifies
     * the token against Notion before storing it — a check worth having where it exists.
     * The rest have no probe to make, and inventing one that always says yes would be a
     * reassurance rather than a check.
     *
     * The credential is stored encrypted and never returned. What the screen sees
     * afterwards is that a connection exists, not what it is made of.
     */
    public function connectToken(Request $request, string $provider): JsonResponse
    {
        $this->available();
        $this->write($request);

        $registry = app(ProviderRegistry::class);
        abort_unless($registry->has($provider), 404, 'Tuhle službu neznáme.');
        abort_if($provider === 'notion', 422, 'Notion se připojuje vlastní cestou.');

        // WebDAV is storage rather than an integration: it belongs to the space and is
        // checked against the server before anything is stored, so a typo fails here
        // rather than silently at the first photograph.
        if ($provider === 'webdav') return $this->connectWebDav($request);
        abort_unless((ProviderRegistry::PROVIDERS[$provider]['auth'] ?? '') === 'token', 422,
            'Tuhle službu nelze připojit tokenem.');

        $data = $request->validate([
            'token' => 'required|string|min:8|max:500',
            'visibility' => 'required|in:personal,shared',
            'label' => 'nullable|string|max:120',
        ]);

        abort_unless(in_array($data['visibility'], $registry->scopes($provider), true), 422,
            'Tuhle službu takto sdílet nelze.');

        $space = $this->space($request);

        $connection = new UserIntegration([
            'gallery_space_id' => $space->id,
            'user_id' => $request->user()->id,
            'provider' => $provider,
            'visibility' => $data['visibility'],
            'label' => $data['label'] ?: ProviderRegistry::PROVIDERS[$provider]['name'],
            'account_name' => $data['label'] ?: null,
            'status' => 'active',
        ]);
        $connection->setCredentials(['token' => $data['token']]);
        $connection->save();

        return response()->json($this->payload($connection->fresh(), $request->user()->id), 201);
    }

    /**
     * Connects somebody's own WebDAV storage.
     *
     * Three values arrive in one field as address|user|password, because the modal has one
     * input and splitting it would mean a second shape of form for a single provider. The
     * password is an app password by instruction; nothing here can tell the difference, so
     * the guide says it and this does not pretend to enforce it.
     *
     * Verified against the server before it is stored. An address that answers 401 fails
     * here with a reason rather than at the first photograph nobody is watching.
     */
    private function connectWebDav(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:500']);

        $parts = array_map('trim', explode('|', $data['token']));
        abort_unless(count($parts) === 3 && filled($parts[0]), 422,
            'Zadejte adresu, uživatele a heslo aplikace oddělené svislítkem: adresa|uzivatel|heslo');

        [$url, $user, $pass] = $parts;
        abort_unless(str_starts_with($url, 'https://'), 422,
            'Adresa musí začínat https:// — přes http by heslo cestovalo otevřeně.');

        $space = $this->space($request);
        abort_unless(app(StorageResolver::class)->mayManage($space, $request->user()->id), 403,
            'Úložiště prostoru může připojit jen vlastník tarifu.');

        $connection = StorageConnection::updateOrCreate(
            ['provider' => 'webdav', 'gallery_space_id' => $space->id],
            [
                'owner_user_id' => $request->user()->id,
                'account_email' => $user,
                'encrypted_access_token' => Crypt::encryptString(json_encode([
                    'url' => rtrim($url, '/'), 'user' => $user, 'pass' => $pass,
                ])),
                'connection_status' => 'connected',
            ],
        );

        $probe = app(WebDavClient::class)->probe($connection);

        if (! $probe['ok']) {
            $connection->delete();
            abort(422, 'Připojení se nepodařilo ověřit: ' . $probe['error']);
        }

        return response()->json(['provider' => 'webdav', 'account' => $user], 201);
    }

    /**
     * The space's Drive, summarised for the same screen.
     *
     * Reported rather than merged. Drive is not a per-person integration: it holds the
     * gallery's files and belongs to the whole space, so it keeps its own table and its
     * own connect flow. One screen, two mechanisms — which is honest, because they behave
     * differently and pretending otherwise would surprise somebody at the worst moment.
     */
    private function storage(int $spaceId): array
    {
        if (! Schema::hasTable('storage_connections')) return [];

        // This space's own, not the installation's. Rows predating the space column are
        // left out rather than shown to everybody — an unattributed connection belongs to
        // nobody, and guessing wrong here would show one customer another's account.
        return StorageConnection::when(
                Schema::hasColumn('storage_connections', 'gallery_space_id'),
                fn ($query) => $query->where('gallery_space_id', $spaceId),
            )
            ->get()
            ->mapWithKeys(fn (StorageConnection $row) => [$row->provider => [
                'account' => $row->account_email,
                'status' => $row->connection_status,
                'last_ok' => $row->last_successful_request_at?->toIso8601String(),
                'last_error' => $row->last_error_message,
                'last_error_at' => $row->last_error_at?->toIso8601String(),
            ]])->all();
    }

    /**
     * Connects Notion with a token the person created in their own workspace.
     *
     * The token is checked against Notion before anything is stored, so a typo fails
     * here with a clear reason rather than silently later during a sync.
     */
    public function connectNotion(Request $request, NotionClient $notion): JsonResponse
    {
        $this->available();
        $this->write($request);
        $space = $this->space($request);

        $data = $request->validate([
            'token' => 'required|string|min:20|max:200',
            'visibility' => 'required|in:personal,shared',
            'label' => 'nullable|string|max:120',
        ]);

        $probe = $notion->probe($data['token']);
        abort_unless($probe['ok'], 422, $probe['error'] ?? 'Token se nepodařilo ověřit.');

        $connection = new UserIntegration([
            'gallery_space_id' => $space->id,
            'user_id' => $request->user()->id,
            'provider' => 'notion',
            'visibility' => $data['visibility'],
            'label' => $data['label'] ?? $probe['account_name'],
            'account_id' => $probe['account_id'],
            'account_name' => $probe['account_name'],
            'account_avatar' => $probe['account_avatar'],
            'status' => 'active',
        ]);
        $connection->setCredentials(['token' => $data['token']]);
        $connection->save();

        // A first sync straight away, so the screen shows something real immediately.
        $result = $notion->sync($connection);

        return response()->json([
            'connection' => $this->payload($connection->fresh('owner'), $request->user()->id),
            'synced' => $result['synced'],
            'error' => $result['error'],
        ], 201);
    }

    public function sync(Request $request, string $uuid, NotionClient $notion): JsonResponse
    {
        $this->available();
        $connection = $this->readable($request, $uuid);
        abort_unless($connection->provider === 'notion', 422, 'Tuhle službu synchronizovat nelze.');

        $result = $notion->sync($connection);

        return response()->json([
            'connection' => $this->payload($connection->fresh('owner'), $request->user()->id),
            'synced' => $result['synced'],
            'error' => $result['error'],
        ]);
    }

    /** Opens one indexed page. The body is fetched live; we never mirrored it. */
    public function document(Request $request, string $uuid, NotionClient $notion): JsonResponse
    {
        $this->available();
        $space = $this->space($request);

        $document = IntegrationDocument::with('integration')
            ->where('gallery_space_id', $space->id)->where('uuid', $uuid)->firstOrFail();

        $connection = $document->integration;
        abort_unless($connection && $this->mayRead($connection, $request->user()->id), 403, 'K téhle stránce nemáte přístup.');

        $page = $notion->page($connection, $document->external_id);

        return response()->json([
            'title' => $document->title,
            'url' => $document->url,
            'blocks' => $page['blocks'],
            'error' => $page['error'],
        ]);
    }

    /** Sends a journal entry or a note into Notion, under a page it can already reach. */
    public function push(Request $request, string $uuid, NotionClient $notion): JsonResponse
    {
        $this->available();
        $this->write($request);
        $connection = $this->readable($request, $uuid);
        abort_unless($connection->provider === 'notion', 422, 'Do téhle služby zapisovat nelze.');

        $data = $request->validate([
            'parent_id' => 'required|string|max:190',
            'title' => 'required|string|max:190',
            'body' => 'required|string|max:40000',
        ]);

        $result = $notion->createPage($connection, $data['parent_id'], $data['title'], $data['body']);
        abort_unless($result['ok'], 422, $result['error'] ?? 'Stránku se nepodařilo vytvořit.');

        return response()->json(['url' => $result['url']]);
    }

    /** What Discord will tell us about the linked account. */
    public function discordProfile(Request $request, string $uuid, DiscordClient $discord): JsonResponse
    {
        $this->available();
        $connection = $this->readable($request, $uuid);
        abort_unless($connection->provider === 'discord', 422, 'Tohle není propojení s Discordem.');

        return response()->json([
            'me' => $discord->me($connection),
            'guilds' => $discord->guilds($connection),
            'connections' => $discord->connections($connection),
        ]);
    }

    /** Stores the webhook a space wants its notifications sent to. */
    public function discordWebhook(Request $request, string $uuid, DiscordClient $discord): JsonResponse
    {
        $this->available();
        $this->write($request);
        $connection = $this->owned($request, $uuid);

        $data = $request->validate(['webhook_url' => 'nullable|string|max:600']);
        $url = $data['webhook_url'] ?? null;

        abort_if($url !== null && ! $discord->isWebhookUrl($url), 422, 'Tohle není platná adresa webhooku Discordu.');

        $connection->setCredentials(['webhook_url' => $url] + $connection->credentials());
        $connection->save();

        if ($url) {
            abort_unless(
                $discord->notify($url, 'Propojení s Maki je hotové. Sem budou chodit upozornění.'),
                422,
                'Na webhook se nepodařilo odeslat zkušební zprávu.',
            );
        }

        return response()->json($this->payload($connection->fresh('owner'), $request->user()->id));
    }

    public function updateVisibility(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $connection = $this->owned($request, $uuid);

        $data = $request->validate(['visibility' => 'required|in:personal,shared']);
        $connection->update(['visibility' => $data['visibility']]);

        return response()->json($this->payload($connection->fresh('owner'), $request->user()->id));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        // Documents go with it: the cascade is on the foreign key.
        $this->owned($request, $uuid)->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(UserIntegration $row, int $viewerId): array
    {
        return [
            'uuid' => $row->uuid,
            'provider' => $row->provider,
            'visibility' => $row->visibility,
            'label' => $row->label,
            'account_name' => $row->account_name,
            'account_avatar' => $row->account_avatar,
            'status' => $row->status,
            'last_error' => $row->last_error,
            'last_used_at' => $row->last_used_at?->toIso8601String(),
            'owner' => ['id' => $row->user_id, 'name' => $row->owner?->name],
            'is_mine' => $row->user_id === $viewerId,
            'can_manage' => $row->user_id === $viewerId,
            // Deliberately absent: anything derived from the credentials.
            'has_webhook' => ($row->credentials()['webhook_url'] ?? null) !== null,
            'documents' => $row->documents()->count(),
        ];
    }

    /**
     * @param  list<int>  $connectionIds
     * @return list<array<string, mixed>>
     */
    private function documents(int $spaceId, array $connectionIds): array
    {
        if (! $connectionIds) return [];

        return IntegrationDocument::where('gallery_space_id', $spaceId)
            ->whereIn('user_integration_id', $connectionIds)
            ->orderByDesc('external_updated_at')
            ->limit(200)->get()
            ->map(fn (IntegrationDocument $doc) => [
                'uuid' => $doc->uuid,
                'title' => $doc->title,
                'kind' => $doc->kind,
                'icon' => $doc->icon,
                'url' => $doc->url,
                'provider' => $doc->provider,
                'updated_at' => $doc->external_updated_at?->toIso8601String(),
            ])->values()->all();
    }

    private function mayRead(UserIntegration $connection, int $viewerId): bool
    {
        return $connection->user_id === $viewerId || $connection->isShared();
    }

    /** Readable means mine or shared with me; using a shared connection is the point. */
    private function readable(Request $request, string $uuid): UserIntegration
    {
        return UserIntegration::where('gallery_space_id', $this->space($request)->id)
            ->readableBy($request->user())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** Changing or removing a connection stays with whoever brought it. */
    private function owned(Request $request, string $uuid): UserIntegration
    {
        $connection = $this->readable($request, $uuid);
        abort_unless($connection->isManageableBy($request->user()), 403, 'Propojení může spravovat jen ten, kdo ho vytvořil.');

        return $connection;
    }

    private function space(Request $request): GallerySpace
    {
        $id = $request->integer('gallery_space_id') ?: null;
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze měnit propojení.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('user_integrations'), 503, 'Pro propojení dokončete databázové migrace.');
    }
}
