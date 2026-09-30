<?php

namespace MikroApi\Tests;

use MikroApi\Middleware\ApcuRateLimitStore;
use PHPUnit\Framework\TestCase;

class ApcuRateLimitStoreTest extends TestCase
{
    // Nota (Ronda 2 #1): estos tests requieren la extensión `apcu`, que no
    // suele estar disponible en entornos de desarrollo/CI mínimos. Se
    // saltan (no fallan) cuando la extensión no está cargada, pero validan
    // la lógica de apcu_add()/apcu_inc() en cualquier entorno que sí la
    // tenga (ej. CI con apcu instalada).

    protected function setUp(): void
    {
        if (!\extension_loaded('apcu')) {
            $this->markTestSkipped('La extensión apcu no está disponible.');
        }
    }

    public function testIncrementReturnsCountAndReset(): void
    {
        $store = new ApcuRateLimitStore('test_apcu_rl_a_');

        $first = $store->increment('ip1', 60);
        $this->assertEquals(1, $first['count']);

        $second = $store->increment('ip1', 60);
        $this->assertEquals(2, $second['count']);

        $third = $store->increment('ip1', 60);
        $this->assertEquals(3, $third['count']);

        // El reset debe mantenerse estable dentro de la misma ventana.
        $this->assertEquals($first['reset'], $second['reset']);
        $this->assertEquals($first['reset'], $third['reset']);
    }

    public function testWindowResetsAfterExpiry(): void
    {
        $store = new ApcuRateLimitStore('test_apcu_rl_b_');

        $first = $store->increment('ip2', 1);
        $this->assertEquals(1, $first['count']);

        $second = $store->increment('ip2', 1);
        $this->assertEquals(2, $second['count']);

        // Esperamos a que expire la ventana de 1 segundo.
        \usleep(1100000);

        $afterExpiry = $store->increment('ip2', 1);
        $this->assertEquals(1, $afterExpiry['count']);
        $this->assertGreaterThan($second['reset'], $afterExpiry['reset']);
    }

    public function testConsecutiveIncrementsDoNotLoseCounts(): void
    {
        $store = new ApcuRateLimitStore('test_apcu_rl_c_');

        // No es una prueba real de concurrencia multi-proceso, pero
        // verifica que llamadas consecutivas incrementan atómicamente
        // sin perder ninguna (lo que sí ocurría con el patrón
        // fetch+store previo bajo condiciones de carrera reales).
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = $store->increment('ip3', 60);
        }

        $counts = \array_map(fn(array $r) => $r['count'], $results);
        $this->assertEquals(\range(1, 10), $counts);
    }
}
