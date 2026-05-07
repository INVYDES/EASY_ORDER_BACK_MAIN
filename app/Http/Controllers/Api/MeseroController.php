<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Restaurante;
use App\Models\MesaMesero;
use App\Models\Orden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MeseroController extends Controller
{
    /**
     * Obtener el ID del restaurante activo de forma consistente
     */
    private function getRestauranteId(Request $request, $user): ?int
    {
        $headerId = $request->header('X-Restaurante-Id');
        $userRestId = is_object($user->restaurante_activo) 
            ? $user->restaurante_activo->id 
            : $user->restaurante_activo;
        $requestId = $request->restaurante_id;

        $restauranteId = !empty($headerId) 
            ? $headerId 
            : (!empty($userRestId) ? $userRestId : $requestId);

        return !empty($restauranteId) ? (int) $restauranteId : null;
    }

    /**
     * Verificar si un usuario es administrador o propietario
     */
    private function esAdminOPropietario(User $user): bool
    {
        // Verificar por ID de rol (1=Propietario, 2=Admin)
        if ($user->roles()->whereIn('roles.id', [1, 2])->exists()) {
            return true;
        }
        
        // Verificar por nombre de rol
        return $user->roles()->whereRaw('LOWER(roles.nombre) = ?', ['admin'])->exists();
    }

    /**
     * Obtener lista de meseros con sus mesas asignadas
     * GET /api/meseros
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = $this->getRestauranteId($request, $user);
            
            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            // Buscar usuarios con rol de mesero (id=3 o nombre='MESERO')
            $meseros = User::whereHas('roles', function($q) {
                    $q->where('roles.id', 3)
                      ->orWhereRaw('LOWER(roles.nombre) = ?', ['mesero']);
                })
                ->whereHas('restaurantes', function($q) use ($restauranteId) {
                    $q->where('restaurantes.id', $restauranteId);
                })
                ->with('roles')
                ->get();

            $asignaciones = MesaMesero::where('restaurante_id', $restauranteId)->get();

            $data = $meseros->map(function($mesero) use ($asignaciones) {
                $rol = $mesero->roles->first();
                return [
                    'id' => $mesero->id,
                    'name' => $mesero->name,
                    'username' => $mesero->username,
                    'email' => $mesero->email,
                    'activo' => (bool) $mesero->activo,
                    'rol_id' => $rol?->id,
                    'rol_nombre' => $rol?->nombre,
                    'mesas' => $asignaciones->where('user_id', $mesero->id)->pluck('numero_mesa')->values(),
                    'created_at' => $mesero->created_at
                ];
            });

            $restaurante = Restaurante::find($restauranteId);

            return response()->json([
                'success' => true,
                'data' => [
                    'meseros' => $data,
                    'total_meseros' => $data->count(),
                    'total_mesas' => $restaurante->total_mesas ?? 0
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar el número total de mesas del restaurante
     * POST /api/meseros/configurar-mesas
     */
    public function configurarTotalMesas(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = $this->getRestauranteId($request, $user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó sucursal activa'], 400);
            }

            $validator = Validator::make($request->all(), [
                'total_mesas' => 'required|integer|min:0|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $restaurante = Restaurante::findOrFail($restauranteId);
            $restaurante->update(['total_mesas' => $request->total_mesas]);

            return response()->json([
                'success' => true,
                'message' => 'Configuración actualizada correctamente',
                'data' => ['total_mesas' => $restaurante->total_mesas]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Asignar mesas a un mesero
     * POST /api/meseros/asignar-mesas
     */
    public function asignarMesas(Request $request)
    {
        DB::beginTransaction();
        try {
            $userAuth = $request->user();
            $restauranteId = $this->getRestauranteId($request, $userAuth);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó sucursal activa'], 400);
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'rol_id' => 'required|exists:roles,id',
                'mesas' => 'present|array',
                'mesas.*' => 'integer|min:1|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // Verificar que el usuario existe en el restaurante
            $mesero = User::where('id', $request->user_id)
                ->whereHas('restaurantes', function($q) use ($restauranteId) {
                    $q->where('restaurantes.id', $restauranteId);
                })
                ->first();

            if (!$mesero) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'El mesero no pertenece a este restaurante'], 404);
            }

            // Obtener el total de mesas del restaurante
            $restaurante = Restaurante::find($restauranteId);
            $totalMesas = $restaurante->total_mesas ?? 0;

            // Validar que las mesas existen
            $invalidMesas = array_filter($request->mesas, function($mesa) use ($totalMesas) {
                return $mesa > $totalMesas;
            });

            if (!empty($invalidMesas)) {
                DB::rollBack();
                return response()->json([
                    'success' => false, 
                    'message' => 'Las siguientes mesas no existen en el restaurante: ' . implode(', ', $invalidMesas)
                ], 422);
            }

            // Validar que no haya duplicados en el nuevo arreglo
            if (count($request->mesas) !== count(array_unique($request->mesas))) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'No se pueden asignar mesas duplicadas'], 422);
            }

            // Verificar conflictos con otros meseros
            $mesasEnUso = MesaMesero::where('restaurante_id', $restauranteId)
                ->where('user_id', '!=', $request->user_id)
                ->whereIn('numero_mesa', $request->mesas)
                ->pluck('numero_mesa')
                ->toArray();

            if (!empty($mesasEnUso)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Las siguientes mesas ya están asignadas a otro mesero: ' . implode(', ', $mesasEnUso)
                ], 422);
            }

            // 1. Obtener las asignaciones actuales
            $currentAssignments = MesaMesero::where('user_id', $request->user_id)
                ->where('restaurante_id', $restauranteId)
                ->get();

            $newMesas = $request->mesas;
            
            // 2. Reutilizar registros existentes (Sobrescribir)
            foreach ($currentAssignments as $index => $assignment) {
                if (isset($newMesas[$index])) {
                    // Reutilizamos este registro (ID) para una de las nuevas mesas
                    $assignment->update([
                        'numero_mesa' => $newMesas[$index],
                        'rol_id' => $request->rol_id,
                        'propietario_id' => $userAuth->propietario_id
                    ]);
                    unset($newMesas[$index]); // Ya procesamos esta mesa
                } else {
                    // El nuevo set es más pequeño, borramos lo que sobra
                    $assignment->delete();
                }
            }

            // 3. Crear solo si sobran mesas nuevas
            foreach ($newMesas as $numMesa) {
                MesaMesero::create([
                    'user_id' => $request->user_id,
                    'restaurante_id' => $restauranteId,
                    'propietario_id' => $userAuth->propietario_id,
                    'rol_id' => $request->rol_id,
                    'numero_mesa' => $numMesa
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Mesas asignadas correctamente']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener las mesas asignadas al mesero logueado
     * GET /api/meseros/mis-mesas
     */
    public function misMesas(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = $this->getRestauranteId($request, $user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó sucursal activa'], 400);
            }

            $misMesas = MesaMesero::where('user_id', $user->id)
                ->where('restaurante_id', $restauranteId)
                ->pluck('numero_mesa')
                ->values()
                ->toArray();

            // Obtener el total de mesas del restaurante
            $restaurante = Restaurante::find($restauranteId);
            $totalMesas = $restaurante->total_mesas ?? 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'mis_mesas' => $misMesas,
                    'total_mesas_asignadas' => count($misMesas),
                    'total_mesas_restaurante' => $totalMesas
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener las órdenes que corresponden a las mesas asignadas al mesero logueado
     * GET /api/meseros/mis-ordenes
     */
    public function misOrdenes(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = $this->getRestauranteId($request, $user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó sucursal activa'], 400);
            }

            $esAdmin = $this->esAdminOPropietario($user);
 
            $query = Orden::where('restaurante_id', $restauranteId)
                ->with(['detalles.producto', 'user', 'cliente']);

            // Si NO es admin/propietario, aplicamos el filtro de mesas asignadas
            if (!$esAdmin) {
                $misMesas = MesaMesero::where('user_id', $user->id)
                    ->where('restaurante_id', $restauranteId)
                    ->pluck('numero_mesa')
                    ->toArray();

                if (empty($misMesas)) {
                    return response()->json([
                        'success' => true, 
                        'data' => [],
                        'message' => 'No tienes mesas asignadas'
                    ]);
                }

                $query->whereIn('mesa', $misMesas);
            }

            // Filtro por estado
            $estado = $request->query('estado');
            if (!empty($estado) && $estado !== 'todas') {
                $query->where('estado', strtoupper($estado));
            }

            // Filtro de fechas
            if ($request->filled('fecha_desde')) {
                $query->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            // Paginación opcional
            $perPage = $request->input('per_page', 20);
            $ordenes = $query->orderBy('id', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $ordenes,
                'es_admin' => $esAdmin
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Métricas de ventas agrupadas por mesero
     * GET /api/meseros/metricas-ventas
     */
    public function metricasVentas(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = $this->getRestauranteId($request, $user);

            if (empty($restauranteId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se detectó el ID de la sucursal activa'
                ], 400);
            }

            // Base query: órdenes del restaurante (estados correctos de la BD)
            $ordenesQuery = DB::table('ordenes')
                ->where('ordenes.restaurante_id', $restauranteId)
                ->whereIn('ordenes.estado', ['CERRADA', 'ENTREGADA'])
                ->whereNotNull('ordenes.mesa')
                ->where('ordenes.mesa', '>', 0);

            // Filtros opcionales de fecha
            if ($request->filled('fecha_desde')) {
                $ordenesQuery->whereDate('ordenes.created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $ordenesQuery->whereDate('ordenes.created_at', '<=', $request->fecha_hasta);
            }
            if ($request->filled('estado')) {
                $ordenesQuery->where('ordenes.estado', strtoupper($request->estado));
            }

            // Filtro por mesero específico
            if ($request->filled('mesero_id')) {
                $ordenesQuery->whereExists(function ($query) use ($request) {
                    $query->select(DB::raw(1))
                        ->from('mesas_meseros')
                        ->whereColumn('mesas_meseros.numero_mesa', 'ordenes.mesa')
                        ->where('mesas_meseros.user_id', $request->mesero_id)
                        ->where('mesas_meseros.restaurante_id', DB::raw('ordenes.restaurante_id'));
                });
            }

            // JOIN con mesas_meseros y users
            $metricas = $ordenesQuery
                ->join('mesas_meseros', function ($join) use ($restauranteId) {
                    $join->on('mesas_meseros.numero_mesa', '=', 'ordenes.mesa')
                         ->where('mesas_meseros.restaurante_id', '=', $restauranteId)
                         ->whereNotNull('mesas_meseros.user_id');
                })
                ->join('users', 'users.id', '=', 'mesas_meseros.user_id')
                ->select(
                    'users.id as mesero_id',
                    'users.name as mesero_nombre',
                    'users.username as mesero_username',
                    'users.email as mesero_email',
                    DB::raw('COUNT(DISTINCT ordenes.id) as total_ordenes'),
                    DB::raw('COALESCE(SUM(ordenes.total), 0) as total_ventas'),
                    DB::raw('COALESCE(AVG(ordenes.total), 0) as ticket_promedio'),
                    DB::raw('COUNT(DISTINCT ordenes.mesa) as mesas_atendidas'),
                    DB::raw('COUNT(DISTINCT ordenes.cliente_id) as clientes_atendidos'),
                    DB::raw('MIN(ordenes.created_at) as primera_venta'),
                    DB::raw('MAX(ordenes.created_at) as ultima_venta')
                )
                ->groupBy('users.id', 'users.name', 'users.username', 'users.email')
                ->orderByDesc('total_ventas')
                ->get();

            // Totales globales del restaurante
            $totales = DB::table('ordenes')
                ->where('restaurante_id', $restauranteId)
                ->whereIn('estado', ['CERRADA', 'ENTREGADA'])
                ->whereNotNull('mesa')
                ->where('mesa', '>', 0)
                ->when($request->filled('fecha_desde'), fn($q) =>
                    $q->whereDate('created_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn($q) =>
                    $q->whereDate('created_at', '<=', $request->fecha_hasta))
                ->when($request->filled('estado'), fn($q) =>
                    $q->where('estado', strtoupper($request->estado)))
                ->selectRaw('COUNT(*) as total_ordenes, COALESCE(SUM(total),0) as total_ventas')
                ->first();

            // Calcular total de meseros activos
            $totalMeseros = User::whereHas('roles', function($q) {
                    $q->where('roles.id', 3)->orWhereRaw('LOWER(roles.nombre) = ?', ['mesero']);
                })
                ->whereHas('restaurantes', function($q) use ($restauranteId) {
                    $q->where('restaurantes.id', $restauranteId);
                })
                ->where('activo', 1)
                ->count();

            $ventaTotal = (float) ($totales->total_ventas ?? 0);
            $ordenesTotal = (int) ($totales->total_ordenes ?? 0);

            $data = $metricas->map(function ($row) use ($ventaTotal, $ordenesTotal) {
                $totalVentas = (float) $row->total_ventas;
                $totalOrdenes = (int) $row->total_ordenes;
                
                return [
                    'mesero_id' => $row->mesero_id,
                    'mesero_nombre' => $row->mesero_nombre,
                    'mesero_username' => $row->mesero_username,
                    'mesero_email' => $row->mesero_email,
                    'total_ordenes' => $totalOrdenes,
                    'total_ventas' => $totalVentas,
                    'ticket_promedio' => $totalOrdenes > 0 
                        ? round($totalVentas / $totalOrdenes, 2)
                        : 0,
                    'mesas_atendidas' => (int) $row->mesas_atendidas,
                    'clientes_atendidos' => (int) $row->clientes_atendidos,
                    'participacion_ventas' => $ventaTotal > 0
                        ? round(($totalVentas / $ventaTotal) * 100, 2)
                        : 0,
                    'participacion_ordenes' => $ordenesTotal > 0
                        ? round(($totalOrdenes / $ordenesTotal) * 100, 2)
                        : 0,
                    'primera_venta' => $row->primera_venta,
                    'ultima_venta' => $row->ultima_venta,
                    'eficiencia' => $totalOrdenes > 0
                        ? round($totalVentas / $totalOrdenes, 2)
                        : 0
                ];
            });

            $ticketPromedioGeneral = $ordenesTotal > 0 
                ? round($ventaTotal / $ordenesTotal, 2) 
                : 0;

            $mejorMesero = $data->isNotEmpty() ? $data->first() : null;
            
            $ranking = $data->map(function($item, $index) {
                return [
                    'posicion' => $index + 1,
                    'mesero_id' => $item['mesero_id'],
                    'mesero_nombre' => $item['mesero_nombre'],
                    'total_ventas' => $item['total_ventas']
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'metricas_por_mesero' => $data,
                    'resumen_general' => [
                        'total_ordenes' => $ordenesTotal,
                        'total_ventas' => $ventaTotal,
                        'ticket_promedio_general' => $ticketPromedioGeneral,
                        'total_meseros_activos' => $totalMeseros,
                        'meseros_con_ventas' => $metricas->count(),
                        'periodo' => [
                            'desde' => $request->filled('fecha_desde') ? $request->fecha_desde : null,
                            'hasta' => $request->filled('fecha_hasta') ? $request->fecha_hasta : null
                        ]
                    ],
                    'ranking' => $ranking,
                    'top_mesero' => $mejorMesero ? [
                        'id' => $mejorMesero['mesero_id'],
                        'nombre' => $mejorMesero['mesero_nombre'],
                        'total_ventas' => $mejorMesero['total_ventas'],
                        'total_ordenes' => $mejorMesero['total_ordenes']
                    ] : null,
                    'data_para_grafico' => [
                        'labels' => $data->pluck('mesero_nombre')->toArray(),
                        'ventas' => $data->pluck('total_ventas')->toArray(),
                        'ordenes' => $data->pluck('total_ordenes')->toArray(),
                        'participacion' => $data->pluck('participacion_ventas')->toArray()
                    ]
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en metricasVentas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'restaurante_id' => $restauranteId ?? null
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener métricas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas detalladas de un mesero específico
     * GET /api/meseros/{id}/metricas-detalladas
     */
    public function metricasDetalladas(Request $request, $meseroId)
    {
        try {
            $user = $request->user();
            $restauranteId = $this->getRestauranteId($request, $user);

            if (empty($restauranteId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se detectó el ID de la sucursal activa'
                ], 400);
            }

            // Verificar que el mesero existe y pertenece al restaurante
            $mesero = User::where('id', $meseroId)
                ->whereHas('restaurantes', function($q) use ($restauranteId) {
                    $q->where('restaurantes.id', $restauranteId);
                })
                ->whereHas('roles', function($q) {
                    $q->where('roles.id', 3)->orWhereRaw('LOWER(roles.nombre) = ?', ['mesero']);
                })
                ->first();

            if (!$mesero) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mesero no encontrado'
                ], 404);
            }

            // Obtener las mesas asignadas al mesero
            $mesasAsignadas = MesaMesero::where('user_id', $meseroId)
                ->where('restaurante_id', $restauranteId)
                ->pluck('numero_mesa')
                ->toArray();

            // Query de órdenes atendidas por este mesero
            $ordenesQuery = Orden::where('restaurante_id', $restauranteId)
                ->whereIn('estado', ['CERRADA', 'ENTREGADA'])
                ->whereIn('mesa', $mesasAsignadas);

            // Filtros de fecha
            if ($request->filled('fecha_desde')) {
                $ordenesQuery->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $ordenesQuery->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            $ordenes = $ordenesQuery->get();
            
            $totalVentas = $ordenes->sum('total');
            $totalOrdenes = $ordenes->count();
            $ticketPromedio = $totalOrdenes > 0 ? $totalVentas / $totalOrdenes : 0;
            
            // Ventas por día de la semana
            $ventasPorDia = [];
            $diasSemana = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $diasNombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
            
            foreach ($diasSemana as $index => $dia) {
                $ordenesDia = $ordenes->filter(function($orden) use ($dia) {
                    return $orden->created_at->format('l') === $dia;
                });
                $ventasPorDia[$diasNombres[$index]] = [
                    'ordenes' => $ordenesDia->count(),
                    'ventas' => $ordenesDia->sum('total')
                ];
            }

            // Productos más vendidos por este mesero
            $productosTop = DB::table('orden_detalles')
                ->join('ordenes', 'ordenes.id', '=', 'orden_detalles.orden_id')
                ->join('productos', 'productos.id', '=', 'orden_detalles.producto_id')
                ->whereIn('ordenes.mesa', $mesasAsignadas)
                ->where('ordenes.restaurante_id', $restauranteId)
                ->whereIn('ordenes.estado', ['CERRADA', 'ENTREGADA'])
                ->when($request->filled('fecha_desde'), fn($q) =>
                    $q->whereDate('ordenes.created_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn($q) =>
                    $q->whereDate('ordenes.created_at', '<=', $request->fecha_hasta))
                ->select(
                    'productos.id',
                    'productos.nombre',
                    DB::raw('SUM(orden_detalles.cantidad) as total_vendido'),
                    DB::raw('SUM(orden_detalles.subtotal) as total_ventas')
                )
                ->groupBy('productos.id', 'productos.nombre')
                ->orderByDesc('total_vendido')
                ->limit(10)
                ->get();

            // Evolución de ventas por día
            $ventasPorFecha = $ordenes->groupBy(function($orden) {
                return $orden->created_at->format('Y-m-d');
            })->map(function($ordenesDelDia, $fecha) {
                return [
                    'fecha' => $fecha,
                    'ordenes' => $ordenesDelDia->count(),
                    'ventas' => $ordenesDelDia->sum('total')
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'mesero' => [
                        'id' => $mesero->id,
                        'name' => $mesero->name,
                        'username' => $mesero->username,
                        'email' => $mesero->email,
                        'activo' => (bool) $mesero->activo,
                        'fecha_contratacion' => $mesero->created_at->format('Y-m-d')
                    ],
                    'mesas_asignadas' => $mesasAsignadas,
                    'total_mesas' => count($mesasAsignadas),
                    'metricas' => [
                        'total_ventas' => round($totalVentas, 2),
                        'total_ordenes' => $totalOrdenes,
                        'ticket_promedio' => round($ticketPromedio, 2),
                        'ventas_por_dia' => $ventasPorDia,
                        'evolucion_ventas' => $ventasPorFecha,
                        'productos_top' => $productosTop
                    ],
                    'resumen_periodo' => [
                        'desde' => $request->filled('fecha_desde') ? $request->fecha_desde : null,
                        'hasta' => $request->filled('fecha_hasta') ? $request->fecha_hasta : null
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en metricasDetalladas: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'mesero_id' => $meseroId ?? null
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}