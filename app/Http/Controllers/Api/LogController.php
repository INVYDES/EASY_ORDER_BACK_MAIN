<?php
// app/Http/Controllers/Api/LogController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Log;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Listar logs con paginación y filtros
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // Verificar permiso
            if (!$user->hasPermission('VER_LOGS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver logs'
                ], 403);
            }

            // Parámetros de paginación (por defecto 20 logs por página)
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            
            // Validar que per_page no sea excesivo
            $perPage = min($perPage, 100); // Máximo 100 registros por página

            // Construir query base según el rol
            $query = Log::with('user');
            
            if ($user->roles()->where('nombre', 'PROPIETARIO')->exists()) {
                // Propietario: ve logs de todos sus usuarios
                $query->whereHas('user', function ($q) use ($user) {
                    $q->where('propietario_id', $user->propietario_id);
                });
            } else {
                // Otros usuarios: solo sus propios logs
                $query->where('user_id', $user->id);
            }

            // APLICAR FILTROS

            // Filtro por acción
            if ($request->has('accion') && !empty($request->accion)) {
                $query->where('accion', 'like', '%' . $request->accion . '%');
            }

            // Filtro por tabla afectada
            if ($request->has('tabla') && !empty($request->tabla)) {
                $query->where('tabla_afectada', 'like', '%' . $request->tabla . '%');
            }

            // Filtro por usuario específico (solo para propietarios)
            if ($user->roles()->where('nombre', 'PROPIETARIO')->exists() && $request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            // Filtro por rango de fechas
            if ($request->has('fecha_desde')) {
                $query->whereDate('created_at', '>=', $request->fecha_desde);
            }
            
            if ($request->has('fecha_hasta')) {
                $query->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            // Filtro por fecha específica
            if ($request->has('fecha')) {
                $query->whereDate('created_at', $request->fecha);
            }

            // Filtro por IP
            if ($request->has('ip') && !empty($request->ip)) {
                $query->where('ip_address', 'like', '%' . $request->ip . '%');
            }

            // Filtro por búsqueda general (en descripción)
            if ($request->has('buscar') && !empty($request->buscar)) {
                $buscar = $request->buscar;
                $query->where(function($q) use ($buscar) {
                    $q->where('descripcion', 'like', '%' . $buscar . '%')
                      ->orWhere('accion', 'like', '%' . $buscar . '%')
                      ->orWhere('tabla_afectada', 'like', '%' . $buscar . '%');
                });
            }

            // Ordenamiento
            $orderBy = $request->get('order_by', 'created_at');
            $orderDir = $request->get('order_dir', 'desc');
            
            $allowedOrderFields = ['id', 'accion', 'tabla_afectada', 'created_at', 'user_id'];
            if (in_array($orderBy, $allowedOrderFields)) {
                $query->orderBy($orderBy, $orderDir === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Ejecutar paginación
            $logs = $query->paginate($perPage, ['*'], 'page', $page);

            // Transformar datos para respuesta
            $logsData = $logs->map(function($log) {
                return [
                    'id' => $log->id,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'username' => $log->user->username,
                        'email' => $log->user->email
                    ] : null,
                    'accion' => $log->accion,
                    'tabla_afectada' => $log->tabla_afectada,
                    'registro_id' => $log->registro_id,
                    'descripcion' => $log->descripcion,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at,
                    'created_at_formateado' => $log->created_at ? $log->created_at->format('d/m/Y H:i:s') : null,
                    'created_at_humano' => $log->created_at ? $log->created_at->diffForHumans() : null
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Logs obtenidos correctamente',
                'data' => $logsData,
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'last_page' => $logs->lastPage(),
                    'from' => $logs->firstItem(),
                    'to' => $logs->lastItem(),
                    'next_page_url' => $logs->nextPageUrl(),
                    'prev_page_url' => $logs->previousPageUrl(),
                    'has_more_pages' => $logs->hasMorePages()
                ],
                'filters' => [
                    'accion' => $request->accion ?? null,
                    'tabla' => $request->tabla ?? null,
                    'user_id' => $request->user_id ?? null,
                    'fecha_desde' => $request->fecha_desde ?? null,
                    'fecha_hasta' => $request->fecha_hasta ?? null,
                    'ip' => $request->ip ?? null,
                    'buscar' => $request->buscar ?? null
                ],
                'resumen' => [
                    'total_en_pagina' => $logs->count(),
                    'acciones_unicas' => $logs->pluck('accion')->unique()->values()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener logs',
                'error' => $e->getMessage(),
                'trace' => env('APP_DEBUG') ? $e->getTrace() : null
            ], 500);
        }
    }

    /**
     * Obtener un log específico
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_LOGS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver logs'
                ], 403);
            }

            $log = Log::with('user')->findOrFail($id);
            
            // Verificar permisos según el rol
            if ($user->roles()->where('nombre', 'PROPIETARIO')->exists()) {
                if ($log->user && $log->user->propietario_id != $user->propietario_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tienes permiso para ver este log'
                    ], 403);
                }
            } else if ($log->user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver este log'
                ], 403);
            }

            // Transformar datos
            $logData = [
                'id' => $log->id,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'username' => $log->user->username,
                    'email' => $log->user->email
                ] : null,
                'accion' => $log->accion,
                'tabla_afectada' => $log->tabla_afectada,
                'registro_id' => $log->registro_id,
                'descripcion' => $log->descripcion,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'created_at_formateado' => $log->created_at ? $log->created_at->format('d/m/Y H:i:s') : null,
                'created_at_humano' => $log->created_at ? $log->created_at->diffForHumans() : null
            ];

            return response()->json([
                'success' => true,
                'data' => $logData
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Log no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener log',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener acciones únicas (para filtros)
     */
    public function acciones(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_LOGS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $acciones = Log::select('accion')
                ->distinct()
                ->orderBy('accion')
                ->get()
                ->pluck('accion');

            return response()->json([
                'success' => true,
                'data' => $acciones
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener acciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener tablas afectadas únicas (para filtros)
     */
    public function tablas(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_LOGS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $tablas = Log::select('tabla_afectada')
                ->distinct()
                ->whereNotNull('tabla_afectada')
                ->orderBy('tabla_afectada')
                ->get()
                ->pluck('tabla_afectada');

            return response()->json([
                'success' => true,
                'data' => $tablas
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tablas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar logs antiguos (solo para propietarios)
     */
    public function limpiar(Request $request)
    {
        try {
            $user = $request->user();
            
            // Solo propietarios pueden limpiar logs
            if (!$user->roles()->where('nombre', 'PROPIETARIO')->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para esta acción'
                ], 403);
            }

            $request->validate([
                'dias' => 'required|integer|min:1|max:365'
            ]);

            $fechaLimite = now()->subDays($request->dias);
            
            $logsEliminados = Log::whereHas('user', function($q) use ($user) {
                    $q->where('propietario_id', $user->propietario_id);
                })
                ->where('created_at', '<', $fechaLimite)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "Se eliminaron {$logsEliminados} logs anteriores a {$request->dias} días"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al limpiar logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}