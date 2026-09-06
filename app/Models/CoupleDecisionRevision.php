<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Znění rozhodnutí, které tehdy platilo. */
class CoupleDecisionRevision extends Model
{
    protected $fillable = ['couple_decision_id', 'changed_by', 'wording', 'valid_from'];

    protected function casts(): array
    {
        return ['valid_from' => 'date'];
    }

    public function rozhodnuti()
    {
        return $this->belongsTo(CoupleDecision::class, 'couple_decision_id');
    }

    public function kdo()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
