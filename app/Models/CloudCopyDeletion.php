<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jedna kopie v cloudu, která má po trvalém smazání položky zmizet.
 *
 * Žije déle než položka: vzniká v `MediaPurger` před smazáním řádku a maže ji
 * úloha `RemoveCloudCopy`. Jméno souboru ani titulek nenese — viz migrace.
 */
class CloudCopyDeletion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'gallery_space_id', 'storage_connection_id', 'provider', 'remote_ref',
        'media_uuid', 'reason', 'status', 'attempts', 'last_error', 'done_at',
    ];

    /**
     * Výchozí hodnoty i v modelu, ne jen v databázi — čerstvě založený záznam
     * by jinak do prvního `refresh()` neměl stav a úloha by ho četla jako prázdný.
     */
    protected $attributes = [
        'reason' => 'purge',
        'status' => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'gallery_space_id' => 'integer',
            'storage_connection_id' => 'integer',
            'attempts' => 'integer',
            'done_at' => 'datetime',
        ];
    }

    /**
     * Čekající, na které nikdo den nesáhl.
     *
     * Podle `updated_at`, ne `created_at`: každý pokus úlohy záznam přepíše
     * (nejdelší odstup jsou tři hodiny), takže den ticha znamená, že fronta
     * `drive` nejede nebo se úloha nezařadila. Záznam vrácený do fronty
     * ručním opakováním by podle `created_at` visel hned od začátku.
     */
    public function scopeVisi($query)
    {
        return $query->where('status', self::STATUS_PENDING)->where('updated_at', '<', now()->subDay());
    }

    public function storageConnection()
    {
        return $this->belongsTo(StorageConnection::class);
    }
}
