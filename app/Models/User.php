<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'uuid', 'name', 'email', 'password', 'role', 'avatar_path', 'avatar_preset', 'avatar_colour',
        'is_active', 'invited_by', 'invited_by_user_id', 'invitation_token', 'invitation_accepted_at',
        'preferences', 'last_login_at', 'last_login_ip', 'read_only_mode',
    ];

    protected $hidden = ['password', 'remember_token', 'invitation_token'];

    /** Every place that shows a person shows their face; deriving it twice invites drift. */
    protected $appends = ['avatar_url', 'avatar_fallback'];

    /**
     * Where to fetch the uploaded picture, if there is one.
     *
     * Uploads live on the private disk and are streamed by an authorised route, so this
     * is never a direct file path — a face is as private as the gallery it belongs to.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? route('api.avatar.show', ['uuid' => $this->uuid]) : null;
    }

    /**
     * What to draw when there is no picture: a preset name, or the initial on a stable
     * colour. The colour is stored rather than derived so renaming someone does not
     * silently repaint them.
     */
    public function getAvatarFallbackAttribute(): array
    {
        return [
            'preset' => $this->avatar_preset,
            'initial' => mb_strtoupper(mb_substr($this->name ?? '?', 0, 1)),
            'colour' => $this->avatar_colour ?: self::colourFor($this->uuid ?? (string) $this->id),
        ];
    }

    /** Deterministic, so the same person is the same colour on every device. */
    public static function colourFor(string $seed): string
    {
        $palette = ['#7c5cff', '#e0568a', '#2fa8a0', '#e08c3a', '#4a80e8', '#9d5ce6', '#2f9e5e', '#d4546a'];

        return $palette[hexdec(substr(md5($seed), 0, 8)) % count($palette)];
    }

    protected function casts(): array
    {
        return [
            'email_verified_at'      => 'datetime',
            'invitation_accepted_at' => 'datetime',
            'last_login_at'          => 'datetime',
            'last_seen_at'           => 'datetime',
            'preferences'            => 'array',
            'is_active'              => 'boolean',
            'read_only_mode'         => 'boolean',
            'password'               => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (User $u) => $u->uuid ??= (string) Str::uuid());
    }

    public function isOwner(): bool   { return $this->role === 'owner'; }
    public function isAdmin(): bool   { return in_array($this->role, ['owner', 'admin']); }
    public function isPartner(): bool { return $this->role === 'partner'; }

    public function gallerySpaces()
    {
        return $this->belongsToMany(GallerySpace::class, 'gallery_space_user')
            ->withPivot(['role', 'can_delete', 'can_share', 'can_download', 'show_in_timeline', 'joined_at'])
            ->withTimestamps();
    }

    public function ownedSpaces()       { return $this->hasMany(GallerySpace::class, 'owner_id'); }
    public function favorites()         { return $this->belongsToMany(MediaItem::class, 'user_favorites')->withTimestamps(); }
    public function ratings()           { return $this->belongsToMany(MediaItem::class, 'user_ratings')->withPivot('rating')->withTimestamps(); }
    public function storageConnections(){ return $this->hasMany(StorageConnection::class, 'owner_user_id'); }
    public function savedSearches()     { return $this->hasMany(SavedSearch::class); }
    public function auditLogs()         { return $this->hasMany(AuditLog::class); }
    public function uploadSessions()    { return $this->hasMany(UploadSession::class); }
    public function createdRecipes()    { return $this->hasMany(Recipe::class, 'created_by'); }
    public function cookingSessions()   { return $this->hasMany(RecipeCookingSession::class, 'created_by'); }
    public function inviter()            { return $this->belongsTo(User::class, 'invited_by_user_id'); }

    public function activeStorageConnection(): ?StorageConnection
    {
        return $this->storageConnections()->where('connection_status', 'healthy')->latest()->first();
    }
}
