<?php

namespace MikroApi\Tests;

use MikroApi\Database\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTransactionTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        Database::reset();
        $this->db = Database::connect(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->execute("CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
    }

    protected function tearDown(): void
    {
        Database::reset();
    }

    public function testTransactionCommits(): void
    {
        $result = $this->db->transaction(function () {
            $this->db->execute("INSERT INTO items (name) VALUES ('a')");
            $this->db->execute("INSERT INTO items (name) VALUES ('b')");
            return 'done';
        });

        $this->assertEquals('done', $result);
        $count = $this->db->queryOne("SELECT COUNT(*) as c FROM items");
        $this->assertEquals(2, $count['c']);
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function () {
                $this->db->execute("INSERT INTO items (name) VALUES ('a')");
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('fail', $e->getMessage());
        }

        $count = $this->db->queryOne("SELECT COUNT(*) as c FROM items");
        $this->assertEquals(0, $count['c']);
    }

    public function testTransactionRethrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->db->transaction(function () {
            throw new \RuntimeException('boom');
        });
    }

    public function testResetClearsSingleton(): void
    {
        Database::reset();

        $this->expectException(\RuntimeException::class);
        Database::getInstance();
    }
}
