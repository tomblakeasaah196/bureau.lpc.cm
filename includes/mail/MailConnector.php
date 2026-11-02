<?php

namespace LPC\Mail;

use LPC\Mail\Imap\ImapException;
use Throwable;

/**
 * Connecting mailboxes (docs/MAIL.md §7.1): every connect, reconnect and password change signs in to both IMAP
 * and SMTP first, so nothing is saved that doesn't work. Problems come back as MailError on the field to fix
 * (email, password, imap_host, smtp_host, display_name), in plain French. Everything goes to audit_logs
 * (never the password).
 */
final class MailConnector
{
    public static function make(): self
    {
        return new self();
    }

    /** Connect the person's own mailbox (one per person) @return array the account row */
    public function connectPersonal(int $userId, string $email, string $password, array $settings, ?string $displayName = null): array
    {
        if (!MailAccess::canUse($userId)) {
            throw MailError::denied("Votre compte ne peut pas utiliser Courrier.");
        }
        $email = self::cleanEmail($email);
        $existing = Store::accounts("kind = 'personal' AND owner_user_id = ?", [$userId])[0] ?? null;
        if ($existing && strcasecmp($existing['email'], $email) !== 0) {
            throw MailError::field('email', 'Vous avez déjà une boîte mail connectée. Retirez-la d’abord pour en connecter une autre.');
        }
        $this->ensureFree($email, $existing['id'] ?? null);
        $this->requirePassword($password);

        $settings = Autodiscover::normalise($settings);
        $this->verify($settings, $email, $password);

        $row = [
            'email' => $email,
            'display_name' => self::cleanName($displayName ?: People::name($userId)),
            'username' => $email,
            'secret' => MailCredentials::encrypt($password),
            'status' => 'active',
            'last_error' => null,
            'updated_at' => Clock::sql(),
        ] + $settings;

        if ($existing) {
            Db::update('mail_accounts', $row, $existing['id']);
            $id = $existing['id'];
        } else {
            $id = Db::insert('mail_accounts', $row + ['kind' => 'personal', 'owner_user_id' => $userId, 'created_at' => Clock::sql()]);
        }
        self::audit($userId, $existing ? 'UPDATE' : 'INSERT', $id, "Boîte mail personnelle {$email} " . ($existing ? 'reconnectée' : 'connectée') . ' dans Courrier.');
        MailAccess::flush();

        return Store::account($id);
    }

    /** Add a shared mailbox (admins); the person adding it becomes its first manager */
    public function addShared(int $userId, string $email, string $password, array $settings, string $displayName): array
    {
        if (!MailAccess::canAddShared($userId)) {
            throw MailError::denied('Seuls les administrateurs ajoutent des boîtes mail partagées.');
        }
        $email = self::cleanEmail($email);
        $this->ensureFree($email, null);
        $name = self::cleanName($displayName);
        if ($name === '') {
            throw MailError::field('display_name', 'Saisissez le nom que les destinataires verront, par exemple « LPC Service client ».');
        }
        $this->requirePassword($password);
        $settings = Autodiscover::normalise($settings);
        $this->verify($settings, $email, $password);

        $id = Db::tx(function () use ($userId, $email, $password, $settings, $name) {
            $now = Clock::sql();
            $id = Db::insert('mail_accounts', [
                'kind' => 'shared',
                'email' => $email,
                'display_name' => $name,
                'owner_user_id' => $userId,
                'username' => $email,
                'secret' => MailCredentials::encrypt($password),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ] + $settings);
            Db::insert('mail_account_members', ['account_id' => $id, 'user_id' => $userId, 'level' => 'manage', 'added_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        });
        self::audit($userId, 'INSERT', $id, "Boîte mail partagée {$email} ajoutée dans Courrier.");
        MailAccess::flush();

        return Store::account($id);
    }

    /** New password (and optionally server settings): needs manage level (or the person's own mailbox) */
    public function updatePassword(int $userId, int $accountId, string $password, ?array $settings = null): array
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($userId, $account, 'manage');
        $this->requirePassword($password);
        $settings = $settings ? Autodiscover::normalise($settings) : MailServer::settingsOf($account);
        $this->verify($settings, $account['username'], $password);

        Db::update('mail_accounts', ['secret' => MailCredentials::encrypt($password), 'status' => 'active', 'last_error' => null, 'updated_at' => Clock::sql()] + $settings, $accountId);
        self::audit($userId, 'UPDATE', $accountId, "Mot de passe de {$account['email']} mis à jour dans Courrier.");

        return Store::account($accountId);
    }

    /** Rename a mailbox (the name people see in the From line) */
    public function rename(int $userId, int $accountId, string $displayName): array
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($userId, $account, 'manage');
        $name = self::cleanName($displayName);
        if ($name === '') {
            throw MailError::field('display_name', 'Saisissez le nom que les destinataires verront.');
        }
        Db::update('mail_accounts', ['display_name' => $name, 'updated_at' => Clock::sql()], $accountId);
        self::audit($userId, 'UPDATE', $accountId, "Boîte mail {$account['email']} renommée de « {$account['display_name']} » en « {$name} ».");

        return Store::account($accountId);
    }

    /**
     * Take a mailbox out of bureau: its copy here, its people, its conversations' notes and assignments go; the
     * mail on the server is not touched. A personal mailbox: its person; a shared one: a manager.
     */
    public function remove(int $userId, int $accountId): void
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($userId, $account, 'manage');
        self::audit($userId, 'DELETE', $accountId, Store::isShared($account)
            ? "Boîte mail partagée {$account['email']} retirée de Courrier (le courrier reste sur le serveur)."
            : "Boîte mail personnelle {$account['email']} retirée de Courrier.");
        Db::exec('DELETE FROM mail_accounts WHERE id = ?', [$accountId]); // everything below it cascades
        MailAccess::flush();
    }

    /** Sign in to IMAP and SMTP with these settings, or throw a MailError that says what to fix */
    public function verify(array $settings, string $username, string $password): void
    {
        if ($settings['imap_host'] === '' || $settings['smtp_host'] === '') {
            throw MailError::field('imap_host', 'Saisissez les serveurs entrant et sortant (par exemple mail.lpc.cm).');
        }
        $imapPorts = Config::get('imap_ports', [993, 143]);
        $smtpPorts = Config::get('smtp_ports', [465, 587, 25, 2525]);
        if (!in_array($settings['imap_port'], $imapPorts, true)) {
            throw MailError::field('imap_host', 'Le port entrant doit être ' . implode(' ou ', $imapPorts) . '.');
        }
        if (!in_array($settings['smtp_port'], $smtpPorts, true)) {
            throw MailError::field('smtp_host', 'Le port sortant doit être ' . implode(', ', $smtpPorts) . '.');
        }
        foreach (['imap_host', 'smtp_host'] as $key) {
            if (!preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $settings[$key]) && !self::isLocalTestHost($settings[$key])) {
                throw MailError::field($key, 'Saisissez un nom de serveur comme mail.lpc.cm.');
            }
        }

        try {
            $session = MailServer::make()->connect($settings, $username, $password);
            $session->logout();
        } catch (ImapException $e) {
            throw match ($e->reason) {
                'auth' => MailError::field('password', 'Le serveur de messagerie a refusé cette adresse ou ce mot de passe. Vérifiez le mot de passe de la boîte mail (celui de cPanel, pas votre mot de passe bureau).'),
                'tls' => MailError::field('imap_host', "La connexion sécurisée à {$settings['imap_host']} a échoué. Vérifiez le serveur entrant et sa sécurité."),
                'unreachable', 'timeout' => MailError::field('imap_host', "{$settings['imap_host']} ne répond pas sur le port {$settings['imap_port']}. Vérifiez le serveur entrant et le port."),
                default => MailError::field('imap_host', 'Le serveur entrant a répondu de façon inattendue. Vérifiez les paramètres du serveur entrant.'),
            };
        }

        try {
            MailServer::make()->checkSmtp($settings, $username, $password);
        } catch (MailSendException $e) {
            throw match ($e->reason) {
                'auth' => MailError::field('password', 'La réception fonctionne, mais le serveur sortant a refusé le mot de passe. Vérifiez les paramètres du serveur sortant.'),
                'unreachable' => MailError::field('smtp_host', "{$settings['smtp_host']} ne répond pas sur le port {$settings['smtp_port']}. Vérifiez le serveur sortant et le port."),
                default => MailError::field('smtp_host', 'Le serveur sortant ne peut pas être utilisé : ' . $e->getMessage()),
            };
        }
    }

    public static function cleanEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw MailError::field('email', 'Saisissez une adresse complète, par exemple prenom.nom@lpc.cm.');
        }

        return $email;
    }

    public static function cleanName(string $name): string
    {
        return mb_substr(trim(preg_replace('/[\r\n"\\\\<>]+/', ' ', $name)), 0, 120);
    }

    public static function audit(int $userId, string $action, int $accountId, string $note): void
    {
        try {
            Db::exec(
                'INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value) VALUES (?, ?, ?, ?, ?)',
                [$userId, $action, 'mail_accounts', $accountId, $note],
            );
        } catch (Throwable $e) {
            error_log('Mail audit: ' . $e->getMessage());
        }
    }

    private function ensureFree(string $email, ?int $exceptId): void
    {
        $taken = Store::accountByEmail($email);
        if ($taken && $taken['id'] !== $exceptId) {
            throw MailError::field('email', Store::isShared($taken)
                ? "{$email} est déjà dans Courrier comme boîte partagée. Demandez l’accès à l’un de ses gestionnaires."
                : "{$email} est déjà connectée par quelqu’un dans bureau.");
        }
    }

    private function requirePassword(string $password): void
    {
        if ($password === '') {
            throw MailError::field('password', 'Saisissez le mot de passe de la boîte mail.');
        }
    }

    /** 127.0.0.1 / localhost: only when certificate checks are off (a local test server, never live) */
    private static function isLocalTestHost(string $host): bool
    {
        return !Config::get('verify_tls', true) && in_array($host, ['127.0.0.1', 'localhost'], true);
    }
}
