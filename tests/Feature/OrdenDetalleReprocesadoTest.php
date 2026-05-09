<?php

use App\Models\OrdenDetalle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Tests\IntegrationTestCase;

use Tests\TestCase;

class OrdenDetalleReprocesadoTest extends TestCase
{
    /** @test */
    public function it_casts_reprocesado_as_boolean_and_has_default_false()
    {
        $detalle = OrdenDetalle::factory()->make();
        $this->assertFalse($detalle->reprocesado);
    }
}
