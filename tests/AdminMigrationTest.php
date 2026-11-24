<?php
declare(strict_types=1);
namespace App\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AdminMigrationTest extends TestCase
{
    public function testMigrationPreservesExistingAccountsAndBuildsPortablePostgresSql(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['Version20261120153213', 'Version20261120230000', 'Version20261120235900', 'Version20261124160000'] as $name) {
            require_once dirname(__DIR__).'/migrations/'.$name.'.php';
        }
        foreach (['Version20261120153213', 'Version20261120230000', 'Version20261120235900'] as $name) {
            $class = 'DoctrineMigrations\\'.$name; $migration = new $class($db, new NullLogger());
            $migration->up($db->createSchemaManager()->introspectSchema());
            foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        }
        $db->insert('app_user', ['email' => 'existing@test.local', 'password' => 'preserved-password-hash',
            'created_at' => '2026-11-20 00:00:00', 'is_verified' => 1, 'live_ai_enabled' => 1, 'free_search_run_id' => 'preserved-reservation']);
        $schema = $db->createSchemaManager()->introspectSchema();
        $postgres = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16']);
        $pgMigration = new \DoctrineMigrations\Version20261124160000($postgres, new NullLogger());
        $pgMigration->up($schema);
        $sql = implode("\n", array_map(static fn ($query) => $query->getStatement(), $pgMigration->getSql()));
        self::assertStringContainsString('ALTER TABLE app_user ADD admin_key', $sql);
        self::assertStringContainsString('CREATE TABLE activity_event', $sql);
        self::assertStringNotContainsString('DROP TABLE', $sql); self::assertStringNotContainsString('DELETE FROM', $sql);
        $migration = new \DoctrineMigrations\Version20261124160000($db, new NullLogger()); $migration->up($schema);
        foreach ($migration->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        $user = $db->fetchAssociative('SELECT * FROM app_user');
        self::assertSame('preserved-password-hash', $user['password']); self::assertSame('preserved-reservation', $user['free_search_run_id']);
        self::assertNull($user['admin_key']); self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM activity_event'));
        self::assertTrue($db->createSchemaManager()->introspectTable('app_user')->hasIndex('singleton_admin_key'));
        $db->close(); $postgres->close();
    }
}
