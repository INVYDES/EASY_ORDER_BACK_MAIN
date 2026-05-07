<?php
// app/Http/Controllers/Api/HorarioController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HorarioEmpleado;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class HorarioController extends Controller
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
     * Días de la semana
     */
    private function getDiasSemana(): array
    {
        return [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
        ];
    }

    /**
     * Listar horarios de empleados del restaurante
     * GET /api/horarios
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $query = HorarioEmpleado::with('user')
                ->where('restaurante_id', $restauranteId);

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('dia_semana')) {
                $query->where('dia_semana', $request->dia_semana);
            }

            if ($request->filled('activo')) {
                $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
            }

            $horarios = $query->orderBy('dia_semana')->orderBy('hora_entrada')->get();

            $diasSemana = $this->getDiasSemana();

            return response()->json([
                'success' => true,
                'data' => $horarios->map(fn($h) => [
                    'id' => $h->id,
                    'user_id' => $h->user_id,
                    'empleado' => $h->user ? $h->user->name : null,
                    'dia_semana' => $h->dia_semana,
                    'dia_nombre' => $diasSemana[$h->dia_semana] ?? 'Desconocido',
                    'hora_entrada' => $h->hora_entrada,
                    'hora_salida' => $h->hora_salida,
                    'activo' => (bool) $h->activo,
                    'created_at' => $h->created_at,
                    'updated_at' => $h->updated_at,
                ]),
                'dias_semana' => $diasSemana,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Crear horario para un empleado
     * POST /api/horarios
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'dia_semana' => 'required|integer|min:1|max:7',
                'hora_entrada' => 'required|date_format:H:i',
                'hora_salida' => 'required|date_format:H:i|after:hora_entrada',
                'activo' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // Verificar que el usuario existe y pertenece al restaurante
            $empleado = User::where('id', $request->user_id)
                ->whereHas('restaurantes', fn($q) => $q->where('restaurantes.id', $restauranteId))
                ->first();

            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado en este restaurante'], 404);
            }

            // Verificar si ya existe horario para ese día
            $existente = HorarioEmpleado::where('user_id', $request->user_id)
                ->where('restaurante_id', $restauranteId)
                ->where('dia_semana', $request->dia_semana)
                ->first();

            if ($existente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un horario para este empleado en este día',
                    'data' => $existente
                ], 409);
            }

            $horario = HorarioEmpleado::create([
                'user_id' => $request->user_id,
                'restaurante_id' => $restauranteId,
                'dia_semana' => $request->dia_semana,
                'hora_entrada' => $request->hora_entrada,
                'hora_salida' => $request->hora_salida,
                'activo' => $request->boolean('activo', true),
            ]);

            $diasSemana = $this->getDiasSemana();

            return response()->json([
                'success' => true,
                'message' => 'Horario creado correctamente',
                'data' => [
                    'id' => $horario->id,
                    'user_id' => $horario->user_id,
                    'dia_semana' => $horario->dia_semana,
                    'dia_nombre' => $diasSemana[$horario->dia_semana],
                    'hora_entrada' => $horario->hora_entrada,
                    'hora_salida' => $horario->hora_salida,
                    'activo' => (bool) $horario->activo,
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Ver un horario específico
     * GET /api/horarios/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $horario = HorarioEmpleado::with('user')
                ->where('restaurante_id', $restauranteId)
                ->where('id', $id)
                ->firstOrFail();

            $diasSemana = $this->getDiasSemana();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $horario->id,
                    'user_id' => $horario->user_id,
                    'empleado' => $horario->user ? $horario->user->name : null,
                    'dia_semana' => $horario->dia_semana,
                    'dia_nombre' => $diasSemana[$horario->dia_semana],
                    'hora_entrada' => $horario->hora_entrada,
                    'hora_salida' => $horario->hora_salida,
                    'activo' => (bool) $horario->activo,
                    'created_at' => $horario->created_at,
                    'updated_at' => $horario->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Horario no encontrado'], 404);
        }
    }

    /**
     * Actualizar horario
     * PUT /api/horarios/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $horario = HorarioEmpleado::where('restaurante_id', $restauranteId)
                ->where('id', $id)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'dia_semana' => 'sometimes|integer|min:1|max:7',
                'hora_entrada' => 'sometimes|date_format:H:i',
                'hora_salida' => 'sometimes|date_format:H:i|after:hora_entrada',
                'activo' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // Si cambia el día, verificar que no haya duplicado
            if ($request->has('dia_semana') && $request->dia_semana != $horario->dia_semana) {
                $existente = HorarioEmpleado::where('user_id', $horario->user_id)
                    ->where('restaurante_id', $restauranteId)
                    ->where('dia_semana', $request->dia_semana)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existente) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ya existe un horario para este empleado en este día'
                    ], 409);
                }
            }

            $horario->update($request->only([
                'dia_semana', 'hora_entrada', 'hora_salida', 'activo'
            ]));

            $diasSemana = $this->getDiasSemana();

            return response()->json([
                'success' => true,
                'message' => 'Horario actualizado correctamente',
                'data' => [
                    'id' => $horario->id,
                    'user_id' => $horario->user_id,
                    'dia_semana' => $horario->dia_semana,
                    'dia_nombre' => $diasSemana[$horario->dia_semana],
                    'hora_entrada' => $horario->hora_entrada,
                    'hora_salida' => $horario->hora_salida,
                    'activo' => (bool) $horario->activo,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar horario
     * DELETE /api/horarios/{id}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $horario = HorarioEmpleado::where('restaurante_id', $restauranteId)
                ->where('id', $id)
                ->firstOrFail();

            $horario->delete();

            return response()->json([
                'success' => true,
                'message' => 'Horario eliminado correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener horarios de un empleado específico
     * GET /api/horarios/empleado/{empleadoId}
     */
    public function porEmpleado(Request $request, $empleadoId)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $empleado = User::where('id', $empleadoId)
                ->whereHas('restaurantes', fn($q) => $q->where('restaurantes.id', $restauranteId))
                ->first();

            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $horarios = HorarioEmpleado::where('user_id', $empleadoId)
                ->where('restaurante_id', $restauranteId)
                ->orderBy('dia_semana')
                ->get();

            $diasSemana = $this->getDiasSemana();

            // Crear array con los 7 días (mostrar días sin horario como null)
            $horariosPorDia = [];
            for ($i = 1; $i <= 7; $i++) {
                $horario = $horarios->firstWhere('dia_semana', $i);
                $horariosPorDia[] = [
                    'dia_semana' => $i,
                    'dia_nombre' => $diasSemana[$i],
                    'tiene_horario' => !is_null($horario),
                    'id' => $horario ? $horario->id : null,
                    'hora_entrada' => $horario ? $horario->hora_entrada : null,
                    'hora_salida' => $horario ? $horario->hora_salida : null,
                    'activo' => $horario ? (bool) $horario->activo : null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'empleado_id' => $empleadoId,
                    'empleado_nombre' => $empleado->name,
                    'horarios' => $horariosPorDia,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Copiar horario de una semana a otra (útil para asignar el mismo horario a varios empleados)
     * POST /api/horarios/copiar
     */
    public function copiar(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $validator = Validator::make($request->all(), [
                'origen_user_id' => 'required|exists:users,id',
                'destino_user_id' => 'required|exists:users,id',
                'sobrescribir' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // Verificar que los empleados existen en el restaurante
            $origen = User::where('id', $request->origen_user_id)
                ->whereHas('restaurantes', fn($q) => $q->where('restaurantes.id', $restauranteId))
                ->first();

            $destino = User::where('id', $request->destino_user_id)
                ->whereHas('restaurantes', fn($q) => $q->where('restaurantes.id', $restauranteId))
                ->first();

            if (!$origen || !$destino) {
                return response()->json(['success' => false, 'message' => 'Empleado(s) no encontrado(s)'], 404);
            }

            $horariosOrigen = HorarioEmpleado::where('user_id', $request->origen_user_id)
                ->where('restaurante_id', $restauranteId)
                ->get();

            $copiados = 0;
            $omitidos = 0;

            foreach ($horariosOrigen as $horario) {
                $existente = HorarioEmpleado::where('user_id', $request->destino_user_id)
                    ->where('restaurante_id', $restauranteId)
                    ->where('dia_semana', $horario->dia_semana)
                    ->first();

                if ($existente && !$request->boolean('sobrescribir')) {
                    $omitidos++;
                    continue;
                }

                if ($existente) {
                    $existente->update([
                        'hora_entrada' => $horario->hora_entrada,
                        'hora_salida' => $horario->hora_salida,
                        'activo' => $horario->activo,
                    ]);
                } else {
                    HorarioEmpleado::create([
                        'user_id' => $request->destino_user_id,
                        'restaurante_id' => $restauranteId,
                        'dia_semana' => $horario->dia_semana,
                        'hora_entrada' => $horario->hora_entrada,
                        'hora_salida' => $horario->hora_salida,
                        'activo' => $horario->activo,
                    ]);
                }
                $copiados++;
            }

            return response()->json([
                'success' => true,
                'message' => "Horarios copiados: {$copiados} creados/actualizados, {$omitidos} omitidos",
                'data' => [
                    'copiados' => $copiados,
                    'omitidos' => $omitidos,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}