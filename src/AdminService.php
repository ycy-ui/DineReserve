<?php

declare(strict_types=1);

namespace DineReserve;

use PDO;
use Throwable;

/**
 * URS REQ-1.9 — users, roles, capabilities and the policy settings a manager
 * can change at runtime (W12).
 *
 * Authentication is deliberately small and dependency free: passwords are hashed
 * with `password_hash()` (bcrypt/argon per PHP defaults) and verified with
 * `password_verify()`. Authorisation is a role → capability matrix, evaluated on
 * every admin request. The deployment credential (the API token) is compared in
 * constant time.
 */
final class AdminService
{
    /** Fallback credential for local development; the deployment sets ADMIN_TOKEN. */
    public const DEFAULT_ADMIN_TOKEN = 'dev-admin-token';

    /** Merchant session lifetime (stateless HMAC token, revoked by deactivating the user). */
    public const SESSION_TTL_HOURS = 8;

    /** Role priority used when a user holds several roles. */
    public const ROLE_PRIORITY = ['administrator', 'manager', 'host', 'viewer', 'diner'];

    /** Roles and the capabilities each one grants. */
    public const CAPABILITIES = [
        'administrator' => [
            'users.read', 'users.write',
            'settings.read', 'settings.write',
            'bookings.read', 'bookings.write',
            'reports.read', 'floorplan.write',
        ],
        'manager' => [
            'settings.read', 'bookings.read', 'bookings.write',
            'reports.read', 'floorplan.write',
        ],
        'host' => [
            'bookings.read', 'bookings.write', 'floorplan.write',
        ],
        'viewer' => [
            'reports.read',
        ],
    ];

    /** Policy settings with their defaults and accepted integer ranges. */
    public const SETTINGS_RULES = [
        'slot_minutes' => ['default' => 15, 'min' => 5, 'max' => 60],
        'max_days_ahead' => ['default' => 90, 'min' => 1, 'max' => 365],
        'cutoff_minutes' => ['default' => 120, 'min' => 0, 'max' => 1440],
        'turnover_buffer_minutes' => ['default' => 15, 'min' => 0, 'max' => 120],
        'waitlist_offer_minutes' => ['default' => 5, 'min' => 1, 'max' => 60],
        'max_party_size' => ['default' => 20, 'min' => 1, 'max' => 100],
    ];

    private PDO $pdo;

    private Clock $clock;

    public function __construct(PDO $pdo, ?Clock $clock = null)
    {
        $this->pdo = $pdo;
        $this->clock = $clock ?? new SystemClock();
    }

    // ------------------------------------------------------------- security

    /** Compare the deployment credential in constant time. */
    public function tokenIsValid(?string $token): bool
    {
        $expected = (string) (getenv('ADMIN_TOKEN') ?: self::DEFAULT_ADMIN_TOKEN);
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /** Default role for the demo credential when a caller sends no X-Actor-Role. */
    public function defaultRole(): string
    {
        return (string) (getenv('ADMIN_DEFAULT_ROLE') ?: 'administrator');
    }

    /** @return list<string> */
    public function capabilities(string $roleCode): array
    {
        return self::CAPABILITIES[strtolower($roleCode)] ?? [];
    }

    public function can(string $roleCode, string $capability): bool
    {
        return in_array($capability, $this->capabilities($roleCode), true);
    }

    /** Throw a 403 DomainException unless the role grants the capability. */
    public function requireCapability(string $roleCode, string $capability): void
    {
        if (!$this->can($roleCode, $capability)) {
            throw new DomainException(
                'FORBIDDEN',
                'The role ' . $roleCode . ' is not allowed to ' . $capability . '.',
                ['role' => strtolower($roleCode), 'capability' => $capability],
                403
            );
        }
    }

    public function requireToken(?string $token): void
    {
        if (!$this->tokenIsValid($token)) {
            throw new DomainException(
                'UNAUTHORIZED',
                'A valid X-Admin-Token header is required.',
                [],
                401
            );
        }
    }

    // ---------------------------------------------------------------- users

    /** @return list<array<string,mixed>> */
    public function users(): array
    {
        $statement = $this->pdo->query(
            'SELECT u.id, u.name, u.email, u.is_active, u.last_login_at, u.created_at, r.code AS role
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN roles r ON r.id = ur.role_id
              ORDER BY u.id'
        );
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (!isset($rows[$id])) {
                $rows[$id] = [
                    'id' => $id,
                    'name' => (string) $row['name'],
                    'email' => (string) $row['email'],
                    'active' => (bool) $row['is_active'],
                    'last_login_at' => $row['last_login_at'],
                    'roles' => [],
                ];
            }
            if ($row['role'] !== null) {
                $rows[$id]['roles'][] = (string) $row['role'];
            }
        }

        return array_values($rows);
    }

    /**
     * @param array{name:string,email:string,password:string,role?:string,restaurant_id?:int} $input
     * @return array<string,mixed>
     */
    public function createUser(array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $role = strtolower(trim((string) ($input['role'] ?? 'host')));

        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'A name of at most 80 characters is required.';
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid e-mail address is required.';
        }
        if (mb_strlen($password) < 8 || preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
            $errors['password'] = 'Use at least 8 characters including a letter and a digit.';
        }
        if (!isset(self::CAPABILITIES[$role])) {
            $errors['role'] = 'Unknown role; expected diner, host, manager or administrator.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $statement->execute([$email]);
            if ((int) $statement->fetchColumn() > 0) {
                $errors['email'] = 'That e-mail address already has an account.';
            }
        }
        if ($errors !== []) {
            throw DomainException::validation($errors);
        }

        $restaurantId = (int) ($input['restaurant_id'] ?? $this->firstRestaurantId());
        $statement = $this->pdo->prepare(
            'INSERT INTO users (restaurant_id, name, email, password_hash, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $statement->execute([
            $restaurantId,
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            Database::dateTimeFor($this->pdo, Slot::iso8601($this->clock->now())),
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->setRole($userId, $role);
        $this->audit('admin', 'user.created', 'user', $userId, ['email' => $email, 'role' => $role]);

        return $this->findUser($userId);
    }

    /** @return array<string,mixed> */
    public function setRole(int $userId, string $roleCode): array
    {
        $role = strtolower(trim($roleCode));
        $roleId = $this->roleId($role);
        if ($roleId === null) {
            throw DomainException::validation(['role' => 'Unknown role.']);
        }
        $user = $this->findUser($userId);
        $this->pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        $this->audit('admin', 'user.role_changed', 'user', $userId, ['role' => $role, 'was' => $user['roles']]);

        return $this->findUser($userId);
    }

    /** @return array<string,mixed> */
    public function setActive(int $userId, bool $active): array
    {
        $this->findUser($userId);
        $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);
        $this->audit('admin', $active ? 'user.activated' : 'user.deactivated', 'user', $userId, []);

        return $this->findUser($userId);
    }

    /**
     * Verify credentials and return the user with roles; failed logins are
     * audited and answered with the same message (no account enumeration).
     *
     * @return array<string,mixed>
     */
    public function authenticate(string $email, string $password): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        $valid = is_array($user)
            && (int) $user['is_active'] === 1
            && password_verify($password, (string) $user['password_hash']);

        if (!$valid) {
            $this->audit('anonymous', 'auth.failed', 'user', null, ['email' => strtolower(trim($email))]);
            throw new DomainException('INVALID_CREDENTIALS', 'E-mail or password is incorrect.', [], 401);
        }

        $now = Database::dateTimeFor($this->pdo, Slot::iso8601($this->clock->now()));
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$now, (int) $user['id']]);
        $this->audit('user:' . $user['id'], 'auth.succeeded', 'user', (int) $user['id'], []);

        return $this->findUser((int) $user['id']);
    }

    // -------------------------------------------------------------- session

    /**
     * Merchant sign-in: verify the credentials and issue a stateless session token.
     *
     * @return array{token:string,expires_at:string,expires_in_hours:int,user:array<string,mixed>,role:string}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->authenticate($email, $password);
        $session = $this->issueSession($user);
        $this->audit('user:' . $user['id'], 'session.issued', 'user', (int) $user['id'], [
            'expires_at' => $session['expires_at'],
            'role' => $session['role'],
        ]);

        return $session;
    }

    /**
     * @param array<string,mixed> $user a user payload from authenticate()/findUser()
     * @return array{token:string,expires_at:string,expires_in_hours:int,user:array<string,mixed>,role:string}
     */
    public function issueSession(array $user): array
    {
        $expires = $this->clock->now()->modify('+' . self::SESSION_TTL_HOURS . ' hours')->getTimestamp();
        $payload = (int) $user['id'] . '.' . $expires;
        $signature = substr(hash_hmac('sha256', $payload, $this->sessionSecret()), 0, 32);
        $token = rtrim(strtr(base64_encode($payload . '.' . $signature), '+/', '-_'), '=');

        return [
            'token' => $token,
            'expires_at' => Slot::iso8601($this->clock->now()->modify('+' . self::SESSION_TTL_HOURS . ' hours')),
            'expires_in_hours' => self::SESSION_TTL_HOURS,
            'user' => $user,
            'role' => $this->primaryRole($user['roles'] ?? []),
        ];
    }

    /**
     * @return array{user:array<string,mixed>,role:string,expires_at:string}
     */
    public function verifySession(string $token): array
    {
        $token = trim($token);
        $raw = $token === '' ? false : base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false) {
            throw $this->invalidSession();
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 3) {
            throw $this->invalidSession();
        }
        [$userId, $expires, $signature] = $parts;
        if (!preg_match('/^\d+$/', $userId) || !preg_match('/^\d+$/', $expires)) {
            throw $this->invalidSession();
        }
        $expected = substr(hash_hmac('sha256', $userId . '.' . $expires, $this->sessionSecret()), 0, 32);
        if (!hash_equals($expected, (string) $signature)) {
            throw $this->invalidSession();
        }
        if ((int) $expires < $this->clock->now()->getTimestamp()) {
            throw new DomainException('SESSION_EXPIRED', 'The merchant session has expired; please sign in again.', [], 401);
        }
        $user = $this->findUser((int) $userId);
        if (!$user['active']) {
            throw new DomainException('SESSION_INVALID', 'This account is no longer active.', [], 401);
        }

        return [
            'user' => $user,
            'role' => $this->primaryRole($user['roles']),
            'expires_at' => Slot::iso8601((new \DateTimeImmutable('@' . (int) $expires))->setTimezone(new \DateTimeZone('UTC'))),
        ];
    }

    /** The strongest role a user holds, used for capability checks. */
    public function primaryRole(array $roles): string
    {
        $roles = array_map(static fn ($role): string => strtolower((string) $role), $roles);
        foreach (self::ROLE_PRIORITY as $candidate) {
            if (in_array($candidate, $roles, true)) {
                return $candidate;
            }
        }

        // No admin role at all: stay a diner, which grants no admin capability.
        return 'diner';
    }

    private function invalidSession(): DomainException
    {
        return new DomainException('SESSION_INVALID', 'The merchant session token is invalid.', [], 401);
    }

    private function sessionSecret(): string
    {
        $secret = getenv('SESSION_SECRET');
        if ($secret === false || $secret === '') {
            $secret = getenv('ADMIN_TOKEN');
        }
        if ($secret === false || $secret === '') {
            $secret = self::DEFAULT_ADMIN_TOKEN;
        }

        return (string) $secret;
    }

    // ------------------------------------------------------------- settings

    /** @return array<string,mixed> */
    public function settings(int $restaurantId): array
    {
        $this->requireRestaurant($restaurantId);
        $stored = [];
        $statement = $this->pdo->prepare('SELECT key, value FROM settings WHERE restaurant_id = ?');
        $statement->execute([$restaurantId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored[(string) $row['key']] = (string) $row['value'];
        }

        $result = ['restaurant_id' => $restaurantId];
        foreach (self::SETTINGS_RULES as $key => $rule) {
            $result[$key] = isset($stored[$key]) ? (int) $stored[$key] : $rule['default'];
        }

        return $result;
    }

    /**
     * @param array<string,int|string> $changes
     * @return array<string,mixed>
     */
    public function updateSettings(int $restaurantId, array $changes): array
    {
        $this->requireRestaurant($restaurantId);
        $errors = [];
        $clean = [];
        foreach ($changes as $key => $value) {
            $key = (string) $key;
            if (!isset(self::SETTINGS_RULES[$key])) {
                $errors[$key] = 'Unknown setting.';
                continue;
            }
            if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
                $errors[$key] = 'Expected an integer.';
                continue;
            }
            $rule = self::SETTINGS_RULES[$key];
            $number = (int) $value;
            if ($number < $rule['min'] || $number > $rule['max']) {
                $errors[$key] = 'Expected a value between ' . $rule['min'] . ' and ' . $rule['max'] . '.';
                continue;
            }
            $clean[$key] = $number;
        }
        if ($errors !== []) {
            throw DomainException::validation($errors);
        }
        if ($clean === []) {
            throw DomainException::validation(['settings' => 'Provide at least one setting to change.']);
        }

        $now = Database::dateTimeFor($this->pdo, Slot::iso8601($this->clock->now()));
        $exists = $this->pdo->prepare('SELECT COUNT(*) FROM settings WHERE restaurant_id = ? AND key = ?');
        $insert = $this->pdo->prepare('INSERT INTO settings (restaurant_id, key, value, updated_at) VALUES (?, ?, ?, ?)');
        $update = $this->pdo->prepare('UPDATE settings SET value = ?, updated_at = ? WHERE restaurant_id = ? AND key = ?');
        foreach ($clean as $key => $value) {
            $exists->execute([$restaurantId, $key]);
            if ((int) $exists->fetchColumn() > 0) {
                $update->execute([(string) $value, $now, $restaurantId, $key]);
            } else {
                $insert->execute([$restaurantId, $key, (string) $value, $now]);
            }
        }
        $this->audit('admin', 'settings.updated', 'restaurant', $restaurantId, $clean);

        return $this->settings($restaurantId);
    }

    // -------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    public function findUser(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            throw DomainException::notFound('User not found.');
        }
        $roles = $this->pdo->prepare(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.code'
        );
        $roles->execute([$userId]);

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'active' => (bool) $user['is_active'],
            'last_login_at' => $user['last_login_at'],
            'roles' => array_map('strval', $roles->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    private function roleId(string $code): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM roles WHERE code = ?');
        $statement->execute([$code]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function firstRestaurantId(): int
    {
        $id = $this->pdo->query('SELECT id FROM restaurants ORDER BY id LIMIT 1')->fetchColumn();

        return $id === false ? 1 : (int) $id;
    }

    private function requireRestaurant(int $restaurantId): void
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM restaurants WHERE id = ?');
        $statement->execute([$restaurantId]);
        if ((int) $statement->fetchColumn() === 0) {
            throw DomainException::notFound('Restaurant not found.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function audit(string $actor, string $action, string $entity, ?int $entityId, array $payload): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO audit_log (actor, action, entity, entity_id, payload, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $actor,
                $action,
                $entity,
                $entityId === null ? null : (string) $entityId,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                Database::dateTimeFor($this->pdo, Slot::iso8601($this->clock->now())),
            ]);
        } catch (Throwable) {
            // Audit is best effort.
        }
    }
}
