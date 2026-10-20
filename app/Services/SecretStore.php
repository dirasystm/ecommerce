<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Crypto;
use Waimart\Core\Database;
use Waimart\Core\ValidationException;

/**
 * Credentials the admin can set without editing server files (e.g. the SMTP password), encrypted at rest with the app
 * key (AES-256-GCM, Core\Crypto). Values are write-only: the admin sees whether a secret is set, when and by whom,
 * never the value. Only known names can be stored (SLOTS), so the store cannot become a dumping ground. Changes are
 * audited by name only. If the app key changes, stored values can no longer be read; they show as needing re-entry.
 */
final class SecretStore
{
    /** name => [label, what it is used for] */
    public const SLOTS = [
        'smtp_password' => ['SMTP password', 'Used to send email through the mail server set under Settings → Email & SMS.'],
        'sms_api_token' => ['SMS API token', 'The Talksasa API token used to send SMS (Admin → Email & SMS).'],
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** The decrypted value, or null when not set or unreadable. Never log or display the result. */
    public function get(string $name): ?string
    {
        $row = $this->db->value('SELECT ciphertext FROM secrets WHERE name = ?', [$name]);
        return $row === null || $row === false ? null : Crypto::decrypt((string) $row);
    }

    public function set(string $name, string $value, int $actorId): void
    {
        $this->known($name);
        if ($value === '' || mb_strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new ValidationException(['secret_' . $name => 'Enter the ' . strtolower(self::SLOTS[$name][0]) . ' (up to 500 characters, no line breaks).']);
        }
        $this->db->exec(
            'INSERT INTO secrets (name, ciphertext, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), updated_by = VALUES(updated_by), updated_at = NOW()',
            [$name, Crypto::encrypt($value), $actorId]
        );
        AuditLogger::log('secret.updated', 'secret', null, null, ['name' => $name], $actorId);
    }

    public function clear(string $name, int $actorId): void
    {
        $this->known($name);
        if ($this->db->exec('DELETE FROM secrets WHERE name = ?', [$name]) > 0) {
            AuditLogger::log('secret.removed', 'secret', null, ['name' => $name], null, $actorId);
        }
    }

    /** @return array{set:bool,readable:bool,updated_at:?string,updated_by:?string} — never the value */
    public function status(string $name): array
    {
        $row = $this->db->one(
            "SELECT s.ciphertext, s.updated_at, CONCAT(u.first_name, ' ', u.last_name) AS who
               FROM secrets s LEFT JOIN users u ON u.id = s.updated_by WHERE s.name = ?",
            [$name]
        );
        if ($row === null) {
            return ['set' => false, 'readable' => false, 'updated_at' => null, 'updated_by' => null];
        }
        return ['set' => true, 'readable' => Crypto::decrypt((string) $row['ciphertext']) !== null,
                'updated_at' => (string) $row['updated_at'], 'updated_by' => $row['who'] !== null ? trim((string) $row['who']) : null];
    }

    private function known(string $name): void
    {
        if (!isset(self::SLOTS[$name])) {
            throw new \InvalidArgumentException("Unknown secret $name");
        }
    }
}
