# Discord — soupis toho, co lze a nelze

Ověřeno proti živému API (`discord.com/api/v10`), ne podle paměti.

## Hotové a funkční

| Schopnost | Jak | Rozsah oprávnění |
|---|---|---|
| Propojení účtu | OAuth2 authorization code | `identify` |
| Jméno, přezdívka, avatar | `GET /users/@me` | `identify` |
| E-mail účtu | `GET /users/@me` | `email` |
| Seznam serverů uživatele | `GET /users/@me/guilds` | `guilds` |
| Propojené služby profilu (Spotify, Steam, GitHub, Xbox…) | `GET /users/@me/connections` | `connections` |
| Odesílání upozornění do kanálu | Incoming webhook | žádné — webhook si vytvoří uživatel |
| Obnovení vypršeného přístupu | refresh token | — |
| Odpojení | smazání propojení u nás + odebrání aplikace v Discordu | — |

Všechna požadovaná oprávnění jsou **jen pro čtení**. Aplikace nežádá nic, čím by mohla
psát jménem uživatele nebo měnit jeho účet.

## Co Discord přes HTTP nevydá

**Živý stav uživatele** — tedy online/nepřítomen/nerušit, „hraje…", „poslouchá Spotify".

Tohle není otázka oprávnění ani našeho úsilí. Presence se v Discordu doručuje **výhradně
událostmi `PRESENCE_UPDATE` na Gateway** (`wss://gateway.discord.gg`), a to pouze botovi,
který splňuje **všechny tři** podmínky zároveň:

1. je členem serveru, kde je i sledovaný uživatel,
2. má zapnutý privilegovaný intent `GUILD_PRESENCES`,
3. drží trvale otevřené websocket spojení a udržuje ho heartbeatem.

Žádný REST endpoint presence nevrací. `GET /users/{id}` vrátí profil, ne stav.

### Co by to znamenalo doplnit

Samostatný trvale běžící proces (démon) vedle PHP-FPM, pod dohledem supervisoru, který
drží spojení, zpracovává heartbeat a reconnect, a stav ukládá do databáze. K tomu bot
pozvaný na server, kde jsou oba partneři.

Aplikace dnes žádný dlouho běžící proces nemá — a jeden takový by znamenal další věc
k nasazování, hlídání a restartování. Proto to není součástí integrace.

### Co je místo toho

Aplikace sleduje **vlastní** přítomnost: kdo je právě v konverzaci a kdo píše
(`chat_presence`). To je ta informace, kterou produkt od „sledování stavu" reálně chtěl,
a je přesná, protože pochází z našeho systému.

Nejbližší, co Discord po HTTP nabídne, jsou **propojené služby profilu** — tedy že někdo
má napojené Spotify, ne co zrovna hraje.

## Vědomě nepoužité

| Schopnost | Proč ne |
|---|---|
| Bot s `GUILD_MEMBERS` | vyžaduje démona, viz výše |
| Posílání DM uživateli | vyžaduje bota a sdílený server |
| `guilds.join` | oprávnění přidat uživatele na server je mimo účel této aplikace |
| Rich Presence (RPC) | běží jen proti lokálnímu klientovi na stejném počítači, ne ze serveru |
| `applications.commands` | slash příkazy dávají smysl až s botem |

## Nastavení

1. V [Discord Developer Portal](https://discord.com/developers/applications) založit aplikaci.
2. Do OAuth2 → Redirects přidat `https://<doména>/discord/zpet`.
3. Client ID a Client Secret vložit v administraci jako poskytovatele `discord`.
4. Uživatel se propojí v Nastavení → Propojení služeb.
5. Pro upozornění: v Discordu Nastavení kanálu → Integrace → Webhooky → zkopírovat adresu
   a vložit ji u propojení. Zkušební zpráva se odešle hned.

## Bezpečnostní poznámky

- Tokeny jsou šifrované klíčem aplikace a **žádný endpoint je nevrací**.
- OAuth `state` je jednorázový, uložený v session a porovnávaný `hash_equals`.
- Webhook se přijme jen na doméně Discordu a jen přes https.
- Odchozí zprávy mají `allowed_mentions: { parse: [] }`, takže upozornění nikdy nevyvolá
  hromadný ping.
