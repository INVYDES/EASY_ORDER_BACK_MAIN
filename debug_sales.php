<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Orden;
use App\Models\OrdenDetalle;
use Illuminate\Support\Facades\DB;

$hoy = today()->format('Y-m-d');
$restauranteId = 1; // Assuming 1 or we can find it
$restauranteActivo = \App\Models\Restaurante::first();
if ($restauranteActivo) {
    $restauranteId = $restauranteActivo->id;
}

$queryHoy = Orden::where('restaurante_id', $restauranteId)
    ->where('estado', 'CERRADA')
    ->whereDate('created_at', $hoy);

$ventasHoy = (float) ((clone $queryHoy)->sum(DB::raw('total - COALESCE(propina, 0)')) ?? 0);
$total = (float) ((clone $queryHoy)->sum('total') ?? 0);
$propina = (float) ((clone $queryHoy)->sum('propina') ?? 0);

$costoProductoHoy = (float) DB::table('orden_detalles')
    ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
    ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
    ->where('ordenes.restaurante_id', $restauranteId)
    ->where('ordenes.estado', 'CERRADA')
    ->whereDate('ordenes.created_at', $hoy)
    ->selectRaw('SUM(orden_detalles.cantidad * COALESCE(productos.costo, 0)) as total_costo')
    ->value('total_costo') ?? 0;

$utilidadBrutaHoy = $ventasHoy - $costoProductoHoy;

echo "Hoy: $hoy\n";
echo "Ventas Hoy (total - propina): $ventasHoy\n";
echo "Total Sum: $total\n";
echo "Propina Sum: $propina\n";
echo "Costo Producto Hoy: $costoProductoHoy\n";
echo "Utilidad Bruta: $utilidadBrutaHoy\n";

// Let's also check if there are PAGADA orders
$pagadas = Orden::where('restaurante_id', $restauranteId)
    ->where('estado', 'PAGADA')
    ->whereDate('created_at', $hoy)->count();

echo "PAGADA Orders: $pagadas\n";

// Check if any order details have negative values or weird things
$detalles = DB::table('orden_detalles')
    ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
    ->where('ordenes.restaurante_id', $restauranteId)
    ->where('ordenes.estado', 'CERRADA')
    ->whereDate('ordenes.created_at', $hoy)
    ->select('orden_detalles.subtotal', 'orden_detalles.cantidad', 'orden_detalles.precio_unitario')
    ->get();

$sumSubtotales = 0;
foreach ($detalles as $d) {
    $sumSubtotales += $d->subtotal;
}
echo "Sum of subtotales directly from detalles: $sumSubtotales\n";

// Also check nomina (mano_obra)
$manoObra = DB::table('nomina_diaria')
    ->where('restaurante_id', $restauranteId)
    ->whereDate('fecha', $hoy)
    ->sum('total_mano_obra');
echo "Mano de obra (Nomina Diaria): " . ($manoObra ?: 0) . "\n";
