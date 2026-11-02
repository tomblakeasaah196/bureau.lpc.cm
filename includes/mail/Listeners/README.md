# includes/mail/Listeners

Listeners for Courrier's events (docs/MAIL.md §7.4). Each `*.php` file here returns a map of event name to
callable, and `LPC\Mail\Events` loads every file the first time an event is raised:

```php
<?php
// includes/mail/Listeners/team_new_mail.php  (PR 4)
return [
    'mail.received' => function (array $e): void {
        // $e['account_id'], $e['message_ids'], $e['thread_ids']
    },
];
```

Rules: be quick, never throw (Events::emit logs and moves on), never send mail from here. File names start with
the owning PR's prefix: `team_*.php` (PR 4), `ai_*.php` (PR 5), `compose_*.php` (PR 3), `read_*.php` (PR 2).
