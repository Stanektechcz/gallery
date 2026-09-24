<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SharedLink extends Model
{
    protected $fillable = [
        'uuid', 'token', 'created_by', 'gallery_space_id', 'target_type', 'target_id',
        'name', 'description', 'password_hash', 'allow_download', 'allow_guest_upload', 'allow_comments',
        'show_metadata', 'hide_gps', 'upload_limit_bytes', 'max_uses', 'use_count',
        'expires_at', 'is_active',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'allow_download' => 'boolean',
            'allow_guest_upload' => 'boolean',
            'allow_comments' => 'boolean',
            'show_metadata' => 'boolean',
            'hide_gps' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SharedLink $link) {
            $link->uuid ??= (string) Str::uuid();
            $link->token ??= Str::random(40);
        });

        /*
         * S odkazem odchází i to, co přes něj hosté nahráli a nikdo neprošel.
         *
         * Řádky nahrávek smaže databáze sama (`cascadeOnDelete`), soubory ne:
         * ležely by na disku napořád a nic by je už nenašlo — i fotky, kterých
         * se dvojice smazáním odkazu chtěla zbavit. Schválené jsou už v galerii
         * a odmítnuté smazané, takže jde jen o čekající.
         */
        static::deleting(function (SharedLink $link) {
            GuestUpload::where('shared_link_id', $link->id)
                ->where('status', 'pending')
                ->pluck('storage_path')
                ->each(fn (string $cesta) => self::smazNahravku($cesta));
        });
    }

    /**
     * Soubor nahrávky i s její složkou — ale jen složku přesně toho tvaru.
     *
     * `deleteDirectory(dirname(...))` nad cestou bez složky by dostal `.`
     * a smazal celý disk. Složka se proto maže jen tehdy, když je to
     * `guest_uploads/{uuid}`; jinak jen samotný soubor.
     */
    public static function smazNahravku(string $cesta): void
    {
        $slozka = dirname($cesta);

        if (preg_match('#^guest_uploads/[0-9a-f-]{36}$#i', $slozka)) {
            Storage::disk('local')->deleteDirectory($slozka);

            return;
        }

        Storage::disk('local')->delete($cesta);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function gallerySpace()
    {
        return $this->belongsTo(GallerySpace::class);
    }

    public function mediaItems()
    {
        return $this->belongsToMany(MediaItem::class, 'shared_link_media');
    }

    public function guestUploads()
    {
        return $this->hasMany(GuestUpload::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isUsageLimitReached(): bool
    {
        return $this->max_uses !== null && $this->use_count >= $this->max_uses;
    }

    public function isAccessible(): bool
    {
        return $this->is_active && ! $this->isExpired() && ! $this->isUsageLimitReached();
    }

    public function verifyPassword(string $password): bool
    {
        if ($this->password_hash === null) {
            return true;
        }

        return password_verify($password, $this->password_hash);
    }
}
