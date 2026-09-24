<?php

namespace App\Http\Requests\Album;

use App\Models\Album;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlbumRequest extends FormRequest
{
    /**
     * Galerie upravovaného alba.
     *
     * Album se hledá přes model s globálním rozsahem, takže cizí album tu
     * nenajde nic — a `0` pak nepustí žádný obal. Kontroler cizí album odmítne
     * sám (`firstOrFail`).
     */
    private function galerieAlba(): int
    {
        return (int) Album::where('uuid', (string) $this->route('uuid'))->value('gallery_space_id');
    }

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'event_date_start' => 'nullable|date',
            'event_date_end' => 'nullable|date|after_or_equal:event_date_start',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
            'visibility' => 'nullable|in:private,shared,public',
            'sort_mode' => 'nullable|in:date_taken,date_uploaded,title,manual',
            'sort_direction' => 'nullable|in:asc,desc',
            /*
             * Obal jen z galerie alba.
             *
             * Přihlášenému se cizí obal neukáže (vztah má globální rozsah), ale
             * veřejný sdílený odkaz běží bez přihlášení, rozsah tam ustupuje —
             * a cizí fotka by se ukázala každému, kdo má odkaz.
             */
            'cover_media_id' => ['nullable', 'integer', Rule::exists('media_items', 'id')->where('gallery_space_id', $this->galerieAlba())],
            // Location
            'location_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'location_country' => 'nullable|string|max:100',
            'location_country_code' => 'nullable|string|max:3',
        ];
    }
}
