<?php
// app/Http/Controllers/Api/NominaDetalleController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Nomina;
use App\Models\NominaDetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NominaDetalleController extends Controller
{
    /**
     * Obtener el ID del restaurante activo
     */
    private function getRestauranteId($user)
    {
        $headerId = request()->header('X-Restaurante-Id');
        $userRestId = is_object($user->restaurante_activo) ? $user->restaurante_activo->id : $user->restaurante_activo;
        $requestId = request()->restaurante_id;

        return (!empty($headerId)) ? $headerId : ((!empty($userRestId)) ? $userRestId : $requestId);
    }

    /**
     * Listar detalles de una nómina
     * GET /api/nominas/{nominaId}/detalles
     */
    public function index(Request $request, $nominaId)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('restaurante_id', $restauranteId)
                ->where('id', $nominaId)
                ->firstOrFail();

            $detalles = NominaDetalle::where('nomina_id', $nominaId)
                ->orderBy('created_at', 'desc')
                ->get();

            $resumen = [
                'total_devengado' => round($detalles->where('tipo', 'devengado')->sum('monto'), 2),
                'total_deduccion' => round($detalles->where('tipo', 'deduccion')->sum('monto'), 2),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'nomina' => [
                        'id' => $nomina->id,
                        'empleado' => $nomina->user ? $nomina->user->name : null,
                        'periodo_inicio' => $nomina->periodo_inicio,
                        'periodo_fin' => $nomina->periodo_fin,
                        'pago_total' => (float) $nomina->pago_total,
                        'estado' => $nomina->estado,
                    ],
                    'detalles' => $detalles->map(fn($d) => [
                        'id' => $d->id,
                        'concepto' => $d->concepto,
                        'tipo' => $d->tipo,
                        'monto' => (float) $d->monto,
                        'monto_formateado' => '$' . number_format($d->monto, 2),
                        'descripcion' => $d->descripcion,
                        'created_at' => $d->created_at,
                    ]),
                    'resumen' => $resumen,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Agregar detalle a una nómina
     * POST /api/nominas/{nominaId}/detalles
     */
    public function store(Request $request, $nominaId)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('restaurante_id', $restauranteId)
                ->where('id', $nominaId)
                ->firstOrFail();

            if ($nomina->estado !== 'PENDIENTE') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden agregar detalles a una nómina que no está pendiente'
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'concepto' => 'required|string|max:100',
                'tipo' => 'required|in:devengado,deduccion',
                'monto' => 'required|numeric|min:0',
                'descripcion' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $detalle = NominaDetalle::create([
                'nomina_id' => $nomina->id,
                'concepto' => $request->concepto,
                'tipo' => $request->tipo,
                'monto' => $request->monto,
                'descripcion' => $request->descripcion,
            ]);

            // Recalcular totales de la nómina
            $totalDevengado = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('tipo', 'devengado')
                ->sum('monto');

            $totalDeduccion = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('tipo', 'deduccion')
                ->sum('monto');

            $nomina->comision_ventas = $totalDevengado;
            $nomina->descuentos = $totalDeduccion;
            $nomina->pago_total = $nomina->salario_base + $totalDevengado + $nomina->bonos - ($nomina->descuentos + $totalDeduccion);
            $nomina->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Detalle agregado correctamente',
                'data' => [
                    'id' => $detalle->id,
                    'concepto' => $detalle->concepto,
                    'tipo' => $detalle->tipo,
                    'monto' => (float) $detalle->monto,
                    'descripcion' => $detalle->descripcion,
                    'created_at' => $detalle->created_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Ver un detalle específico
     * GET /api/nominas/{nominaId}/detalles/{detalleId}
     */
    public function show(Request $request, $nominaId, $detalleId)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('restaurante_id', $restauranteId)
                ->where('id', $nominaId)
                ->firstOrFail();

            $detalle = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $detalle->id,
                    'concepto' => $detalle->concepto,
                    'tipo' => $detalle->tipo,
                    'monto' => (float) $detalle->monto,
                    'descripcion' => $detalle->descripcion,
                    'created_at' => $detalle->created_at,
                    'updated_at' => $detalle->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Detalle no encontrado'], 404);
        }
    }

    /**
     * Actualizar un detalle
     * PUT /api/nominas/{nominaId}/detalles/{detalleId}
     */
    public function update(Request $request, $nominaId, $detalleId)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('restaurante_id', $restauranteId)
                ->where('id', $nominaId)
                ->firstOrFail();

            if ($nomina->estado !== 'PENDIENTE') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden modificar detalles de una nómina que no está pendiente'
                ], 400);
            }

            $detalle = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'concepto' => 'sometimes|string|max:100',
                'tipo' => 'sometimes|in:devengado,deduccion',
                'monto' => 'sometimes|numeric|min:0',
                'descripcion' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $detalle->update($request->only(['concepto', 'tipo', 'monto', 'descripcion']));

            // Recalcular totales
            $totalDevengado = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('tipo', 'devengado')
                ->sum('monto');

            $totalDeduccion = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('tipo', 'deduccion')
                ->sum('monto');

            $nomina->comision_ventas = $totalDevengado;
            $nomina->descuentos = $totalDeduccion;
            $nomina->pago_total = $nomina->salario_base + $totalDevengado + $nomina->bonos - ($nomina->descuentos + $totalDeduccion);
            $nomina->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Detalle actualizado correctamente',
                'data' => $detalle->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar un detalle
     * DELETE /api/nominas/{nominaId}/detalles/{detalleId}
     */
    public function destroy(Request $request, $nominaId, $detalleId)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('restaurante_id', $restauranteId)
                ->where('id', $nominaId)
                ->firstOrFail();

            if ($nomina->estado !== 'PENDIENTE') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden eliminar detalles de una nómina que no está pendiente'
                ], 400);
            }

            $detalle = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            DB::beginTransaction();

            $detalle->delete();

            // Recalcular totales
            $totalDevengado = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('tipo', 'devengado')
                ->sum('monto');

            $totalDeduccion = NominaDetalle::where('nomina_id', $nomina->id)
                ->where('tipo', 'deduccion')
                ->sum('monto');

            $nomina->comision_ventas = $totalDevengado;
            $nomina->descuentos = $totalDeduccion;
            $nomina->pago_total = $nomina->salario_base + $totalDevengado + $nomina->bonos - ($nomina->descuentos + $totalDeduccion);
            $nomina->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Detalle eliminado correctamente',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}