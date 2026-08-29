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

    /**
     * Smí uživatel do téhle věci zapisovat?
     *
     * Kromě vlastníka a toho, komu se právo dalo, smí vždycky i **majitel prostoru.**
     * Bez téhle pojistky vzniká slepá ulička: kdo rozpočet vidí, ale nesmí ho měnit,
     * nemůže změnit ani to, kdo ho smí měnit — a když vlastník zrovna není u telefonu,
     * nedá se s tím udělat vůbec nic. Ve dvou lidech je to nepoužitelné.
     */
    public static function smiUpravit(string $druh, int $subjectId, ?int $ownerId, int $userId): bool
    {
        if ($ownerId === null || $ownerId === $userId) {
            return true;
        }

        if (static::jeMajitelProstoru($subjectId, $druh, $userId)) {
            return true;
        }

        return static::where('subject_type', $druh)
            ->where('subject_id', $subjectId)
            ->where('user_id', $userId)
            ->where('can_edit', true)
            ->exists();
    }

    /** Založil tenhle člověk celý prostor? Pak se mu nic zamknout nedá. */
    private static function jeMajitelProstoru(int $subjectId, string $druh, int $userId): bool
    {
        $tabulka = $druh === 'trip' ? 'finance_projects' : 'budgets';

        $spaceId = \Illuminate\Support\Facades\DB::table($tabulka)
            ->where('id', $subjectId)->value('gallery_space_id');

        return $spaceId !== null && \Illuminate\Support\Facades\DB::table('gallery_spaces')
            ->where('id', $spaceId)->where('owner_id', $userId)->exists();
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
