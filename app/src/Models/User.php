<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;

/**
 * Auth locale (Spec B Lot 1) — CRUD utilisateurs avec bcrypt.
 *
 * Mode `local` (par défaut) : login form → POST /login → password_verify
 * → $_SESSION['user_id'] = id. Spec B Lot 3 ajoutera un mode `authentik`
 * forward-auth (header X-Forwarded-User) qui bypass cette table.
 */
final class User
{
    /**
     * Crée un user avec password bcrypt. Appelé par le wizard d'init (Lot 2)
     * ou en CLI le temps que le wizard arrive (Lot 1).
     *
     * @return int id du user créé
     * @throws \PDOException si username déjà pris (UNIQUE constraint)
     */
    public static function create(string $username, string $password): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = Db::get()->prepare(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)'
        );
        $stmt->execute([$username, $hash]);
        return (int)Db::get()->lastInsertId();
    }

    public static function findByUsername(string $username): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Vérifie le password contre le hash en DB. Met à jour last_login_at
     * en cas de succès (sentinel d'activité).
     */
    public static function verifyPassword(string $username, string $password): ?array
    {
        $user = self::findByUsername($username);
        if ($user === null) {
            // Anti-timing attack : faire un hash bidon même quand le user n'existe pas
            password_verify($password, '$2y$10$0000000000000000000000000000000000000000000000000000000');
            return null;
        }
        if (!password_verify($password, (string)$user['password_hash'])) {
            return null;
        }
        Db::get()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')
                  ->execute([(int)$user['id']]);
        return $user;
    }

    /** Combien de users existent (pour bootstrap : 0 = "premier admin à créer"). */
    public static function count(): int
    {
        return (int)Db::get()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** Premier username (le plus ancien) — utilisé par le wizard pour afficher l'admin existant. */
    public static function firstUsername(): ?string
    {
        $row = Db::get()->query('SELECT username FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
        return $row === false ? null : (string)$row;
    }

    /** Purge tous les users — utilisé par le wizard quand l'admin choisit "Remplacer le compte existant". */
    public static function deleteAll(): void
    {
        Db::get()->exec('DELETE FROM users');
    }
}
