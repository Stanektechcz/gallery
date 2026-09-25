<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGallerySpace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Album extends Model
{
    use BelongsToGallerySpace, HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'gallery_space_id',
        'trip_id',
        'anniversary_year',
        'parent_id',
        'title',
        'slug',
        'depth',
        'materialized_path',
        'full_display_path',
        'drive_folder_id',
        'drive_parent_folder_id',
        'cover_media_id',
        'description',
        'event_date_start',
        'event_date_end',
        'default_place_id',
        'color',
        'icon',
        'sort_mode',
        'sort_direction',
        'manual_sort_order',
        'visibility',
        'inherit_permissions',
        'created_by',
        'updated_by',
        'sync_status',
        'last_drive_sync_at',
        'media_count',
        'descendant_count',
        'total_size_bytes',
        'story_mode',
        'album_type',
        'smart_rules',
        'event_mode',
        'event_start_at',
        'event_end_at',
        'event_place_name',
        'event_latitude',
        'event_longitude',
        'event_gps_radius',
        'location_name',
        'latitude',
        'longitude',
        'location_country',
        'location_country_code',
    ];

    protected function casts(): array
    {
        return [
            'event_date_start' => 'date',
            'event_date_end' => 'date',
            'event_start_at' => 'datetime',
            'event_end_at' => 'datetime',
            'last_drive_sync_at' => 'datetime',
            'inherit_permissions' => 'boolean',
            'story_mode' => 'boolean',
            'event_mode' => 'boolean',
            'event_latitude' => 'float',
            'event_longitude' => 'float',
            'anniversary_year' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Album $album) {
            $album->uuid ??= (string) Str::uuid();
        });

        static::created(function (Album $album) {
            $album->insertClosureRows();
        });

        static::deleting(function (Album $album) {
            // Only clean closure table on hard delete, not soft delete
            if ($album->isForceDeleting()) {
                DB::table('album_closure')
                    ->where('descendant_id', $album->id)
                    ->orWhere('ancestor_id', $album->id)
                    ->delete();
            }
        });
    }

    // Relations
    public function gallerySpace()
    {
        return $this->belongsTo(GallerySpace::class);
    }

    public function parent()
    {
        return $this->belongsTo(Album::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Album::class, 'parent_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cover()
    {
        return $this->belongsTo(MediaItem::class, 'cover_media_id');
    }

    public function media()
    {
        return $this->belongsToMany(MediaItem::class, 'album_media')
            ->withPivot(['sort_order', 'is_cover', 'added_at', 'added_by'])
            ->orderByPivot('sort_order');
    }

    public function primaryMedia()
    {
        return $this->hasMany(MediaItem::class, 'primary_album_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'album_tag');
    }

    public function people()
    {
        return $this->belongsToMany(Person::class, 'album_person');
    }

    public function places()
    {
        return $this->belongsToMany(Place::class, 'album_place')
            ->withPivot('is_primary');
    }

    public function userPermissions()
    {
        return $this->hasMany(AlbumUserPermission::class);
    }

    // Closure table helpers
    public function ancestors()
    {
        return $this->belongsToMany(Album::class, 'album_closure', 'descendant_id', 'ancestor_id')
            ->withPivot('depth')
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth', 'desc');
    }

    public function descendants()
    {
        return $this->belongsToMany(Album::class, 'album_closure', 'ancestor_id', 'descendant_id')
            ->withPivot('depth')
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth');
    }

    public function insertClosureRows(): void
    {
        static::vlozRadkyUzaveru((int) $this->id, $this->parent_id ? (int) $this->parent_id : null);
    }

    /**
     * Move this album to a new parent. Prevents circular moves.
     */
    public function moveTo(?int $newParentId): void
    {
        if ($newParentId !== null) {
            // Prevent moving into own descendant
            $isDescendant = DB::table('album_closure')
                ->where('ancestor_id', $this->id)
                ->where('descendant_id', $newParentId)
                ->where('depth', '>', 0)
                ->exists();

            // Druhá pojistka podle `parent_id`: uzávěr mohl rozbít dřívější
            // přesun (viz níže) a sám o sobě smyčku neodhalí.
            if ($isDescendant || $newParentId === $this->id || $this->jePredkem($newParentId)) {
                throw new \InvalidArgumentException('Cannot move album into its own descendant.');
            }
        }

        DB::transaction(function () use ($newParentId) {
            /*
             * Podstrom se bere z `parent_id` po patrech a přestavuje se od
             * kořene dolů.
             *
             * Dřív se potomci přestavovali v pořadí primárního klíče a každý
             * kopíroval řádky svého rodiče. Starší album přesunuté pod novější
             * tak kopírovalo rodiče, který ještě přestavěný nebyl: chyběl mu
             * předek, cesta v názvu vyšla špatně a pojistka proti vložení do
             * vlastního podalba pak pustila smyčku. `static::find()` navíc
             * vynechal smazaná podalba — ta o své předky přišla navždy
             * a po obnovení visela bez cesty. Dotaz přímo do tabulky smazaná
             * alba nevynechá.
             */
            $patra = $this->podstromPoPatrech();
            $podstrom = array_merge(...$patra);

            DB::table('album_closure')
                ->whereIn('descendant_id', $podstrom)
                ->where('ancestor_id', '!=', DB::raw('descendant_id'))
                ->delete();

            $this->parent_id = $newParentId;
            $this->save();

            foreach ($patra as $patro) {
                $rodice = DB::table('albums')->whereIn('id', $patro)->pluck('parent_id', 'id');

                foreach ($patro as $id) {
                    $rodic = $rodice[$id] ?? null;
                    static::vlozRadkyUzaveru((int) $id, $rodic !== null ? (int) $rodic : null);
                }
            }

            $this->rebuildPaths();
        });
    }

    /**
     * Id podstromu (včetně tohoto alba) po patrech, od tohoto alba dolů.
     *
     * Jde se po `parent_id`, ne po uzávěru — ten může být z dřívějška
     * neúplný. Navštívená alba se hlídají, aby stará smyčka v datech
     * neskončila nekonečným cyklem.
     *
     * @return list<list<int>>
     */
    private function podstromPoPatrech(): array
    {
        $navstivene = [$this->id => true];
        $patra = [[$this->id]];
        $patro = [$this->id];

        while ($patro !== []) {
            $deti = DB::table('albums')
                ->whereIn('parent_id', $patro)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => isset($navstivene[$id]))
                ->values()
                ->all();

            foreach ($deti as $id) {
                $navstivene[$id] = true;
            }

            if ($deti !== []) {
                $patra[] = $deti;
            }

            $patro = $deti;
        }

        return $patra;
    }

    /** Leží tohle album nad albem `$id`? Podle `parent_id`, se smazanými alby. */
    private function jePredkem(int $id): bool
    {
        $navstivene = [];

        while ($id !== 0 && ! isset($navstivene[$id])) {
            if ($id === $this->id) {
                return true;
            }

            $navstivene[$id] = true;
            $id = (int) DB::table('albums')->where('id', $id)->value('parent_id');
        }

        return false;
    }

    /** Řádky uzávěru jednoho alba: sebe sama a předky zkopírované od rodiče. */
    private static function vlozRadkyUzaveru(int $id, ?int $rodic): void
    {
        DB::table('album_closure')->insertOrIgnore([
            'ancestor_id' => $id,
            'descendant_id' => $id,
            'depth' => 0,
        ]);

        if ($rodic === null) {
            return;
        }

        $radky = DB::table('album_closure')
            ->where('descendant_id', $rodic)
            ->get()
            ->map(fn ($row) => [
                'ancestor_id' => $row->ancestor_id,
                'descendant_id' => $id,
                'depth' => $row->depth + 1,
            ])
            ->all();

        if ($radky !== []) {
            DB::table('album_closure')->insertOrIgnore($radky);
        }
    }

    /**
     * @param  array<int, true>  $navstivene  alba, která už touhle přestavbou prošla
     */
    public function rebuildPaths(array &$navstivene = []): void
    {
        // Obrana proti smyčce v `parent_id` (z dřívějšího rozbitého přesunu):
        // bez ní se přestavba zanořovala donekonečna a skončila 500/OOM.
        if (isset($navstivene[$this->id])) {
            return;
        }
        $navstivene[$this->id] = true;

        $ancestors = $this->ancestors()->orderByPivot('depth', 'desc')->get();
        $pathIds = $ancestors->pluck('id')->concat([$this->id])->implode('/');
        $pathNames = $ancestors->pluck('title')->concat([$this->title])->implode(' / ');

        $this->update([
            'depth' => $ancestors->count(),
            'materialized_path' => $pathIds,
            'full_display_path' => $pathNames,
        ]);

        // I smazaná podalba: po obnovení se jinak ukazují se starou cestou.
        foreach ($this->children()->withTrashed()->get() as $child) {
            $child->rebuildPaths($navstivene);
        }
    }

    public function getBreadcrumbAttribute(): array
    {
        return $this->ancestors()
            ->orderByPivot('depth', 'desc')
            ->get(['id', 'uuid', 'title', 'slug'])
            ->push($this->only(['id', 'uuid', 'title', 'slug']))
            ->toArray();
    }
}
