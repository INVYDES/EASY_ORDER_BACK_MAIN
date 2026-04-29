<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestauranteController extends Controller
{
    /**
     * Listar restaurantes con paginación y filtros
     */
    public function index(Request $request)
{
    try {
        $user = $request->user();
        if (!$user->hasPermission('VER_RESTAURANTE')) {
            return response()->json(['success' => false, 'message' => 'No tienes permiso para ver restaurantes'], 403);
        }

        $perPage   = min($request->get('per_page', 15), 50);
        $isCliente = $user->hasRole('cliente') || $user->hasRole('CLIENTE');
        $query     = $isCliente ? Restaurante::query() : $user->restaurantes();

        // Filtros
        if ($request->filled('nombre')) {
            $query->where('nombre', 'like', '%' . $request->nombre . '%');
        }
        if ($request->filled('ciudad')) {
            $query->where('ciudad', 'like', '%' . $request->ciudad . '%');
        }
        if ($request->filled('estado_region')) {
            $query->where('estado', 'like', '%' . $request->estado_region . '%');
        }
        if ($request->filled('buscar')) {
            $b = $request->buscar;
            $query->where(fn($q) => $q
                ->where('nombre', 'like', "%{$b}%")
                ->orWhere('ciudad', 'like', "%{$b}%")
                ->orWhere('estado', 'like', "%{$b}%")
                ->orWhere('calle', 'like', "%{$b}%")
            );
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }
        if ($request->filled('con_ordenes_hoy')) {
            $query->whereHas('ordenes', fn($q) => $q->whereDate('created_at', today()));
        }

        // Ordenamiento
        $orderBy  = $request->get('order_by', 'nombre');
        $orderDir = $request->get('order_dir', 'asc');
        if (in_array($orderBy, ['id', 'nombre', 'ciudad', 'estado', 'created_at'])) {
            $query->orderBy($orderBy, $orderDir === 'asc' ? 'asc' : 'desc');
        }

        $query->withCount(['productos', 'ordenes']);
        $restaurantes = $query->paginate($perPage);

        $restaurantesData = $restaurantes->map(function ($r) use ($user, $isCliente) {
            $ordenesHoy = $r->ordenes()->whereDate('created_at', today())->count();
            $ventasHoy  = $r->ordenes()->whereDate('created_at', today())->where('estado', 'CERRADA')->sum('total');

            return [
                'id'              => $r->id,
                'nombre'          => $r->nombre,
                'nombre_corto'    => strlen($r->nombre) > 30 ? substr($r->nombre, 0, 30) . '...' : $r->nombre,
                'telefono'        => $r->telefono,
                'direccion_completa' => trim($r->calle . ', ' . $r->ciudad . ', ' . $r->estado, ', '),
                'calle'           => $r->calle,
                'ciudad'          => $r->ciudad,
                'estado'          => $r->estado,
                'imagen'          => $r->imagen,
                'imagen_url'      => $r->imagen_url,
                'es_activo'       => !$isCliente && $user->restaurante_activo == $r->id,
                'estadisticas'    => $isCliente ? null : [
                    'productos_count'     => $r->productos_count,
                    'ordenes_count'       => $r->ordenes_count,
                    'ordenes_hoy'         => $ordenesHoy,
                    'ventas_hoy'          => (float) $ventasHoy,
                    'ventas_hoy_formateado' => '$' . number_format($ventasHoy, 2),
                ],
                'created_at'           => $r->created_at,
                'created_at_formateado' => $r->created_at->format('d/m/Y'),
                'updated_at'           => $r->updated_at,
            ];
        });

        $restauranteActivo = null;
        if (!$isCliente && $user->restaurante_activo) {
            $activo = Restaurante::find($user->restaurante_activo);
            if ($activo) {
                $restauranteActivo = [
                    'id'       => $activo->id,
                    'nombre'   => $activo->nombre,
                    'telefono' => $activo->telefono,
                    'ciudad'   => $activo->ciudad,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Restaurantes obtenidos correctamente',
            'data'    => ['restaurantes' => $restaurantesData, 'restaurante_activo' => $restauranteActivo],
            'pagination' => [
                'current_page'   => $restaurantes->currentPage(),
                'per_page'       => $restaurantes->perPage(),
                'total'          => $restaurantes->total(),
                'last_page'      => $restaurantes->lastPage(),
                'from'           => $restaurantes->firstItem(),
                'to'             => $restaurantes->lastItem(),
                'next_page_url'  => $restaurantes->nextPageUrl(),
                'prev_page_url'  => $restaurantes->previousPageUrl(),
                'has_more_pages' => $restaurantes->hasMorePages(),
            ],
            'filters' => [
                'nombre'       => $request->nombre ?? null,
                'ciudad'       => $request->ciudad ?? null,
                'estado_region' => $request->estado_region ?? null,
                'buscar'       => $request->buscar ?? null,
                'fecha_desde'  => $request->fecha_desde ?? null,
                'fecha_hasta'  => $request->fecha_hasta ?? null,
            ],
        ]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => 'Error al obtener restaurantes', 'error' => $e->getMessage()], 500);
    }
}

    /**
     * Mostrar un restaurante específico
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_RESTAURANTE')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restaurante = $user->restaurantes()->with(['propietario'])->where('restaurantes.id', $id)->firstOrFail();

            $estadisticas = [
                'productos' => [
                    'total' => $restaurante->productos()->count(),
                    'activos' => $restaurante->productos()->where('activo', true)->count(),
                    'inactivos' => $restaurante->productos()->where('activo', false)->count(),
                ],
                'ordenes' => [
                    'total' => $restaurante->ordenes()->count(),
                    'hoy' => $restaurante->ordenes()->whereDate('created_at', today())->count(),
                    'abiertas' => $restaurante->ordenes()->where('estado', 'ABIERTA')->count(),
                    'en_preparacion' => $restaurante->ordenes()->where('estado', 'EN_PREPARACION')->count(),
                    'listas' => $restaurante->ordenes()->where('estado', 'LISTA')->count(),
                    'cerradas' => $restaurante->ordenes()->where('estado', 'CERRADA')->count(),
                ],
                'ventas' => [
                    'hoy' => (float) $restaurante->ordenes()->whereDate('created_at', today())->where('estado', 'CERRADA')->sum('total'),
                    'semana' => (float) $restaurante->ordenes()->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->where('estado', 'CERRADA')->sum('total'),
                    'mes' => (float) $restaurante->ordenes()->whereMonth('created_at', now()->month)->where('estado', 'CERRADA')->sum('total'),
                ],
            ];

            $productosMasVendidos = DB::table('orden_detalles')
                ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
                ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
                ->where('ordenes.restaurante_id', $restaurante->id)
                ->where('ordenes.created_at', '>=', now()->subDays(30))
                ->select('productos.id', 'productos.nombre', DB::raw('SUM(orden_detalles.cantidad) as total_vendido'))
                ->groupBy('productos.id', 'productos.nombre')
                ->orderByDesc('total_vendido')
                ->limit(5)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $restaurante->id,
                    'nombre' => $restaurante->nombre,
                    'telefono' => $restaurante->telefono,
                    'direccion' => [
                        'calle' => $restaurante->calle,
                        'ciudad' => $restaurante->ciudad,
                        'estado' => $restaurante->estado,
                        'completa' => trim($restaurante->calle . ', ' . $restaurante->ciudad . ', ' . $restaurante->estado, ', '),
                    ],
                    'propietario' => $restaurante->propietario ? [
                        'id' => $restaurante->propietario->id,
                        'nombre' => $restaurante->propietario->nombre_completo ?? $restaurante->propietario->nombre,
                        'correo' => $restaurante->propietario->correo ?? $restaurante->propietario->email,
                    ] : null,
                    'es_activo' => $user->restaurante_activo == $restaurante->id,
                    'imagen' => $restaurante->imagen,
                    'imagen_url' => $restaurante->imagen_url,
                    'estadisticas' => $estadisticas,
                    'productos_destacados' => $productosMasVendidos,
                    'created_at' => $restaurante->created_at,
                    'created_at_formateado' => $restaurante->created_at->format('d/m/Y H:i'),
                    'updated_at' => $restaurante->updated_at,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Restaurante no encontrado'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener restaurante', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Crear un nuevo restaurante
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('CREAR_RESTAURANTE')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para crear restaurantes'], 403);
            }

            $request->validate([
                'nombre' => 'required|string|max:150',
                'telefono' => 'nullable|string|max:20',
                'calle' => 'nullable|string|max:150',
                'ciudad' => 'nullable|string|max:100',
                'estado' => 'nullable|string|max:100',
                'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            ], [
                'imagen.max' => 'La imagen es muy pesada. El tamaño máximo permitido es de 5MB.',
                'imagen.image' => 'El archivo seleccionado debe ser una imagen.',
                'imagen.mimes' => 'La imagen debe ser de formato: jpeg, png, jpg, gif o webp.',
            ]);

            $propietario = $user->propietario;

            // Verificar límite de licencia
            $licenciaActiva = $propietario->licenciaActiva();
            if ($licenciaActiva) {
                $limite = $licenciaActiva->licencia->max_restaurantes;
                $actuales = Restaurante::where('propietario_id', $propietario->id)->count();
                if ($actuales >= $limite) {
                    return response()->json([
                        'success' => false,
                        'message' => "Has alcanzado el límite de {$limite} restaurantes de tu licencia",
                        'limite' => $limite,
                        'actuales' => $actuales,
                    ], 403);
                }
            }

            DB::beginTransaction();

            $restaurante = Restaurante::create([
                'propietario_id' => $propietario->id,
                'nombre' => $request->nombre,
                'telefono' => $request->telefono,
                'calle' => $request->calle,
                'ciudad' => $request->ciudad,
                'estado' => $request->estado,
            ]);

            if ($request->hasFile('imagen')) {
                $path = $request->file('imagen')->store('restaurantes', 'public');
                $restaurante->update(['imagen' => $path]);
            }

            $user->restaurantes()->attach($restaurante->id);

            if (!$user->restaurante_activo) {
                $user->update(['restaurante_activo' => $restaurante->id]);
            }

            // Crear categorías base automáticamente
            $categoriasBase = [
                ['nombre' => 'Cocina', 'color' => '#10B981'], // Esmeralda
                ['nombre' => 'Barra',  'color' => '#6366F1'], // Indigo
                ['nombre' => 'Postres', 'color' => '#EC4899'], // Rosa
            ];

            foreach ($categoriasBase as $cat) {
                \App\Models\Categoria::create([
                    'restaurante_id' => $restaurante->id,
                    'nombre' => $cat['nombre'],
                    'color'  => $cat['color'],
                    'activo' => true
                ]);
            }

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('CREAR_RESTAURANTE', 'restaurantes', $restaurante->id, "Restaurante creado: {$restaurante->nombre}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Restaurante creado correctamente',
                'data' => [
                    'id' => $restaurante->id,
                    'nombre' => $restaurante->nombre,
                    'telefono' => $restaurante->telefono,
                    'calle' => $restaurante->calle,
                    'ciudad' => $restaurante->ciudad,
                    'estado' => $restaurante->estado,
                    'es_activo' => false,
                    'created_at' => $restaurante->created_at,
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear restaurante', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar un restaurante
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_RESTAURANTE')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para editar restaurantes'], 403);
            }

            $restaurante = $user->restaurantes()->where('restaurantes.id', $id)->firstOrFail();

            $request->validate([
                'nombre' => 'sometimes|string|max:150',
                'telefono' => 'nullable|string|max:20',
                'calle' => 'nullable|string|max:150',
                'ciudad' => 'nullable|string|max:100',
                'estado' => 'nullable|string|max:100',
                'activo' => 'sometimes|boolean',
                'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'eliminar_imagen' => 'nullable|boolean',
            ], [
                'imagen.max' => 'La imagen es muy pesada. El tamaño máximo permitido es de 5MB.',
                'imagen.image' => 'El archivo seleccionado debe ser una imagen.',
                'imagen.mimes' => 'La imagen debe ser de formato: jpeg, png, jpg, gif o webp.',
            ]);

            DB::beginTransaction();

            $restaurante->update($request->only(['nombre', 'telefono', 'calle', 'ciudad', 'estado', 'activo']));

            if ($request->eliminar_imagen && $restaurante->imagen) {
                if (!filter_var($restaurante->imagen, FILTER_VALIDATE_URL)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($restaurante->imagen);
                }
                $restaurante->update(['imagen' => null]);
            }

            if ($request->hasFile('imagen')) {
                // Borrar anterior
                if ($restaurante->imagen && !filter_var($restaurante->imagen, FILTER_VALIDATE_URL)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($restaurante->imagen);
                }
                $path = $request->file('imagen')->store('restaurantes', 'public');
                $restaurante->update(['imagen' => $path]);
            }

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('EDITAR_RESTAURANTE', 'restaurantes', $restaurante->id, "Restaurante actualizado: {$restaurante->nombre}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Restaurante actualizado correctamente',
                'data' => [
                    'id' => $restaurante->id,
                    'nombre' => $restaurante->nombre,
                    'telefono' => $restaurante->telefono,
                    'calle' => $restaurante->calle,
                    'ciudad' => $restaurante->ciudad,
                    'estado' => $restaurante->estado,
                    'es_activo' => $user->restaurante_activo == $restaurante->id,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Restaurante no encontrado'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar restaurante', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar un restaurante
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('ELIMINAR_RESTAURANTE')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para eliminar restaurantes'], 403);
            }

            $restaurante = $user->restaurantes()->where('restaurantes.id', $id)->firstOrFail();

            $ordenesActivas = $restaurante->ordenes()->whereIn('estado', ['ABIERTA', 'EN_PREPARACION', 'LISTA'])->count();
            if ($ordenesActivas > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No se puede eliminar: tiene {$ordenesActivas} órdenes activas",
                ], 409);
            }

            DB::beginTransaction();

            if ($user->restaurante_activo == $restaurante->id) {
                $otro = $user->restaurantes()->where('restaurantes.id', '!=', $id)->first();
                $user->update(['restaurante_activo' => $otro?->id]);
            }

            $restaurante->delete();
            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('ELIMINAR_RESTAURANTE', 'restaurantes', $restaurante->id, "Restaurante eliminado: {$restaurante->nombre}");
            }

            return response()->json(['success' => true, 'message' => 'Restaurante eliminado correctamente']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Restaurante no encontrado'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al eliminar restaurante', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Buscar restaurantes por nombre
     */
    public function buscarPorNombre(Request $request)
    {
        try {
            $request->validate(['nombre' => 'required|string|min:3']);
            $restaurantes = Restaurante::where('nombre', 'LIKE', '%' . $request->nombre . '%')
                ->get(['id', 'nombre', 'telefono', 'ciudad', 'estado']);
            return response()->json(['success' => true, 'data' => $restaurantes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al buscar restaurantes', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Estadísticas de un restaurante
     */
    public function estadisticas(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_RESTAURANTE')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restaurante = $user->restaurantes()->where('restaurantes.id', $id)->firstOrFail();

            $ventasSemana = [];
            for ($i = 6; $i >= 0; $i--) {
                $fecha = now()->subDays($i);
                $ventasSemana[] = [
                    'fecha' => $fecha->format('Y-m-d'),
                    'dia' => $fecha->format('d/m'),
                    'total' => (float) $restaurante->ordenes()->whereDate('created_at', $fecha)->where('estado', 'CERRADA')->sum('total'),
                ];
            }

            $productosTop = DB::table('orden_detalles')
                ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
                ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
                ->where('ordenes.restaurante_id', $restaurante->id)
                ->select(
                    'productos.id',
                    'productos.nombre',
                    DB::raw('SUM(orden_detalles.cantidad) as cantidad_total'),
                    DB::raw('SUM(orden_detalles.subtotal) as ventas_total')
                )
                ->groupBy('productos.id', 'productos.nombre')
                ->orderByDesc('cantidad_total')
                ->limit(10)
                ->get();

            $horasPico = DB::table('ordenes')
                ->where('restaurante_id', $restaurante->id)
                ->whereDate('created_at', '>=', now()->subDays(30))
                ->select(DB::raw('HOUR(created_at) as hora'), DB::raw('COUNT(*) as total'))
                ->groupBy('hora')
                ->orderBy('hora')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'restaurante' => ['id' => $restaurante->id, 'nombre' => $restaurante->nombre],
                    'ventas_semana' => $ventasSemana,
                    'productos_top' => $productosTop->map(fn($i) => [
                        'id' => $i->id,
                        'nombre' => $i->nombre,
                        'cantidad_vendida' => (int) $i->cantidad_total,
                        'ventas_totales' => (float) $i->ventas_total,
                    ]),
                    'horas_pico' => $horasPico->map(fn($i) => [
                        'hora' => $i->hora . ':00',
                        'ordenes' => (int) $i->total,
                    ]),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener estadísticas', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lista para select (comboBox)
     */
    public function selectList(Request $request)
    {
        try {
            $user = $request->user();
            $restaurantes = $user->restaurantes()->orderBy('nombre')->get(['id', 'nombre', 'ciudad']);
            return response()->json([
                'success' => true,
                'data' => $restaurantes->map(fn($r) => [
                    'value' => $r->id,
                    'label' => $r->nombre . ($r->ciudad ? ' (' . $r->ciudad . ')' : ''),
                    'es_activo' => $user->restaurante_activo == $r->id,
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener lista', 'error' => $e->getMessage()], 500);
        }
    }
}
