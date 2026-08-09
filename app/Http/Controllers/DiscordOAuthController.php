<?php

namespace App\Http\Controllers;

use App\Models\GallerySpace;
use App\Models\UserIntegration;
use App\Services\Integrations\DiscordClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The browser half of linking a Discord account.
 *
 * Kept out of the API controller because it is a redirect flow, not JSON: the person
 * leaves for Discord and comes back to a page, and both halves need the session.
 */
class DiscordOAuthController extends Controller
{
    private const STATE_KEY = 'discord.oauth.state';

    public function redirect(Request $request, DiscordClient $discord): RedirectResponse
    {
        abort_unless($discord->configured(), 503, 'Discord zatím není nastavený. Doplňte údaje aplikace v administraci.');

        // A random state, held in the session and checked on the way back: without it
        // anyone could hand the user a callback URL and link an account of their choosing.
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);
        $request->session()->put(self::STATE_KEY . '.visibility', $request->query('visibility') === 'shared' ? 'shared' : 'personal');

        return redirect()->away($discord->authorizeUrl($state, route('discord.callback')));
    }

    public function callback(Request $request, DiscordClient $discord): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $visibility = $request->session()->pull(self::STATE_KEY . '.visibility', 'personal');

        if ($request->query('error')) {
            return redirect('/settings/propojeni')->with('warning', 'Propojení s Discordem bylo zrušeno.');
        }

        // Compared in constant time and only once: the state was pulled, not read.
        if (! $expected || ! is_string($request->query('state')) || ! hash_equals($expected, $request->query('state'))) {
            return redirect('/settings/propojeni')->with('error', 'Propojení s Discordem se nepodařilo ověřit. Zkuste to znovu.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect('/settings/propojeni')->with('error', 'Discord nevrátil ověřovací kód.');
        }

        $exchange = $discord->exchange($code, route('discord.callback'));
        if (! ($exchange['ok'] ?? false)) {
            return redirect('/settings/propojeni')->with('error', $exchange['error'] ?? 'Propojení s Discordem selhalo.');
        }

        $space = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id))
            ->orderByDesc('is_default')->firstOrFail();

        // One Discord account per person per space: linking again refreshes it in place
        // rather than leaving a trail of dead connections.
        $connection = UserIntegration::firstOrNew([
            'gallery_space_id' => $space->id,
            'user_id' => $request->user()->id,
            'provider' => 'discord',
        ]);

        $connection->fill([
            'visibility' => $visibility,
            'status' => 'active',
            'last_error' => null,
            'expires_at' => $exchange['expires_at'] ?? null,
        ]);
        // The webhook, if one was already set, belongs to the space and outlives a relink.
        $connection->setCredentials(($exchange['credentials'] ?? []) + array_filter([
            'webhook_url' => $connection->exists ? ($connection->credentials()['webhook_url'] ?? null) : null,
        ]));
        $connection->save();

        if ($profile = $discord->me($connection)) {
            $connection->update([
                'account_id' => $profile['id'],
                'account_name' => $profile['global_name'] ?: $profile['username'],
                'account_avatar' => $profile['avatar'],
                'label' => $connection->label ?: ($profile['global_name'] ?: $profile['username']),
            ]);
        }

        return redirect('/settings/propojeni')->with('success', 'Discord je propojený.');
    }
}
