<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdenDetalle;
use App\Models\Paquete;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaqueteController extends Controller
{
    public function index(Request $request)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquetes = Paquete::with(['productos.categoria', 'productos.ingredientes'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->when($request->filled('buscar'), function($q) use ($request) {
                    $q->where('nombre', 'LIKE', "%{$request->buscar}%");
                })
                ->get();

            $data = $paquetes->map(fn($p) => $this->formatPaqueteResponse($p));

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener paquetes', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'precio' => 'required|numeric|min:0',
            'productos' => 'required|array|min:1',
            'productos.*.id' => 'required|exists:productos,id',
            'productos.*.cantidad' => 'required|numeric|min:0.1',
            'imagen' => 'nullable|image|max:2048'
        ]);

        try {
            DB::beginTransaction();

            $restauranteActivo = app('restaurante_activo');
            
            $data = $request->only(['nombre', 'descripcion', 'precio']);
            $data['restaurante_id'] = $restauranteActivo->id;
            $data['propietario_id'] = $restauranteActivo->propietario_id;
            $data['activo'] = true;

            if ($request->hasFile('imagen')) {
                $path = $request->file('imagen')->store('paquetes', 'public');
                $data['imagen'] = $path;
            }

            $paquete = Paquete::create($data);

            // Sincronizar productos con posible tamano_id
            $paquete->productos()->detach();
            foreach ($request->productos as $prod) {
                $attachData = ['cantidad' => $prod['cantidad']];
                if (!empty($prod['tamano_id'])) {
                    $attachData['tamano_id'] = $prod['tamano_id'];
                }
                $paquete->productos()->attach($prod['id'], $attachData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paquete creado correctamente',
                'data' => $this->formatPaqueteResponse($paquete)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear paquete', 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::with(['productos.categoria', 'productos.ingredientes', 'productos.tamanos'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            return response()->json(['success' => true, 'data' => $this->formatPaqueteResponse($paquete)]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Paquete no encontrado'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'precio' => 'required|numeric|min:0',
            'productos' => 'required|array|min:1',
            'productos.*.id' => 'required|exists:productos,id',
            'productos.*.cantidad' => 'required|numeric|min:0.1',
            'imagen' => 'nullable|image|max:2048'
        ]);

        try {
            DB::beginTransaction();

            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $data = $request->only(['nombre', 'descripcion', 'precio']);

            if ($request->hasFile('imagen')) {
                // Eliminar imagen anterior
                if ($paquete->imagen) {
                    Storage::disk('public')->delete($paquete->imagen);
                }
                $path = $request->file('imagen')->store('paquetes', 'public');
                $data['imagen'] = $path;
            }

            $paquete->update($data);

            // Sincronizar productos con posible tamano_id
            $paquete->productos()->detach();
            foreach ($request->productos as $prod) {
                $attachData = ['cantidad' => $prod['cantidad']];
                if (!empty($prod['tamano_id'])) {
                    $attachData['tamano_id'] = $prod['tamano_id'];
                }
                $paquete->productos()->attach($prod['id'], $attachData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paquete actualizado correctamente',
                'data' => $this->formatPaqueteResponse($paquete)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar paquete', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $ordenesAsociadas = OrdenDetalle::where('paquete_id', $paquete->id)->count();
            if ($ordenesAsociadas > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No se puede eliminar el paquete porque tiene {$ordenesAsociadas} orden(es) asociada(s)"
                ], 409);
            }

            if ($paquete->imagen) {
                Storage::disk('public')->delete($paquete->imagen);
            }

            $paquete->delete();

            return response()->json(['success' => true, 'message' => 'Paquete eliminado correctamente']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar paquete', 'error' => $e->getMessage()], 500);
        }
    }

    public function toggleActive($id)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $paquete->update(['activo' => !$paquete->activo]);

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado',
                'data' => ['activo' => $paquete->activo]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar estado'], 500);
        }
    }
    /**
     * Listar paquetes públicamente (para el Kiosko de Menú)
     */
    public function indexPublic(Request $request)
    {
        try {
            $restauranteId = $request->get('restaurante_id');
            if (!$restauranteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere restaurante_id'
                ], 422);
            }

            $paquetes = Paquete::withoutGlobalScope(\App\Scopes\TenantScope::class)->with(['productos.categoria'])
                ->where('restaurante_id', $restauranteId)
                ->where('activo', true)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paquetes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener paquetes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Mostrar un paquete específico públicamente
     */
    public function showPublic($id, Request $request)
    {
        try {
            $restauranteId = $request->get('restaurante_id');
            if (!$restauranteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere restaurante_id'
                ], 422);
            }

            $paquete = Paquete::withoutGlobalScope(\App\Scopes\TenantScope::class)->with(['productos.categoria'])
                ->where('restaurante_id', $restauranteId)
                ->where('id', $id)
                ->where('activo', true)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $paquete
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Paquete no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener paquete',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    private function obtenerTotalNominaMensual($restauranteId)
    {
        return User::where('restaurante_activo', $restauranteId)
            ->sum('salario_base');
    }

    private function calcularCostosProducto($producto, $totalNominaMensual)
    {
        $costoInsumos = $producto->ingredientes->reduce(function($carry, $ing) {
            $cant = $ing->pivot->cantidad ?? 0;
            return $carry + ($ing->costo_unitario * $cant);
        }, 0);

        $minProd = (float) ($producto->minutos_produccion ?? 0);
        $costoMO = $totalNominaMensual > 0 && $minProd > 0
            ? ($totalNominaMensual / 14400) * 1.36 * $minProd
            : 0;

        $costoBase = $costoInsumos + $costoMO;
        $costoIndirectos = $costoBase * 0.05;
        $costoTotal = $costoBase + $costoIndirectos;

        $margenValor = $producto->precio - $costoTotal;
        $margenPct = $producto->precio > 0 ? round(($margenValor / $producto->precio) * 100, 2) : 0;

        return compact('costoInsumos', 'costoMO', 'costoIndirectos', 'costoTotal', 'margenValor', 'margenPct');
    }

    private function formatPaqueteResponse($paquete)
    {
        $paquete->loadMissing(['productos.categoria', 'productos.ingredientes', 'productos.tamanos']);

        $totalNominaMensual = $this->obtenerTotalNominaMensual($paquete->restaurante_id);

        $costoTotalPaquete = 0;
        $costoInsumosPaquete = 0;
        $costoMOPaquete = 0;
        $costoIndirectosPaquete = 0;
        $totalMinutosProduccion = 0;

        $unidadesPosibles = collect();
        foreach ($paquete->productos as $producto) {
            $tamanoId  = $producto->pivot->tamano_id ?? null;
            $tamanoObj = $tamanoId ? $producto->tamanos->find($tamanoId) : null;
            if ($tamanoObj) {
                $producto->tamano_id     = $tamanoObj->id;
                $producto->tamano_nombre   = $tamanoObj->nombre;
                $producto->precio          = $tamanoObj->precio;
                $producto->stock           = $tamanoObj->stock;
            }

            $c = $this->calcularCostosProducto($producto, $totalNominaMensual);
            $cantidad = (float) ($producto->pivot->cantidad ?? 1);
            $costoTotalPaquete += $c['costoTotal'] * $cantidad;
            $costoInsumosPaquete += $c['costoInsumos'] * $cantidad;
            $costoMOPaquete += $c['costoMO'] * $cantidad;
            $costoIndirectosPaquete += $c['costoIndirectos'] * $cantidad;
            $totalMinutosProduccion += (float) ($producto->minutos_produccion ?? 0) * $cantidad;

            // Calcular stock posible para este producto del combo
            $stockProducto = (float) $producto->stock;
            if ($cantidad > 0) {
                $unidadesPosibles->push(floor($stockProducto / $cantidad));
            }
        }

        $stockPaquete = $paquete->productos->isEmpty() ? 0 : $unidadesPosibles->min();

        $margenValor = $paquete->precio - $costoTotalPaquete;
        $margenPct = $paquete->precio > 0 ? round(($margenValor / $paquete->precio) * 100, 2) : 0;

        $data = $paquete->toArray();
        $data['costo_insumos'] = round($costoInsumosPaquete, 4);
        $data['costo_mo'] = round($costoMOPaquete, 4);
        $data['costo_indirectos'] = round($costoIndirectosPaquete, 4);
        $data['costo_total'] = round($costoTotalPaquete, 4);
        $data['margen'] = round($margenValor, 2);
        $data['margen_pct'] = $margenPct;
        $data['nomina_mensual_base'] = (float) $totalNominaMensual;
        $data['minutos_produccion'] = $totalMinutosProduccion;
        $data['stock'] = (float) $stockPaquete;
        $data['agotado'] = $stockPaquete <= 0;

        return $data;
    }
}
