<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AdminService;
use DineReserve\DomainException;

/**
 * URS REQ-1.9 — users, roles, capabilities and policy settings (W12).
 */
final class AdminServiceTest extends TestCase
{
    private AdminService $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new AdminService($this->pdo, $this->clock);
    }

    private function assertDomainCode(string $code, callable $call): void
    {
        try {
            $call();
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->errorCode(), 'unexpected: ' . $exception->getMessage());

            return;
        }

        self::fail('expected DomainException with code ' . $code);
    }

    // ------------------------------------------------------------- security

    public function testAdminTokenIsCheckedInConstantTime(): void
    {
        self::assertTrue($this->admin->tokenIsValid(AdminService::DEFAULT_ADMIN_TOKEN));
        self::assertFalse($this->admin->tokenIsValid('wrong-token'));
        self::assertFalse($this->admin->tokenIsValid(''));
        self::assertFalse($this->admin->tokenIsValid(null));

        $this->assertDomainCode('UNAUTHORIZED', fn () => $this->admin->requireToken('nope'));
        $this->admin->requireToken(AdminService::DEFAULT_ADMIN_TOKEN);
        self::assertTrue(true);
    }

    public function testCapabilityMatrixEnforcesLeastPrivilege(): void
    {
        self::assertTrue($this->admin->can('administrator', 'settings.write'));
        self::assertTrue($this->admin->can('manager', 'settings.read'));
        self::assertFalse($this->admin->can('manager', 'users.write'));
        self::assertFalse($this->admin->can('host', 'settings.read'));
        self::assertTrue($this->admin->can('host', 'bookings.write'));
        self::assertTrue($this->admin->can('viewer', 'reports.read'));
        self::assertFalse($this->admin->can('viewer', 'bookings.write'));
        self::assertSame([], $this->admin->capabilities('nobody'));

        $this->assertDomainCode('FORBIDDEN', fn () => $this->admin->requireCapability('host', 'settings.write'));
    }

    // ---------------------------------------------------------------- users

    public function testCreateUserHashesThePasswordAndAssignsTheRole(): void
    {
        $user = $this->admin->createUser([
            'name' => 'Lee Host',
            'email' => 'Lee@Example.com',
            'password' => 'Passw0rd!',
            'role' => 'host',
        ]);

        self::assertSame('Lee Host', $user['name']);
        self::assertSame('lee@example.com', $user['email']);
        self::assertTrue($user['active']);
        self::assertSame(['host'], $user['roles']);

        $hash = (string) $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . (int) $user['id'])->fetchColumn();
        self::assertNotSame('Passw0rd!', $hash);
        self::assertTrue(password_verify('Passw0rd!', $hash));
    }

    public function testCreateUserValidatesEveryFieldAtOnce(): void
    {
        $exception = null;
        try {
            $this->admin->createUser(['name' => '', 'email' => 'nope', 'password' => 'short', 'role' => 'wizard']);
        } catch (DomainException $caught) {
            $exception = $caught;
        }
        self::assertNotNull($exception);
        self::assertSame('VALIDATION_ERROR', $exception->errorCode());
        self::assertArrayHasKey('name', $exception->details());
        self::assertArrayHasKey('email', $exception->details());
        self::assertArrayHasKey('password', $exception->details());
        self::assertArrayHasKey('role', $exception->details());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE email = "nope"')->fetchColumn());
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->admin->createUser([
            'name' => 'Duplicate',
            'email' => 'manager@dinereserve.local',
            'password' => 'Passw0rd!',
            'role' => 'host',
        ]));
    }

    public function testSetRoleReplacesTheExistingRole(): void
    {
        $user = $this->admin->createUser(['name' => 'Host', 'email' => 'host2@example.com', 'password' => 'Passw0rd!', 'role' => 'host']);
        $updated = $this->admin->setRole((int) $user['id'], 'manager');

        self::assertSame(['manager'], $updated['roles']);
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->admin->setRole((int) $user['id'], 'wizard'));
    }

    public function testDeactivatedUserCannotAuthenticate(): void
    {
        $user = $this->admin->createUser(['name' => 'Host', 'email' => 'host3@example.com', 'password' => 'Passw0rd!', 'role' => 'host']);
        $this->admin->setActive((int) $user['id'], false);

        $this->assertDomainCode('INVALID_CREDENTIALS', fn () => $this->admin->authenticate('host3@example.com', 'Passw0rd!'));
    }

    public function testAuthenticateSucceedsAndRecordsTheLogin(): void
    {
        $user = $this->admin->authenticate('manager@dinereserve.local', 'DemoPass123!');

        self::assertSame('manager@dinereserve.local', $user['email']);
        self::assertNotEmpty($user['roles']);
        self::assertNotNull($user['last_login_at']);
        self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.succeeded'")->fetchColumn());
    }

    public function testAuthenticateRejectsWrongPasswordWithoutLeaking(): void
    {
        $first = null;
        $second = null;
        try {
            $this->admin->authenticate('manager@dinereserve.local', 'wrong');
        } catch (DomainException $caught) {
            $first = $caught;
        }
        try {
            $this->admin->authenticate('missing@example.com', 'wrong');
        } catch (DomainException $caught) {
            $second = $caught;
        }

        self::assertSame('INVALID_CREDENTIALS', $first->errorCode());
        self::assertSame('INVALID_CREDENTIALS', $second->errorCode());
        self::assertSame($first->getMessage(), $second->getMessage());
        self::assertGreaterThanOrEqual(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.failed'")->fetchColumn());
    }

    public function testUsersListIncludesRoles(): void
    {
        $users = $this->admin->users();

        self::assertGreaterThanOrEqual(3, count($users));
        foreach ($users as $user) {
            self::assertArrayHasKey('roles', $user);
            self::assertNotEmpty($user['roles']);
        }
        $this->assertDomainCode('NOT_FOUND', fn () => $this->admin->findUser(999999));
    }

    // ------------------------------------------------------------- settings

    public function testSettingsReturnTheDefaults(): void
    {
        $settings = $this->admin->settings($this->restaurantId);

        self::assertSame($this->restaurantId, $settings['restaurant_id']);
        self::assertSame(15, $settings['slot_minutes']);
        self::assertSame(90, $settings['max_days_ahead']);
        self::assertSame(120, $settings['cutoff_minutes']);
        self::assertSame(15, $settings['turnover_buffer_minutes']);
        self::assertSame(5, $settings['waitlist_offer_minutes']);
        self::assertSame(20, $settings['max_party_size']);
    }

    public function testUpdateSettingsPersistsAndIsAudited(): void
    {
        $updated = $this->admin->updateSettings($this->restaurantId, ['cutoff_minutes' => 90, 'slot_minutes' => 30]);

        self::assertSame(90, $updated['cutoff_minutes']);
        self::assertSame(30, $updated['slot_minutes']);
        self::assertSame(90, $this->admin->settings($this->restaurantId)['cutoff_minutes']);
        self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'settings.updated'")->fetchColumn());

        // Second write updates the existing row instead of duplicating it.
        $this->admin->updateSettings($this->restaurantId, ['cutoff_minutes' => 60]);
        self::assertSame(60, $this->admin->settings($this->restaurantId)['cutoff_minutes']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM settings WHERE restaurant_id = ' . $this->restaurantId)->fetchColumn());
    }

    public function testSettingsValidationRejectsOutOfRangeAndUnknownKeys(): void
    {
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->admin->updateSettings($this->restaurantId, ['cutoff_minutes' => 9999]));
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->admin->updateSettings($this->restaurantId, ['slot_minutes' => -5]));
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->admin->updateSettings($this->restaurantId, ['max_tables' => 5]));
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->admin->updateSettings($this->restaurantId, []));
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->admin->updateSettings($this->restaurantId, ['slot_minutes' => '15.5']));
        $this->assertDomainCode('NOT_FOUND', fn () => $this->admin->settings(999));
    }
}
