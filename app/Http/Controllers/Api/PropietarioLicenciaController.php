<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropietarioLicencia;
use App\Models\Propietario;
use App\Models\Licencia;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropietarioLicenciaController extends Controller
{
    /**
     * Listar todas las licencias asignadas (admin)
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_PROPIETARIO_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver asignaciones de licencias'
                ], 403);
            }

            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');

            $query = PropietarioLicencia::with(['propietario', 'licencia']);

            if ($search) {
                $query->whereHas('propietario', function($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('licencia', function($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%");
                });
            }

            // Filtrar por estado
            if ($request->has('estado')) {
                $query->where('estado', $request->estado);
            }

            $asignaciones = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $asignaciones->items(),
                'pagination' => [
                    'current_page' => $asignaciones->currentPage(),
                    'per_page' => $asignaciones->perPage(),
                    'total' => $asignaciones->total(),
                    'last_page' => $asignaciones->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error index propietario-licencias: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las asignaciones'
            ], 500);
        }
    }

    /**
     * Mostrar una asignación específica
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_PROPIETARIO_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver esta asignación'
                ], 403);
            }

            $asignacion = PropietarioLicencia::with(['propietario', 'licencia'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $asignacion
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Asignación no encontrada'
            ], 404);
        }
    }

    /**
     * Asignar una licencia a un propietario (admin)
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('ASIGNAR_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para asignar licencias'
                ], 403);
            }

            $request->validate([
                'propietario_id' => 'required|exists:propietarios,id',
                'licencia_id' => 'required|exists:licencias,id',
                'fecha_inicio' => 'required|date',
                'fecha_expiracion' => 'required|date|after:fecha_inicio',
                'estado' => 'nullable|in:ACTIVA,INACTIVA,CANCELADA,EXPIRADA,PENDIENTE',
                'monto_pagado' => 'nullable|numeric|min:0',
                'metodo_pago' => 'nullable|string|max:50'
            ]);

            // Verificar si el propietario ya tiene una licencia activa
            $licenciaActiva = PropietarioLicencia::where('propietario_id', $request->propietario_id)
                ->where('estado', 'ACTIVA')
                ->where('fecha_expiracion', '>', Carbon::now())
                ->first();

            if ($licenciaActiva) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este propietario ya tiene una licencia activa. No se puede asignar otra.'
                ], 400);
            }

            $data = $request->all();
            $data['estado'] = $request->estado ?? 'ACTIVA';

            $asignacion = PropietarioLicencia::create($data);

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ASIGNAR_LICENCIA',
                    'propietario_licencia',
                    $asignacion->id,
                    "Licencia asignada al propietario ID: {$request->propietario_id}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Licencia asignada correctamente',
                'data' => $asignacion
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error store propietario-licencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar la licencia'
            ], 500);
        }
    }

    /**
     * Actualizar una asignación
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_PROPIETARIO_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para editar esta asignación'
                ], 403);
            }

            $asignacion = PropietarioLicencia::findOrFail($id);

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_expiracion' => 'sometimes|date|after:fecha_inicio',
                'estado' => 'sometimes|in:ACTIVA,INACTIVA,CANCELADA,EXPIRADA,PENDIENTE',
                'monto_pagado' => 'nullable|numeric|min:0',
                'metodo_pago' => 'nullable|string|max:50'
            ]);

            $asignacion->update($request->all());

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'EDITAR_PROPIETARIO_LICENCIA',
                    'propietario_licencia',
                    $asignacion->id,
                    "Licencia actualizada"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Asignación actualizada correctamente',
                'data' => $asignacion
            ]);

        } catch (\Exception $e) {
            Log::error('Error update propietario-licencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la asignación'
            ], 500);
        }
    }

    /**
     * Eliminar una asignación
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('ELIMINAR_PROPIETARIO_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar esta asignación'
                ], 403);
            }

            $asignacion = PropietarioLicencia::findOrFail($id);
            $asignacion->delete();

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ELIMINAR_PROPIETARIO_LICENCIA',
                    'propietario_licencia',
                    $id,
                    "Licencia eliminada del propietario ID: {$asignacion->propietario_id}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Asignación eliminada correctamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error destroy propietario-licencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la asignación'
            ], 500);
        }
    }

    /**
     * Obtener licencias activas de un propietario
     */
    public function propietarioActivas(Request $request, $propietarioId)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_PROPIETARIO_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver estas licencias'
                ], 403);
            }

            $propietario = Propietario::findOrFail($propietarioId);

            $licencias = PropietarioLicencia::with('licencia')
                ->where('propietario_id', $propietarioId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($item) {
                    $diasRestantes = 0;
                    if ($item->fecha_expiracion) {
                        $diasRestantes = max(0, Carbon::now()->diffInDays($item->fecha_expiracion, false));
                    }

                    return [
                        'id' => $item->id,
                        'licencia' => $item->licencia,
                        'estado' => $item->estado,
                        'fecha_inicio' => $item->fecha_inicio,
                        'fecha_expiracion' => $item->fecha_expiracion,
                        'dias_restantes' => $diasRestantes,
                        'monto_pagado' => $item->monto_pagado,
                        'metodo_pago' => $item->metodo_pago,
                        'created_at' => $item->created_at
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $licencias,
                'propietario' => [
                    'id' => $propietario->id,
                    'nombre' => $propietario->nombre,
                    'email' => $propietario->email
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error propietarioActivas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las licencias del propietario'
            ], 500);
        }
    }

    /**
     * Renovar una licencia
     */
    public function renovar(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('ASIGNAR_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para renovar licencias'
                ], 403);
            }

            $asignacion = PropietarioLicencia::with('licencia')->findOrFail($id);

            $licencia = $asignacion->licencia;
            $dias = $licencia->tipo === 'MENSUAL' ? 30 : 365;

            $nuevaFechaExpiracion = Carbon::now()->addDays($dias);

            $asignacion->update([
                'fecha_inicio' => Carbon::now(),
                'fecha_expiracion' => $nuevaFechaExpiracion,
                'estado' => 'ACTIVA'
            ]);

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'RENOVAR_LICENCIA',
                    'propietario_licencia',
                    $asignacion->id,
                    "Licencia renovada hasta: {$nuevaFechaExpiracion}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Licencia renovada correctamente',
                'data' => [
                    'id' => $asignacion->id,
                    'fecha_inicio' => $asignacion->fecha_inicio,
                    'fecha_expiracion' => $asignacion->fecha_expiracion,
                    'dias_agregados' => $dias
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error renovar licencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al renovar la licencia'
            ], 500);
        }
    }

    /**
     * Cancelar una licencia
     */
    public function cancelar(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_PROPIETARIO_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para cancelar licencias'
                ], 403);
            }

            $asignacion = PropietarioLicencia::findOrFail($id);

            $motivo = $request->input('motivo', 'Cancelada por administrador');

            $asignacion->update([
                'estado' => 'CANCELADA'
            ]);

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'CANCELAR_LICENCIA',
                    'propietario_licencia',
                    $asignacion->id,
                    "Licencia cancelada. Motivo: {$motivo}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Licencia cancelada correctamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error cancelar licencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar la licencia'
            ], 500);
        }
    }

    /**
     * Verificar licencias expiradas y actualizar estado (cron job)
     */
    public function verificarExpiradas()
    {
        try {
            $expiradas = PropietarioLicencia::where('estado', 'ACTIVA')
                ->where('fecha_expiracion', '<', Carbon::now())
                ->update(['estado' => 'EXPIRADA']);

            return response()->json([
                'success' => true,
                'message' => "{$expiradas} licencias marcadas como expiradas"
            ]);

        } catch (\Exception $e) {
            Log::error('Error verificar expiradas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar licencias expiradas'
            ], 500);
        }
    }

    /**
     * Estadísticas de licencias
     */
    public function estadisticas(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_PROPIETARIO_LICENCIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver estadísticas'
                ], 403);
            }

            $stats = [
                'total' => PropietarioLicencia::count(),
                'activas' => PropietarioLicencia::where('estado', 'ACTIVA')
                    ->where('fecha_expiracion', '>', Carbon::now())
                    ->count(),
                'expiradas' => PropietarioLicencia::where('estado', 'EXPIRADA')->count(),
                'canceladas' => PropietarioLicencia::where('estado', 'CANCELADA')->count(),
                'pendientes' => PropietarioLicencia::where('estado', 'PENDIENTE')->count(),
                'por_vencer' => PropietarioLicencia::where('estado', 'ACTIVA')
                    ->whereBetween('fecha_expiracion', [Carbon::now(), Carbon::now()->addDays(7)])
                    ->count(),
                'total_mensuales' => PropietarioLicencia::whereHas('licencia', function($q) {
                    $q->where('tipo', 'MENSUAL');
                })->count(),
                'total_anuales' => PropietarioLicencia::whereHas('licencia', function($q) {
                    $q->where('tipo', 'ANUAL');
                })->count(),
                'ingresos_totales' => PropietarioLicencia::where('estado', 'ACTIVA')
                    ->sum('monto_pagado') ?? 0
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error estadisticas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas'
            ], 500);
        }
    }

    // POST /api/propietario-licencias/{id}/cambiar-plan
public function cambiarPlan(Request $request, $id)
{
    $nuevoPlan = Licencia::findOrFail($request->plan_id);
    $licenciaActual = PropietarioLicencia::with('propietario.restaurantes')->findOrFail($id);
    
    $restaurantesActuales = $licenciaActual->propietario->restaurantes->count();
    $maxSucursales = $nuevoPlan->max_restaurantes;
    
    // Si el nuevo plan tiene menos sucursales
    if ($maxSucursales && $restaurantesActuales > $maxSucursales) {
        $excedente = $restaurantesActuales - $maxSucursales;
        
        return response()->json([
            'success' => false,
            'message' => "El nuevo plan permite máximo {$maxSucursales} sucursales. Tienes {$restaurantesActuales}.",
            'requires_selection' => true,
            'sucursales_actuales' => $licenciaActual->propietario->restaurantes,
            'sucursales_a_eliminar' => $excedente
        ], 400);
    }
    
    // Actualizar licencia
    $licenciaActual->update([
        'licencia_id' => $nuevoPlan->id,
        'costo_personalizado' => $request->costo_personalizado ?? null
    ]);
    
    return response()->json(['success' => true, 'message' => 'Plan actualizado']);
}
// POST /api/propietario-licencias/{id}/confirmar-cambio
public function confirmarCambio(Request $request, $id)
{
    $licencia = PropietarioLicencia::findOrFail($id);
    
    // Guardar sucursales eliminadas
    $licencia->update([
        'sucursales_eliminadas' => json_encode($request->sucursales_eliminadas)
    ]);
    
    // Eliminar lógicamente las sucursales seleccionadas
    Restaurante::whereIn('id', $request->sucursales_eliminadas)
        ->update(['deleted_at' => now(), 'activo' => false]);
    
    // Cambiar matriz si se seleccionó una nueva
    if ($request->nueva_matriz_id) {
        // Quitar matriz actual
        Restaurante::where('propietario_id', $licencia->propietario_id)
            ->update(['es_matriz' => false]);
        
        // Asignar nueva matriz
        Restaurante::where('id', $request->nueva_matriz_id)
            ->update(['es_matriz' => true]);
    }
    
    return response()->json(['success' => true]);
}

}