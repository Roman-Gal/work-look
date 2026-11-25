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
        foreach (['Version20261120153213', 'Version20261120230000', 'Version20261120235900', 'Version20261124160000', 'Version20261124180000', 'Version20261125140000'] as $name) {
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
        $newSchema = $db->createSchemaManager()->introspectSchema();
        $resumePg = new \DoctrineMigrations\Version20261124180000($postgres, new NullLogger()); $resumePg->up($newSchema);
        $resumeSql = implode("\n", array_map(static fn ($query) => $query->getStatement(), $resumePg->getSql()));
        self::assertStringContainsString('CREATE TABLE resume_draft', $resumeSql); self::assertStringContainsString('CREATE TABLE knowledge_entry', $resumeSql);
        self::assertStringNotContainsString('DELETE FROM', $resumeSql);
        $resumeMigration = new \DoctrineMigrations\Version20261124180000($db, new NullLogger()); $resumeMigration->up($newSchema);
        foreach ($resumeMigration->getSql() as $query) { $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes()); }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM resume_draft')); self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM knowledge_entry'));
        self::assertSame('preserved-password-hash', $db->fetchOne('SELECT password FROM app_user'));
        $db->insert('resume_draft',['user_id'=>1,'filename'=>'existing.pdf','content_hash'=>str_repeat('a',64),'text'=>'Existing private source',
            'request_text'=>'PHP','preferences'=>'Remote','pages'=>1,'updated_at'=>'2026-11-24 18:00:00']);
        $batchPg=new \DoctrineMigrations\Version20261125140000($postgres,new NullLogger());$batchPg->up($db->createSchemaManager()->introspectSchema());
        $batchSql=implode("\n",array_map(static fn($query)=>$query->getStatement(),$batchPg->getSql()));
        self::assertStringContainsString('BYTEA',$batchSql);self::assertStringContainsString('CREATE TABLE local_work_request',$batchSql);
        self::assertStringNotContainsString('DROP TABLE',$batchSql);self::assertStringNotContainsString('DELETE FROM',$batchSql);
        $batch=new \DoctrineMigrations\Version20261125140000($db,new NullLogger());$batch->up($db->createSchemaManager()->introspectSchema());
        foreach($batch->getSql() as $query){$db->executeStatement($query->getStatement(),$query->getParameters(),$query->getTypes());}
        self::assertSame('Existing private source',$db->fetchOne('SELECT text FROM resume_draft'));self::assertNull($db->fetchOne('SELECT pdf_data FROM resume_draft'));
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        $em = new \Doctrine\ORM\EntityManager($db, $config);
        $em->getConfiguration()->setUseSchemaEditorApi(true);
        $expected = (new \Doctrine\ORM\Tools\SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
        $manager = $db->createSchemaManager(); $comparator = $manager->createComparator();
        foreach (['resume_draft', 'knowledge_entry', 'local_work_request'] as $table) {
            $diff = $comparator->compareTables($manager->introspectTable($table), $expected->getTable($table));
            self::assertTrue($diff->isEmpty(), implode("\n", $db->getDatabasePlatform()->getAlterTableSQL($diff)));
        }
        $db->close(); $postgres->close();
    }
}
