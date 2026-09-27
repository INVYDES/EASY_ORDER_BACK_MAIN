<?php

use App\Models\OrdenDetalle;
use Tests\TestCase;

class OrdenDetalleReprocesadoTest extends TestCase
{
    public function test_it_casts_reprocesado_as_boolean_and_has_default_false(): void
    {
        $detalle = OrdenDetalle::factory()->make();

        $this->assertFalse($detalle->reprocesado);
    }
}
