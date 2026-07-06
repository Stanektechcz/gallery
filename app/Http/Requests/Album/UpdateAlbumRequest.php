<?php

namespace App\Http\Requests\Album;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAlbumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'title'            => 'sometimes|string|max:255',
            'description'      => 'nullable|string|max:5000',
            'event_date_start' => 'nullable|date',
            'event_date_end'   => 'nullable|date|after_or_equal:event_date_start',
            'color'            => 'nullable|string|max:20',
            'icon'             => 'nullable|string|max:50',
            'visibility'       => 'nullable|in:private,shared,public',
            'sort_mode'        => 'nullable|in:date_taken,date_uploaded,title,manual',
            'sort_direction'   => 'nullable|in:asc,desc',
            'cover_media_id'   => 'nullable|integer|exists:media_items,id',
            // Location
            'location_name'    => 'nullable|string|max:255',
            'latitude'         => 'nullable|numeric|between:-90,90',
            'longitude'        => 'nullable|numeric|between:-180,180',
            'location_country' => 'nullable|string|max:100',
            'location_country_code' => 'nullable|string|max:3',
        ];
    }
}
