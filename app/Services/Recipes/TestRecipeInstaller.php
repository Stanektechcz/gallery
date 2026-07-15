<?php

namespace App\Services\Recipes;

use App\Models\GallerySpace;
use App\Models\Recipe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TestRecipeInstaller
{
    public const SOURCE = 'Vlastní testovací recept';

    public const RECIPE_UUIDS = [
        '7d3fca1a-2b8e-4d3f-9a6c-1e5b7c9d2f41',
        'c2a58f4e-6d71-4b93-a8cf-3f0e9d7b5a62',
    ];

    public function install(GallerySpace $space): Collection
    {
        $ownerId = (int) $space->owner_id;
        abort_unless($ownerId > 0, 422, 'Partnerský prostor nemá vlastníka pro založení receptů.');

        return DB::transaction(function () use ($space, $ownerId) {
            return collect($this->recipes())->map(function (array $definition) use ($space, $ownerId) {
                $recipe = Recipe::withTrashed()->where('uuid', $definition['uuid'])->first() ?? new Recipe(['uuid' => $definition['uuid']]);
                if ($recipe->trashed()) {
                    $recipe->restore();
                }
                $recipe->fill($definition['recipe'] + [
                    'gallery_space_id' => $space->id,
                    'created_by' => $ownerId,
                    'updated_by' => $ownerId,
                    'source_name' => self::SOURCE,
                ])->save();

                $recipe->ingredients()->delete();
                foreach ($definition['ingredients'] as $index => $ingredient) {
                    $recipe->ingredients()->create($ingredient + ['sort_order' => $index]);
                }
                $recipe->steps()->delete();
                foreach ($definition['steps'] as $index => $step) {
                    $recipe->steps()->create($step + ['sort_order' => $index]);
                }

                return $recipe->fresh(['ingredients', 'steps']);
            });
        });
    }

    private function recipes(): array
    {
        return [
            $this->honeyMustardChicken(),
            $this->marryMeChicken(),
        ];
    }

    private function honeyMustardChicken(): array
    {
        return [
            'uuid' => self::RECIPE_UUIDS[0],
            'recipe' => [
                'title' => 'Zapečená kuřecí prsa v medovo-hořčičné omáčce se třemi sýry a pečenými bramborami',
                'summary' => 'Šťavnaté kořeněné kuře na cibuli, sladko-hořčičná smetanová omáčka, křupavá vrstva goudy, cheddaru a parmazánu a brambory pečené nejprve v páře.',
                'description' => 'Kompletní testovací recept pro společné vaření. Kuřecí plátky se krátce zatáhnou, dopečou zakryté v medovo-hořčičné omáčce a dokončí třemi sýry. Brambory se připravují bez předvaření: změknou pod alobalem a následně se opečou odkryté.',
                'category' => 'main_course',
                'cuisine' => 'Partnerská domácí kuchyně',
                'difficulty' => 'hard',
                'status' => 'published',
                'base_servings' => 6,
                'prep_minutes' => 45,
                'cook_minutes' => 75,
                'rest_minutes' => 10,
                'currency' => 'CZK',
                'dietary_tags' => [],
                'occasion_tags' => ['společná večeře', 'víkendové vaření', 'návštěva'],
                'equipment' => ['trouba', 'velká pánev', 'zapékací mísa s víkem nebo alobalem', 'plech', 'struhadlo', 'metlička', 'teploměr na maso'],
                'tips' => 'Med, hořčici a čerstvý česnek nedávej do marinády určené k prudkému opékání — mohly by se pálit. Pánev nepřeplňuj a maso pouze zatáhni. Parmazán přidej až nakonec. Nejspolehlivější kontrola kuřete je 74 °C ve středu nejsilnějšího kusu.',
                'storage_notes' => 'Kuře, omáčku a brambory uchovávej po vychladnutí zakryté v lednici. Brambory je vhodné uložit odděleně, aby nezvlhly v omáčce.',
                'reheating_notes' => 'Kuře s omáčkou ohřívej zakryté a pozvolna, případně přidej lžíci smetany nebo vody. Brambory ohřej zvlášť v troubě nebo horkovzdušné fritéze.',
                'is_favorite' => false,
            ],
            'ingredients' => [
                $this->ingredient('Kuře · maso', 'Kuřecí prsa', 1000, 'g', preparation: 'očistit a podélně rozříznout na plátky silné 1,5–2 cm'),

                $this->ingredient('Kuře · kořenicí marináda', 'Řepkový nebo slunečnicový olej', 2, 'lžíce', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Sůl', 1.25, 'lžičky', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Sladká paprika', 1.5, 'lžičky', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Uzená paprika', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Čerstvě mletý černý pepř', 1, 'lžička', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Sušený tymián', 0.75, 'lžičky', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Sušený česnek', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Sušená cibule', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Kuře · kořenicí marináda', 'Chilli nebo kajenský pepř', null, null, note: 'špetka', optional: true, pantry: true),

                $this->ingredient('Medovo-hořčičná omáčka', 'Plnotučná hořčice', 80, 'g', note: 'přibližně 4 vrchovaté lžíce'),
                $this->ingredient('Medovo-hořčičná omáčka', 'Tekutý med', 65, 'g', note: 'přibližně 3 lžíce'),
                $this->ingredient('Medovo-hořčičná omáčka', 'Smetana ke šlehání 30–33 %', 200, 'ml'),
                $this->ingredient('Medovo-hořčičná omáčka', 'Sójová omáčka', 2, 'lžíce', pantry: true),
                $this->ingredient('Medovo-hořčičná omáčka', 'Worcesterská omáčka', 1, 'lžíce', pantry: true),
                $this->ingredient('Medovo-hořčičná omáčka', 'Česnek', 4, 'stroužky', preparation: 'prolisovat nebo jemně nastrouhat'),
                $this->ingredient('Medovo-hořčičná omáčka', 'Citronová šťáva', 1, 'lžíce', substitutes: 'Jablečný ocet.'),
                $this->ingredient('Medovo-hořčičná omáčka', 'Sladká paprika', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Medovo-hořčičná omáčka', 'Černý pepř', 0.25, 'lžičky', pantry: true),

                $this->ingredient('Cibulový základ', 'Velká cibule', 1, 'ks', preparation: 'nakrájet na tenké půlměsíce'),
                $this->ingredient('Cibulový základ', 'Máslo na vymazání', 17.5, 'g', note: '15–20 g'),
                $this->ingredient('Cibulový základ', 'Olej', 1, 'lžíce', note: 'jen pokud cibule působí suchá', optional: true, pantry: true),
                $this->ingredient('Cibulový základ', 'Sůl', null, null, note: 'malá špetka', scalable: false, pantry: true),

                $this->ingredient('Sýrová vrstva', 'Gouda', 150, 'g', preparation: 'nahrubo nastrouhat'),
                $this->ingredient('Sýrová vrstva', 'Cheddar', 110, 'g', note: '100–120 g', preparation: 'nahrubo nastrouhat'),
                $this->ingredient('Sýrová vrstva', 'Parmazán', 27.5, 'g', note: '25–30 g', preparation: 'najemno nastrouhat'),

                $this->ingredient('Pečené brambory', 'Brambory vhodné k pečení', 1000, 'g', preparation: 'neloupat, nakrájet na kolečka 5–7 mm'),
                $this->ingredient('Pečené brambory', 'Olej', 2.5, 'lžíce', pantry: true),
                $this->ingredient('Pečené brambory', 'Sůl', 1, 'lžička', pantry: true),
                $this->ingredient('Pečené brambory', 'Sladká paprika', 1, 'lžička', pantry: true),
                $this->ingredient('Pečené brambory', 'Sušený česnek', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Pečené brambory', 'Černý pepř', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Pečené brambory', 'Sušený tymián nebo rozmarýn', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Pečené brambory', 'Horká voda', 50, 'ml', pantry: true),
                $this->ingredient('Pečené brambory', 'Máslo na dokončení', 12.5, 'g', note: '10–15 g', optional: true),
            ],
            'steps' => [
                $this->step('Připrav kuřecí plátky', 'Prsa očisti od blan, podélně je rozřízni na přibližně stejně silné plátky 1,5–2 cm a silnější místa lehce naklepej přes fólii. Maso důkladně osuší papírovými utěrkami.', equipment: 'nůž, prkénko, potravinová fólie, palička'),
                $this->step('Nalož maso', 'Smíchej olej, sůl, obě papriky, pepř, tymián, sušený česnek, sušenou cibuli a případně chilli. Pastu vmasíruj do masa ze všech stran, zakryj a nech odležet v lednici.', timer: 1200, tip: 'Do marinády nepřidávej med, hořčici ani čerstvý česnek; při opékání by se mohly pálit.'),
                $this->step('Předpeč cibuli', 'Troubu předehřej na 190 °C horní/dolní ohřev (175–180 °C horkovzduch). Mísu vymaž máslem, rozprostři cibuli, lehce osol a podle potřeby zakápni olejem. Peč, dokud cibule nezměkne a nezprůsvitní.', timer: 600, temperature: 190, equipment: 'trouba, zapékací mísa'),
                $this->step('Umíchej omáčku', 'Metličkou spoj hořčici, med, smetanu, sójovou a worcesterskou omáčku, česnek, citronovou šťávu nebo ocet, sladkou papriku a pepř. Ochutnej ještě před kontaktem se syrovým masem.', equipment: 'mísa, metlička', tip: 'Další sůl obvykle není potřeba; obsahuje ji marináda, sójová omáčka i sýry.'),
                $this->step('Připrav sýry', 'Goudu a cheddar nastrouhej nahrubo a smíchej. Parmazán nastrouhej najemno a ponech zvlášť pro poslední část pečení.', equipment: 'struhadlo'),
                $this->step('Zatáhni kuře', 'Pánev rozehřej na vyšší střední výkon. Maso opékej po menších dávkách přibližně 1,5–2 minuty z první a 1 minutu z druhé strany. Uvnitř ještě nemá být hotové.', timer: 180, equipment: 'velká pánev', tip: 'Pánev nepřeplňuj. Další olej přidej jen tehdy, když marináda a povrch pánve nestačí.'),
                $this->step('Sestav mísu a uvolni výpek', 'Plátky ulož na cibuli převážně v jedné vrstvě. Do pánve nalij asi 100 ml omáčky, 30–45 sekund stěrkou uvolňuj přípečky a obsah nalij na maso. Přidej zbytek omáčky tak, aby pokryla každý kus.', timer: 45, equipment: 'pánev, stěrka, zapékací mísa', tip: 'Omáčku v pánvi nenechávej prudce vařit.'),
                $this->step('Peč zakryté', 'Mísu zakryj víkem nebo alobalem a vlož do trouby. Zakrytí pomůže masu zůstat šťavnaté a rovnoměrně prohřeje omáčku.', timer: 720, temperature: 190, equipment: 'trouba, víko nebo alobal'),
                $this->step('Přidej goudu a cheddar', 'Mísu odkryj, maso rovnoměrně posyp goudou a cheddarem a pokračuj odkryté, aby se sýry rozpustily a začaly zlátnout.', timer: 480, temperature: 190, equipment: 'trouba'),
                $this->step('Dokonči parmazánem', 'Povrch posyp jemně strouhaným parmazánem a vrať do trouby, dokud se sýry nespojí a lehce nezezlátnou.', timer: 180, temperature: 190, equipment: 'trouba', tip: 'Pro výraznější krustu zapni gril nanejvýš na 30–60 sekund a mísu nepřetržitě sleduj.'),
                $this->step('Zkontroluj kuře a nech odpočinout', 'Změř teplotu uprostřed nejsilnějšího kusu; cílem je přibližně 74 °C. Pokud není hotový, přidávej 2–3 minuty. Potom nech mísu stát, aby omáčka zhoustla a maso si udrželo šťávu.', timer: 600, tip: 'Bez teploměru rozřízni nejsilnější plátek: střed musí být bílý, bez růžové barvy.'),
                $this->step('Připrav brambory', 'Po vytažení kuřete zvyš troubu na 220 °C (205–210 °C horkovzduch). Brambory důkladně omyj, nakrájej na kolečka 5–7 mm, krátce propláchni a velmi dobře osuší.', temperature: 220, equipment: 'trouba, nůž, utěrka'),
                $this->step('Ochuť brambory', 'Smíchej olej, sůl, papriku, sušený česnek, pepř a tymián nebo rozmarýn. Brambory ve směsi důkladně obal a rozlož do co nejrovnoměrnější vrstvy.', equipment: 'mísa, plech nebo široká zapékací nádoba'),
                $this->step('Změkči brambory párou', 'Po straně nádoby přilij 50 ml horké vody, aby se nesmylo koření. Nádobu těsně zakryj alobalem a peč. Pára brambory změkčí bez předvařování.', timer: 900, temperature: 220, equipment: 'trouba, alobal', tip: 'Při sundávání alobalu dej pozor na prudce unikající páru.'),
                $this->step('Opeč brambory', 'Alobal sundej, brambory otoč a rozlož. Peč odkryté 20–25 minut a přibližně v polovině je znovu obrať. Hotové jsou měkké uvnitř a zlatavé na okrajích.', timer: 1500, temperature: 220, equipment: 'trouba', tip: 'Pro výraznější opečení můžeš poslední 3–5 minut zvýšit teplotu na 230 °C nebo krátce použít gril.'),
                $this->step('Dokonči a servíruj', 'Volitelně promíchej horké brambory s máslem. Na talíř dej brambory a kuřecí plátek, přelij medovo-hořčičnou omáčkou s cibulí a doplň svěžím salátem nebo nakládanou zeleninou.'),
            ],
        ];
    }

    private function marryMeChicken(): array
    {
        return [
            'uuid' => self::RECIPE_UUIDS[1],
            'recipe' => [
                'title' => 'Marry Me Chicken',
                'summary' => 'Naše vylepšená verze krémového kuřete se sušenými rajčaty, česnekem, parmazánem a redukovaným vývarem, podávaná se sypkou rýží.',
                'description' => 'Kuře se připravuje v tenkých plátcích, aby se rychle opeklo a zůstalo šťavnaté. Chuť omáčky stojí na přípečcích, krátce opečených sušených rajčatech, protlaku, zredukovaném vývaru a postupně vmíchaném parmazánu. Svěžest se přidává až po vypnutí.',
                'category' => 'main_course',
                'cuisine' => 'Italsko-americká',
                'difficulty' => 'medium',
                'status' => 'published',
                'base_servings' => 4,
                'prep_minutes' => 25,
                'cook_minutes' => 30,
                'rest_minutes' => 3,
                'currency' => 'CZK',
                'dietary_tags' => [],
                'occasion_tags' => ['společná večeře', 'rychlé vaření', 'comfort food'],
                'equipment' => ['velká pánev', 'hrnec s pokličkou', 'metlička nebo vařečka', 'nůž', 'struhadlo'],
                'tips' => 'Maso krájej na tenčí plátky, dobře je osuš a pánev nepřeplňuj. Přípečky ponech v pánvi. Protlak krátce orestuj, česnek nespal a vývar před smetanou zredukuj. Parmazán vmíchávej po částech a svěží složku přidej až na konci.',
                'storage_notes' => 'Kuře s omáčkou a rýži nech vychladnout a ulož odděleně v uzavřených nádobách v lednici.',
                'reheating_notes' => 'Omáčku ohřívej pozvolna bez prudkého varu; podle potřeby ji zřeď trochou vývaru nebo smetany. Rýži ohřívej zakrytou s několika kapkami vody.',
                'is_favorite' => false,
            ],
            'ingredients' => [
                $this->ingredient('Maso', 'Kuřecí prsa', 500, 'g', preparation: 'nakrájet na tenčí plátky'),
                $this->ingredient('Maso', 'Sůl', null, null, note: 'dle chuti', scalable: false, pantry: true),
                $this->ingredient('Maso', 'Čerstvě mletý pepř', null, null, note: 'dle chuti', scalable: false, pantry: true),
                $this->ingredient('Maso', 'Sladká paprika', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Maso', 'Sušené oregano', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Maso', 'Olej ze sušených rajčat', 1, 'lžička', substitutes: 'Olivový olej.'),
                $this->ingredient('Maso', 'Hladká mouka nebo škrob', 1, 'lžíce', note: 'jen lehké poprášení', optional: true, pantry: true),

                $this->ingredient('Omáčka', 'Sušená rajčata v oleji', 80, 'g', note: '70–90 g', preparation: 'nakrájet'),
                $this->ingredient('Omáčka', 'Česnek', 4, 'stroužky', preparation: 'nasekat'),
                $this->ingredient('Omáčka', 'Rajčatový protlak', 1, 'lžička', note: 'menší lžička'),
                $this->ingredient('Omáčka', 'Kuřecí vývar', 200, 'ml'),
                $this->ingredient('Omáčka', 'Smetana 12 %', 175, 'ml', note: '150–200 ml'),
                $this->ingredient('Omáčka', 'Parmazán', 60, 'g', note: '50–70 g', preparation: 'jemně nastrouhat'),
                $this->ingredient('Omáčka', 'Sušené oregano', 0.5, 'lžičky', pantry: true),
                $this->ingredient('Omáčka', 'Chilli', null, null, note: 'špetka', optional: true, pantry: true),
                $this->ingredient('Dokončení', 'Bazalka, petržel nebo pažitka', null, null, note: 'dle dostupnosti', optional: true, scalable: false, preparation: 'nasekat'),
                $this->ingredient('Dokončení', 'Čerstvé nebo cherry rajče', 1, 'ks', note: 'pro svěžest', optional: true, substitutes: 'Velmi malé množství kečupu, nebo lžíce jogurtu či zakysané smetany přidaná až po vypnutí.'),

                $this->ingredient('Rýže', 'Rýže', 200, 'g', note: 'ideálně basmati nebo jasmínová'),
                $this->ingredient('Rýže', 'Voda', 300, 'ml', note: '1,4–1,5 dílu vody na 1 díl rýže', pantry: true),
                $this->ingredient('Rýže', 'Sůl', null, null, note: 'dle chuti', scalable: false, pantry: true),
            ],
            'steps' => [
                $this->step('Připrav a ochuť kuře', 'Prsa nakrájej na tenčí plátky a dobře osuší. Osol, opepři, přidej papriku, oregano a olej ze sušených rajčat. Volitelně maso opravdu lehce popraš moukou nebo škrobem.', timer: 1200, tip: 'Odležení 10–20 minut zlepší chuť, ale při nedostatku času lze pokračovat hned.'),
                $this->step('Začni rýži', 'Rýži několikrát propláchni, dokud voda není téměř čirá. Dej ji do hrnce s vodou a solí, přiveď k varu, stáhni na minimum a vař zakrytou.', timer: 720, equipment: 'hrnec s pokličkou', tip: 'Pro basmati nebo jasmínovou rýži stačí poměr vody přibližně 1 : 1,4; pro ostatní použij 1 : 1,5.'),
                $this->step('Opeč maso', 'Na středně vyšší teplotě rozehřej olej. Kuře opékej přibližně 2–3 minuty z první a 2 minuty z druhé strany. Má získat zlatavou kůrku, ale nemusí být uvnitř hotové. Odlož ho na talíř a zachovej všechnu šťávu.', timer: 300, equipment: 'velká pánev'),
                $this->step('Vytvoř rajčatový základ', 'Do stejné pánve dej sušená rajčata a krátce je opeč. Přidej protlak a restuj 30–45 sekund. Potom přidej česnek pouze na 20–40 sekund, oregano, pepř a případně chilli.', timer: 120, equipment: 'pánev, vařečka', tip: 'Česnek nesmí zhnědnout; spálený by omáčku zhořkl.'),
                $this->step('Zredukuj vývar', 'Přilij kuřecí vývar a seškrábni ze dna všechny přípečky. Nech 3–5 minut mírně probublávat, aby se chuť zkoncentrovala a základ nebyl vodový.', timer: 300, equipment: 'pánev, vařečka'),
                $this->step('Přidej smetanu a parmazán', 'Sniž teplotu a vmíchej smetanu. Parmazán přidávej po částech: každou nech rozpustit, než přidáš další. Omáčku nech pouze jemně probublávat a podle potřeby krátce redukuj.', equipment: 'pánev, metlička', tip: 'Omáčku zahušťuje redukce a parmazán; další mouka obvykle není potřeba.'),
                $this->step('Dodělej kuře v omáčce', 'Vrať maso i šťávu z talíře do pánve. Nech je jemně dojít 4–7 minut podle tloušťky a během toho plátky přelévej omáčkou.', timer: 420, equipment: 'pánev'),
                $this->step('Dolaď svěžest a nech odpočinout', 'Vypni sporák a přidej čerstvé rajče a bylinky. Pokud používáš jogurt nebo zakysanou smetanu, nejprve ji temperuj trochou horké omáčky a až potom vrať do pánve. Nech jídlo krátce odpočinout.', timer: 180, tip: 'Svěží složku přidávej až po vypnutí, aby zůstala lehká a mléčný doplněk se nesrazil.'),
                $this->step('Nech dojít rýži', 'Po 12 minutách vaření rýži vypni a dalších 10 minut neodkrývej. Nakonec ji načechrej vidličkou.', timer: 600, equipment: 'hrnec s pokličkou, vidlička'),
                $this->step('Servíruj', 'Na talíř dej sypkou rýži, kuřecí maso a vše přelij hustou omáčkou se sušenými rajčaty. Dokonči parmazánem, pepřem, bylinkami a případně čerstvým rajčetem.'),
            ],
        ];
    }

    private function ingredient(
        string $section,
        string $name,
        ?float $quantity = null,
        ?string $unit = null,
        ?string $note = null,
        bool $optional = false,
        bool $pantry = false,
        ?bool $scalable = null,
        ?string $preparation = null,
        ?string $substitutes = null,
    ): array {
        return [
            'section' => $section,
            'name' => $name,
            'quantity' => $quantity,
            'unit' => $unit,
            'quantity_note' => $note,
            'is_scalable' => $scalable ?? $quantity !== null,
            'is_optional' => $optional,
            'is_pantry' => $pantry,
            'preparation' => $preparation,
            'substitutes' => $substitutes,
        ];
    }

    private function step(
        string $title,
        string $instruction,
        ?int $timer = null,
        ?float $temperature = null,
        ?string $equipment = null,
        ?string $tip = null,
    ): array {
        return [
            'title' => $title,
            'instruction' => $instruction,
            'timer_seconds' => $timer,
            'temperature' => $temperature,
            'temperature_unit' => 'C',
            'equipment' => $equipment,
            'tip' => $tip,
        ];
    }
}
