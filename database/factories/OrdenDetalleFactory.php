<?php

namespace Database\Factories;

use App\Models\OrdenDetalle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenDetalleFactory extends Factory
{
    protected $model = OrdenDetalle::class;

    public function definition(): array
    {
        return [
            "orden_id" => 1,
            "producto_id" => 1,
            "paquete_id" => null,
            "cantidad" => $this->faker->numberBetween(1,5),
            "precio_unitario" => $this->faker->randomFloat(2,1,100),
            "subtotal" => null,
            "notas" => null,
            "nom_comensal" => null,
            "estado_preparacion" => null,
            "reprocesado" => false,
        ];
    }
}
