<?php

namespace LPC\Mail;

/**
 * Which mail servers bureau may connect to (docs/MAIL.md §7.1, "Which servers"). Server settings come from a
 * form any staff login can fill, so without this the web server could be made to open sockets anywhere: other
 * hosts, 127.0.0.1 and the shared cPanel box's own services (a port probe), or send a mailbox password in clear.
 *
 *   1. Only allowed hosts: the ones in Config 'servers' (mail.lpc.cm), plus Config 'allowed_hosts'
 *      (env MAIL_CLIENT_ALLOWED_HOSTS, comma separated: an owner's decision, in .env).
 *   2. Whatever the list says: no IP literal, and no host that resolves to a loopback, private, link-local or
 *      reserved address. Config 'allow_private_hosts' (env MAIL_CLIENT_ALLOW_PRIVATE_HOSTS) lifts this for local
 *      tests only; it is off on live.
 *   3. Encryption 'none' only when Config 'allow_plain' (env MAIL_CLIENT_ALLOW_PLAIN) is on: off on live.
 *   (Ports are limited to mail ports by MailConnector::verify.)
 *
 * MailConnector checks every setting a person submits; MailServer checks again before any real connection.
 * Problems are MailError on imap_host / smtp_host, in plain French. Tests replace DNS with HostPolicy::$resolver.
 */
final class HostPolicy
{
    /** @var (callable(string): list<string>)|null tests: host => its IP addresses */
    public static $resolver = null;

    /** @throws MailError */
    public static function check(array $settings): void
    {
        foreach (['imap', 'smtp'] as $kind) {
            $field = $kind . '_host';
            $host = strtolower(trim((string) ($settings[$field] ?? '')));
            $label = $kind === 'imap' ? 'entrant' : 'sortant';

            if (($settings[$kind . '_encryption'] ?? 'ssl') === 'none' && !Config::get('allow_plain', false)) {
                throw MailError::field($field, "Le serveur {$label} doit être sécurisé : choisissez SSL/TLS (ou STARTTLS). Une connexion sans chiffrement ferait passer le mot de passe en clair.");
            }
            if ($host === '') {
                throw MailError::field($field, "Saisissez le serveur {$label}, par exemple mail.lpc.cm.");
            }

            $allowPrivate = (bool) Config::get('allow_private_hosts', false);
            if (filter_var($host, FILTER_VALIDATE_IP) !== false && !$allowPrivate) {
                throw MailError::field($field, "Saisissez le nom du serveur {$label} (par exemple mail.lpc.cm), pas une adresse IP.");
            }
            if (!in_array($host, self::allowedHosts(), true)) {
                throw MailError::field($field, "Bureau ne se connecte qu’aux serveurs de messagerie autorisés (" . implode(', ', self::allowedHosts())
                    . "). Pour une adresse @lpc.cm, gardez mail.lpc.cm ; pour un autre serveur, demandez à l’administrateur.");
            }
            if ($allowPrivate) {
                continue;
            }
            $ips = self::resolve($host);
            if (!$ips) {
                throw MailError::field($field, "{$host} est introuvable. Vérifiez le nom du serveur {$label}.");
            }
            foreach ($ips as $ip) {
                if (!self::isPublic($ip)) {
                    error_log("Mail: {$host} resolves to a non-public address; refused.");
                    throw MailError::field($field, "{$host} mène à une adresse interne : bureau ne s’y connecte pas. Prévenez l’administrateur.");
                }
            }
        }
    }

    /** @return list<string> lower case */
    public static function allowedHosts(): array
    {
        $hosts = [];
        foreach (Config::get('servers', []) as $s) {
            $hosts[] = strtolower((string) ($s['imap_host'] ?? ''));
            $hosts[] = strtolower((string) ($s['smtp_host'] ?? ''));
        }
        foreach ((array) Config::get('allowed_hosts', []) as $h) {
            $hosts[] = strtolower(trim((string) $h));
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /** A public (global) address: not loopback, private, link-local, shared or reserved */
    public static function isPublic(string $ip): bool
    {
        $flags = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }

    /** @return list<string> every IPv4 and IPv6 address of a host (the system resolver, so /etc/hosts counts too) */
    public static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }
        $ips = @gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
