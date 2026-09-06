<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nálada dne, 1 až 5.
 *
 * Jeden zápis na člověka a den: nálada se přepisuje, ne přidává. Chybějící den
 * je chybějící řádek, ne nula — „nezapsáno" a „bylo mi mizerně" nejsou totéž.
 */
class WellbeingMood extends Model
{
    protected $fillable = ['gallery_space_id', 'user_id', 'day', 'value', 'note'];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    public function kdo()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
