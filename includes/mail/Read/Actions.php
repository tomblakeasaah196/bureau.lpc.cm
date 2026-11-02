<?php

namespace LPC\Mail\Read;

use LPC\Mail\MailAccess;
use LPC\Mail\MailActions;
use LPC\Mail\MailError;
use LPC\Mail\MailNav;
use LPC\Mail\Store;

/**
 * The reader's toolbar and keyboard actions on the open conversation (docs/MAIL.md §2), through PR 1's MailActions
 * (the server first, then bureau's copy). Each one says what it did in a short French sentence; a move can be
 * undone (the ids and the folder they came from go back to the page, and are checked again on Annuler).
 *
 *   archive, spam, trash, inbox    move what is in view (Pas un indésirable / Remettre dans la boîte de réception)
 *   read, unread                   any level (unread: the latest message, to come back to it)
 *   flag, unflag                   suivi
 *   delete                         for good, from Corbeille or Indésirables only (the page confirms first)
 *   move:<folder id>               another folder of the same mailbox
 */
final class Actions
{
    public const MOVES = ['archive', 'spam', 'trash', 'inbox'];

    /** @return array{message: string, undo: ?array{ids: list<int>, folder: int}, left: bool} left: no longer in this folder */
    public static function run(Inbox $inbox, array $thread, string $action, bool $searching): array
    {
        $userId = $inbox->userId;
        $roles = $inbox->roles();
        $scope = Reader::scope($thread['id'], $inbox->account['id'], $inbox->folder, $searching, $roles);
        if (!$scope) {
            throw MailError::notFound('Cette conversation n’a plus de message ici : elle a peut-être été déplacée.');
        }
        $ids = array_map(fn ($m) => $m['id'], $scope);
        $latest = end($scope);
        $from = $scope[0]['folder_id'];
        $actions = MailActions::make();
        $moved = false;

        if (in_array($action, self::MOVES, true)) {
            $actions->moveToRole($userId, $ids, $action);
            $moved = true;
            $message = match ($action) {
                'archive' => 'Conversation archivée.',
                'spam' => 'Conversation placée dans Indésirables.',
                'trash' => 'Conversation placée dans la Corbeille.',
                default => 'Conversation remise dans la boîte de réception.',
            };
        } elseif ($action === 'read') {
            $actions->markRead($userId, $ids, true);
            $message = 'Marquée comme lue.';
        } elseif ($action === 'unread') {
            $actions->markRead($userId, [$latest['id']], false);
            $message = 'Marquée comme non lue.';
        } elseif ($action === 'flag') {
            $actions->flag($userId, [$latest['id']], true);
            $message = 'Marquée pour suivi.';
        } elseif ($action === 'unflag') {
            $flagged = array_map(fn ($m) => $m['id'], array_filter(Store::threadMessages($thread['id']), fn ($m) => $m['is_flagged'] && $m['account_id'] === $inbox->account['id']));
            $actions->flag($userId, $flagged, false);
            $message = 'Suivi retiré.';
        } elseif ($action === 'delete') {
            $actions->deleteForever($userId, $ids);
            $message = 'Conversation supprimée définitivement.';
        } elseif (preg_match('/^move:(\d+)$/', $action, $m)) {
            $to = $inbox->folderById((int) $m[1]) ?? throw MailError::invalid('Ce dossier n’appartient pas à cette boîte mail.');
            $actions->move($userId, $ids, $to['id']);
            $moved = true;
            $message = 'Conversation déplacée dans « ' . Store::folderLabel($to) . ' ».';
        } else {
            throw MailError::invalid('Action inconnue.');
        }
        MailNav::forget();

        // Moves keep the same rows (the server gives the new UIDs), so Annuler can bring them back
        $kept = $moved ? array_values(array_filter(Store::messages($ids), fn ($r) => $r['folder_id'] !== $from)) : [];

        return [
            'message' => $message,
            'undo' => $moved && $kept ? ['ids' => array_map(fn ($r) => $r['id'], $kept), 'folder' => $from] : null,
            'left' => $moved || $action === 'delete',
        ];
    }

    /** Annuler after a move: the same messages back to the folder they came from */
    public static function undo(Inbox $inbox, array $ids, int $folderId): string
    {
        $folder = $inbox->folderById($folderId);
        $rows = array_values(array_filter(Store::messages(array_slice(array_map('intval', $ids), 0, 500)), fn ($r) => $r['account_id'] === $inbox->account['id']));
        if (!$folder || !$rows) {
            throw MailError::invalid('Cette action ne peut plus être annulée.');
        }
        MailAccess::authorize($inbox->userId, $inbox->account, 'organise');
        MailActions::make()->move($inbox->userId, array_map(fn ($r) => $r['id'], $rows), $folder['id']);
        MailNav::forget();

        return 'Conversation remise dans « ' . Store::folderLabel($folder) . ' ».';
    }

    /** Vider: Corbeille or Indésirables, everything on the server */
    public static function emptyFolder(Inbox $inbox, array $folder): string
    {
        MailActions::make()->emptyFolder($inbox->userId, $folder['id']);
        MailNav::forget();

        return Store::folderLabel($folder) . ' est vide.';
    }
}
