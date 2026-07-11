# Vybraní bezplatní poskytovatelé dat

| Poskytovatel | Účel | Přístup | Administrace |
|---|---|---|---|
| Open-Meteo | Počasí, historické počasí, vzduch a geokódování | Bez klíče pro nekomerční provoz | Aktivní bez klíče, dostupný test připojení. |
| Frankfurter / ECB | Referenční kurzy měn | Bez klíče | Aktivní bez klíče, aplikace používá poskytovatele ECB. |
| Nominatim / OSM | Geokódování a hledání míst | Bez klíče, s povinným User-Agent a férovým limitem | Volitelný kontaktní e-mail v administraci. |
| OpenRouteService | Trasy auto/kolo/pěšky | Bezplatný účet a API klíč | Klíč se šifruje, lze jej ověřit testem. |
| TransportAPI | Část plánovaných a real-time dopravních dat | Free tarif s `app_id` a `app_key` | Klíče se šifrují, lze je otestovat. |

Yahoo Finance není použit jako primární zdroj měn: nemá stabilní oficiální veřejné API pro tento účel. Frankfurter poskytuje zdarma zdrojové kurzy ECB bez nutnosti klíče.

Nastavení všech volitelných klíčů je na `/admin/integrations`. Klíče se po uložení nevracejí do prohlížeče; na serveru jsou šifrované pomocí aplikačního klíče Laravelu.
