<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PlaceReview extends Model
{
    protected $fillable = [
        'uuid', 'gallery_space_id', 'place_id', 'place_plan_id', 'author_user_id', 'status',
        'visited_at', 'visit_context', 'party_size', 'overall_rating', 'service_rating',
        'staff_friendliness_rating', 'food_rating', 'food_quality_rating', 'drink_rating',
        'speed_rating', 'menu_rating', 'atmosphere_rating', 'cleanliness_rating', 'value_rating',
        'wait_minutes', 'total_amount', 'currency', 'would_return', 'recommends', 'positives',
        'improvements', 'notes', 'next_time_note',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'party_size' => 'integer',
            'wait_minutes' => 'integer',
            'total_amount' => 'float',
            'would_return' => 'boolean',
            'recommends' => 'boolean',
            'overall_rating' => 'float',
            'service_rating' => 'float',
            'staff_friendliness_rating' => 'float',
            'food_rating' => 'float',
            'food_quality_rating' => 'float',
            'drink_rating' => 'float',
            'speed_rating' => 'float',
            'menu_rating' => 'float',
            'atmosphere_rating' => 'float',
            'cleanliness_rating' => 'float',
            'value_rating' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PlaceReview $review) => $review->uuid ??= (string) Str::uuid());
    }

    public function place() { return $this->belongsTo(Place::class); }
    public function author() { return $this->belongsTo(User::class, 'author_user_id'); }
    public function items() { return $this->hasMany(PlaceReviewItem::class)->orderBy('sort_order'); }
    public function media()
    {
        return $this->belongsToMany(MediaItem::class, 'place_review_media')
            ->withPivot(['place_review_item_id', 'subject', 'caption', 'sort_order', 'created_at'])
            ->orderByPivot('sort_order');
    }
}
