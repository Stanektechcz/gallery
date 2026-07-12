<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    protected $fillable = [
        'gallery_space_id',
        'name',
        'type',
        'country',
        'country_code',
        'region',
        'city',
        'district',
        'address',
        'latitude',
        'longitude',
        'radius_meters',
        'source',
        'description',
        'website_url',
        'osm_id',
        'osm_type',
        'external_id',
        'created_by',
        'is_rain_friendly',
        'is_accessible',
        'is_photogenic',
        'opens_early',
        'price_level',
        'estimated_visit_minutes',
        'personal_rating',
        'next_time_note',
    ];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
            'is_rain_friendly' => 'boolean',
            'is_accessible' => 'boolean',
            'is_photogenic' => 'boolean',
            'opens_early' => 'boolean',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

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
