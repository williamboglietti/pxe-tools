<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

namespace Admin\Security;

use InvalidArgumentException;
use PDO;

/** Local administrator credentials only; HTTP sessions and throttling are separate. */
final class Administrator
{
    public function __construct(private readonly PDO $db) {}

    /** Create exactly one initial administrator; an existing account is never replaced. */
    public function createInitial(string $username, string $password): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $username) !== 1) {
            throw new InvalidArgumentException('invalid username');
        }
        // bcrypt implementations only use 72 bytes; reject rather than silently truncate.
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('invalid password length or contents');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $this->db->prepare('INSERT INTO administrators (id, username, password_hash) VALUES (1, ?, ?)');
        $insert->execute([$username, $hash]);
    }

    /** Return identity only, never the stored hash. */
    public function authenticate(string $username, string $password): ?array
    {
        if (strlen($username) > 64 || strlen($password) > 72 || str_contains($password, "\0")) return null;
        $row = $this->db->query('SELECT id, username, password_hash FROM administrators WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        // Always verify a hash when an administrator exists, including for an unknown username.
        $correct = password_verify($password, $row['password_hash']);
        if (!$correct || !hash_equals($row['username'], $username)) return null;
        return ['id' => (int)$row['id'], 'username' => $row['username']];
    }
}
