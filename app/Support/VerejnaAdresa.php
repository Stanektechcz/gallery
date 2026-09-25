<?php

namespace App\Support;

/**
 * Vede adresa na veřejný internet, nebo dovnitř sítě serveru?
 *
 * Kdekoli server sám otevírá adresu, kterou zadal uživatel (import receptu,
 * vlastní úložiště WebDAV), by jinak šlo poslat požadavek na `127.0.0.1`,
 * na metadata cloudu (`169.254.169.254`) nebo do vnitřní sítě — a z odpovědi
 * vyčíst, co tam běží. Posuzují se všechny adresy, na které se jméno
 * v DNS překládá: stačí jediná neveřejná a hostitel neprojde.
 */
final class VerejnaAdresa
{
    /**
     * Adresy hostitele, když jsou všechny veřejné; jinak `null`.
     *
     * `$prisne` přidává rozsahy, které nejsou globálně směrovatelné (RFC 6890,
     * např. sdílený rozsah poskytovatele `100.64.0.0/10` nebo dokumentační
     * sítě). IPv6 adresu v hranatých závorkách z URL musí volající rozbalit sám.
     *
     * @return non-empty-list<string>|null
     */
    public static function adresy(string $host, bool $prisne = false): ?array
    {
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::jeVerejnaIp($host, $prisne) ? [$host] : null;
        }

        $ips = self::preklad($host);

        foreach ($ips as $ip) {
            if (! self::jeVerejnaIp($ip, $prisne)) {
                return null;
            }
        }

        return $ips === [] ? null : $ips;
    }

    public static function jeVerejnyHostitel(string $host, bool $prisne = false): bool
    {
        return self::adresy($host, $prisne) !== null;
    }

    public static function jeVerejnaIp(string $ip, bool $prisne = false): bool
    {
        $priznaky = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if ($prisne) {
            $priznaky |= FILTER_FLAG_GLOBAL_RANGE;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, $priznaky) !== false;
    }

    /**
     * Záznamy A a AAAA. Nedostupné DNS se bere jako „nic" — neověřená adresa
     * neprojde, místo aby varování PHP shodilo požadavek.
     *
     * @return list<string>
     */
    private static function preklad(string $host): array
    {
        try {
            $zaznamy = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $ips = [];
        foreach ($zaznamy as $zaznam) {
            $ip = $zaznam['ip'] ?? $zaznam['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
