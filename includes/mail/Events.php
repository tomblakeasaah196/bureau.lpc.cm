<?php

namespace LPC\Mail;

use Throwable;

/**
 * Hooks between the Mail PRs (docs/MAIL.md §7.4). PR 1 raises:
 *
 *   mail.received        sync stored new Inbox mail that arrived since the last check (not a first copy, not
 *                        mail older than 48 h, not mail the mailbox sent itself)
 *                        ['account_id' => int, 'message_ids' => list<int> oldest first, 'thread_ids' => list<int>]
 *   mail.threads_merged  a reply joined two conversations; messages, notes, activity and outbox rows already
 *                        moved to 'into_id'; the others' presence deleted
 *                        ['from_ids' => list<int>, 'into_id' => int]
 *
 * Listeners are files in includes/mail/Listeners/ (PR 4 and PR 5 add theirs; register nothing anywhere else).
 * Each file returns ['event.name' => callable(array $payload): void, …]. Sync runs every minute from cron, so
 * a listener must be quick and must not throw: emit() catches, logs and moves on.
 */
final class Events
{
    /** @var array<string, list<callable>>|null */
    private static ?array $listeners = null;

    public static function on(string $event, callable $listener): void
    {
        self::load();
        self::$listeners[$event][] = $listener;
    }

    public static function emit(string $event, array $payload): void
    {
        self::load();
        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (Throwable $e) {
                error_log("Mail: a listener of {$event} failed: " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    }

    /** Tests: forget every listener (null) or start from these */
    public static function reset(?array $listeners = null): void
    {
        self::$listeners = $listeners;
    }

    private static function load(): void
    {
        if (self::$listeners !== null) {
            return;
        }
        self::$listeners = [];
        foreach (glob(__DIR__ . '/Listeners/*.php') ?: [] as $file) {
            try {
                $map = require $file;
            } catch (Throwable $e) {
                error_log('Mail: listener file ' . basename($file) . ' failed to load: ' . $e->getMessage());
                continue;
            }
            foreach (is_array($map) ? $map : [] as $event => $listener) {
                if (is_string($event) && is_callable($listener)) {
                    self::$listeners[$event][] = $listener;
                }
            }
        }
    }
}
