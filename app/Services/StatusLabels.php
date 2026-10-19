<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\ValidationException;

/**
 * The names of order statuses as customers and staff see them (Fulfilment → Delivery settings). The statuses
 * themselves and their rules are fixed in code (OrderWorkflow); only the wording is editable. An edited name is a row
 * in `order_status_labels`; a blank name restores the built-in one (OrderPresenter::STATUS_LABELS).
 */
final class StatusLabels
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,string> status => name, for every status */
    public static function all(): array
    {
        if (self::$cache === null) {
            $edited = [];
            try {
                $edited = array_column(App::db()->all('SELECT status, label FROM order_status_labels'), 'label', 'status');
            } catch (\Throwable) {
                // before migration 023: built-in names
            }
            self::$cache = array_merge(OrderPresenter::STATUS_LABELS, array_intersect_key($edited, OrderPresenter::STATUS_LABELS));
        }
        return self::$cache;
    }

    /** @return array<string,true> statuses whose name was edited */
    public static function edited(): array
    {
        return array_fill_keys(array_column(App::db()->all('SELECT status FROM order_status_labels'), 'status'), true);
    }

    /** @param array<string,mixed> $names status => new name ('' = built-in) */
    public function save(array $names, int $actorId): void
    {
        $errors = [];
        $clean = [];
        foreach (OrderPresenter::STATUS_LABELS as $status => $default) {
            $v = trim((string) ($names[$status] ?? ''));
            if (mb_strlen($v) > 80 || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                $errors["label.$status"] = 'Keep the name on one line, up to 80 characters.';
            }
            $clean[$status] = $v === '' || $v === $default ? null : $v;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $before = self::all();
        App::db()->transaction(function () use ($clean, $actorId) {
            foreach ($clean as $status => $label) {
                if ($label === null) {
                    App::db()->exec('DELETE FROM order_status_labels WHERE status = ?', [$status]);
                } else {
                    App::db()->exec('INSERT INTO order_status_labels (status, label, updated_by) VALUES (?, ?, ?)
                                     ON DUPLICATE KEY UPDATE label = VALUES(label), updated_by = VALUES(updated_by), updated_at = NOW()', [$status, $label, $actorId]);
                }
            }
        });
        self::flush();
        $after = self::all();
        $changed = array_diff_assoc($after, $before);
        if ($changed) {
            AuditLogger::log('delivery.status_labels_updated', 'order_status_labels', null, array_intersect_key($before, $changed), $changed, $actorId);
        }
    }
}
