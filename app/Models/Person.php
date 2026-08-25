<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'people';

    protected $fillable = [
        'gallery_space_id', 'name', 'nickname', 'birth_date',
        'description', 'cover_media_id', 'is_favorite', 'is_hidden', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date'  => 'date',
            'is_favorite' => 'boolean',
            'is_hidden'   => 'boolean',
        ];
    }

    public function gallerySpace() { return $this->belongsTo(GallerySpace::class); }
    public function cover()        { return $this->belongsTo(MediaItem::class, 'cover_media_id'); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    public function media()
    {
        // Bez `withTimestamps()`: spojovací tabulka má jen `created_at`, `updated_at` v ní
        // nikdy nebylo. `withTimestamps()` si žádá obojí, takže dotaz spadl na chybějící
        // sloupec a detail osoby vracel 500. Druhá strana vztahu, MediaItem::people(),
        // to má správně už teď a bere `created_at` přes withPivot.
        return $this->belongsToMany(MediaItem::class, 'media_person')
            ->withPivot(['tagged_by', 'created_at']);
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_person');
    }
}
