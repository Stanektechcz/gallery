<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PlaceReviewItem extends Model
{
    protected $fillable = [
        'uuid', 'place_review_id', 'category', 'name', 'quantity', 'overall_rating',
        'quality_rating', 'presentation_rating', 'portion_rating', 'value_rating',
        'price', 'currency', 'would_order_again', 'note', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'overall_rating' => 'float',
            'quality_rating' => 'float',
            'presentation_rating' => 'float',
            'portion_rating' => 'float',
            'value_rating' => 'float',
            'price' => 'float',
            'would_order_again' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PlaceReviewItem $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function review() { return $this->belongsTo(PlaceReview::class, 'place_review_id'); }
}
