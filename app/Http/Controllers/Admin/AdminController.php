<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function dashboard(): Response
    {
        $stats = [
            'users'       => User::count(),
            'media_total' => MediaItem::count(),
            'photos'      => MediaItem::where('media_type', 'photo')->count(),
            'videos'      => MediaItem::where('media_type', 'video')->count(),
            'ready'       => MediaItem::where('status', 'ready')->count(),
            'failed'      => MediaItem::where('status', 'failed')->count(),
            'trashed'     => MediaItem::whereNotNull('trashed_at')->count(),
            'albums'      => \App\Models\Album::count(),
        ];

        $connection = StorageConnection::where('provider', 'google_drive')->first();

        $queue = [
            'pending' => DB::table('jobs')->count(),
            'failed'  => DB::table('failed_jobs')->count(),
        ];

        return Inertia::render('Admin/Dashboard', compact('stats', 'connection', 'queue'));
    }

    public function users(): Response
    {
        $users = User::orderBy('created_at', 'desc')->get();
        return Inertia::render('Admin/Users', compact('users'));
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'  => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'role'  => 'required|in:partner,viewer,admin',
        ]);

        $token = Str::random(60);
        $user  = User::create([
            'uuid'             => (string) Str::uuid(),
            'name'             => $data['name'],
            'email'            => $data['email'],
            'role'             => $data['role'],
            'password'         => \Hash::make(Str::random(32)),
            'invitation_token' => $token,
            'invited_by'       => true,
            'invited_by_user_id' => $request->user()->id,
            'is_active'        => true,
        ]);

        AuditLog::record('admin.invite', $user, ['email' => $data['email']]);

        $inviteUrl = url("/invite/{$token}");
        $deliveryNote = '';
        try {
            $user->notify(new InvitationNotification($inviteUrl, $request->user()->name));
        } catch (\Throwable $exception) {
            report($exception);
            // Never discard a securely created invite because a mail provider is unavailable.
            $deliveryNote = " E-mail se nepodařilo odeslat; použijte zabezpečený odkaz: {$inviteUrl}";
        }

        return back()->with('success', "Pozvánka vytvořena a odeslána na {$user->email}.{$deliveryNote}");
    }

    public function jobs(): Response
    {
        $pending    = DB::table('jobs')->orderBy('created_at', 'desc')->limit(50)->get();
        $failed     = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(50)->get();
        return Inertia::render('Admin/Jobs', compact('pending', 'failed'));
    }

    public function audit(): Response
    {
        $logs = AuditLog::with('user')->orderBy('created_at', 'desc')->paginate(50);
        return Inertia::render('Admin/Audit', compact('logs'));
    }

    public function health(): Response
    {
        // Run gallery:doctor checks programmatically
        $checks = [
            'laravel' => [
                'app_key'   => !empty(config('app.key')),
                'app_debug' => config('app.debug') === false,
                'app_url'   => !empty(config('app.url')),
            ],
            'database' => [
                'connected' => $this->checkDb(),
            ],
            'storage' => [
                'writable'  => is_writable(storage_path('app')),
                'free_gb'   => round(disk_free_space(storage_path()) / 1024 / 1024 / 1024, 1),
            ],
            'binaries' => [
                'ffmpeg'    => is_executable(config('gallery.ffmpeg_path', '/usr/bin/ffmpeg')),
                'exiftool'  => is_executable(config('gallery.exiftool_path', '/usr/bin/exiftool')),
            ],
            'queue' => [
                'pending'   => DB::table('jobs')->count(),
                'failed'    => DB::table('failed_jobs')->count(),
            ],
        ];

        return Inertia::render('Admin/Health', compact('checks'));
    }

    private function checkDb(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
