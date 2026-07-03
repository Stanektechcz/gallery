<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    protected $fillable = [
        'name', 'country', 'country_code', 'region', 'city', 'district',
        'address', 'latitude', 'longitude', 'radius_meters', 'source',
        'external_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
        ];
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function media()
    {
        return $this->belongsToMany(MediaItem::class, 'media_place')
            ->withPivot('is_primary');
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_place')
            ->withPivot('is_primary');
    }
}
