<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Kdo se smí podívat na cizí rozpočet nebo cestu.
 *
 * Vlastnictví je v samotném rozpočtu (`owner_user_id`); tahle tabulka řeší jen to,
 * komu dalšímu se přístup dal. Dvě různé otázky, dvě různá místa — kdyby byl vlastník
 * jen dalším řádkem v přístupech, nešlo by odlišit „patří mi to" od „vidím to".
 */
class FinanceAccess extends Model
{
    protected $table = 'finance_access';

    protected $fillable = [
        'gallery_space_id', 'subject_type', 'subject_id', 'user_id', 'can_edit',
    ];

    protected function casts(): array
    {
        return ['can_edit' => 'boolean'];
    }

    /**
     * Omezí dotaz na to, co uživatel smí vidět.
     *
     * Společné (bez vlastníka) vidí každý; vlastní vidí vlastník; cizí jen ten, komu
     * se přístup dal. Píše se to jednou a používají to rozpočty i cesty — dvě kopie
     * by znamenaly dvě místa, kde se dá pravidlo omylem napsat volněji.
     *
     * @param  Builder<covariant Model>  $dotaz
     */
    public static function viditelne(Builder $dotaz, string $druh, int $userId): Builder
    {
        return $dotaz->where(function (Builder $q) use ($druh, $userId) {
            $q->whereNull('owner_user_id')
                ->orWhere('owner_user_id', $userId)
                ->orWhereIn('id', static::query()
                    ->where('subject_type', $druh)
                    ->where('user_id', $userId)
                    ->select('subject_id'));
        });
    }

    /** Smí uživatel do téhle věci zapisovat? */
    public static function smiUpravit(string $druh, int $subjectId, ?int $ownerId, int $userId): bool
    {
        if ($ownerId === null || $ownerId === $userId) {
            return true;
        }

        return static::where('subject_type', $druh)
            ->where('subject_id', $subjectId)
            ->where('user_id', $userId)
            ->where('can_edit', true)
            ->exists();
    }

    /**
     * S kým je to nasdílené — pro obrazovku.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sdileniPro(string $druh, int $subjectId): array
    {
        return static::where('subject_type', $druh)
            ->where('subject_id', $subjectId)
            ->with('user:id,name')
            ->get()
            ->map(fn (self $p) => [
                'user_id' => $p->user_id,
                'name' => $p->user?->name,
                'can_edit' => $p->can_edit,
            ])->all();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
