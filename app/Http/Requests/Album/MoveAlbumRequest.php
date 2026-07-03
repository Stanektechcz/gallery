<?php

namespace App\Http\Requests\Album;

use Illuminate\Foundation\Http\FormRequest;

class MoveAlbumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'parent_id' => 'nullable|integer|exists:albums,id|different:album_id',
        ];
    }
}
