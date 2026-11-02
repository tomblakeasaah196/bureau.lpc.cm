<?php

namespace LPC\Mail\Compose;

use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\MailError;
use PDO;

/**
 * Files attached to a message being written (mail_compose_files, migration 320). They live in the database,
 * not under /uploads/ (reachable from the web), in pieces of ComposeConfig 'chunk_bytes', and go with their
 * outbox row (foreign key) or once the message has gone. Callers check the row belongs to the person first.
 */
final class Files
{
    public static function put(string $outboxId, string $key, string $content): void
    {
        $size = max(1, (int) ComposeConfig::get('chunk_bytes', 524288));
        $now = Clock::sql();
        Db::tx(function () use ($outboxId, $key, $content, $size, $now) {
            Db::exec('DELETE FROM mail_compose_files WHERE outbox_id = ? AND file_key = ?', [$outboxId, $key]);
            $st = Db::pdo()->prepare('INSERT INTO mail_compose_files (outbox_id, file_key, chunk_no, content, created_at) VALUES (?, ?, ?, ?, ?)');
            $n = 0;
            for ($at = 0, $len = strlen($content); $at < $len || $n === 0; $at += $size) {
                $st->bindValue(1, $outboxId);
                $st->bindValue(2, $key);
                $st->bindValue(3, $n++, PDO::PARAM_INT);
                $st->bindValue(4, (string) substr($content, $at, $size), PDO::PARAM_LOB);
                $st->bindValue(5, $now);
                $st->execute();
                if ($len === 0) {
                    break;
                }
            }
        });
    }

    /** The file's bytes; MailError 404 when they are gone */
    public static function get(string $outboxId, string $key): string
    {
        $rows = Db::all('SELECT content FROM mail_compose_files WHERE outbox_id = ? AND file_key = ? ORDER BY chunk_no', [$outboxId, $key]);
        if (!$rows) {
            throw MailError::notFound('Ce fichier n’est plus dans bureau.');
        }
        $out = '';
        foreach ($rows as $r) {
            $out .= is_resource($r['content']) ? stream_get_contents($r['content']) : (string) $r['content'];
        }

        return $out;
    }

    public static function has(string $outboxId, string $key): bool
    {
        return (bool) Db::value('SELECT 1 FROM mail_compose_files WHERE outbox_id = ? AND file_key = ? LIMIT 1', [$outboxId, $key]);
    }

    public static function remove(string $outboxId, ?string $key = null): void
    {
        $key === null
            ? Db::exec('DELETE FROM mail_compose_files WHERE outbox_id = ?', [$outboxId])
            : Db::exec('DELETE FROM mail_compose_files WHERE outbox_id = ? AND file_key = ?', [$outboxId, $key]);
    }
}
