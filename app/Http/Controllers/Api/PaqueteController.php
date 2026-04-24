<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paquete;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaqueteController extends Controller
{
    public function index(Request $request)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquetes = Paquete::with('productos.categoria')
                ->where('restaurante_id', $restauranteActivo->id)
                ->when($request->filled('buscar'), function($q) use ($request) {
                    $q->where('nombre', 'LIKE', "%{$request->buscar}%");
                })
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paquetes
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
            'productos.*.cantidad' => 'required|integer|min:1',
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

            // Sincronizar productos
            foreach ($request->productos as $prod) {
                $paquete->productos()->attach($prod['id'], ['cantidad' => $prod['cantidad']]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paquete creado correctamente',
                'data' => $paquete->load('productos')
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
            $paquete = Paquete::with('productos.categoria')
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            return response()->json(['success' => true, 'data' => $paquete]);
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
            'productos.*.cantidad' => 'required|integer|min:1',
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

            // Sincronizar productos
            $productosSinc = [];
            foreach ($request->productos as $prod) {
                $productosSinc[$prod['id']] = ['cantidad' => $prod['cantidad']];
            }
            $paquete->productos()->sync($productosSinc);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paquete actualizado correctamente',
                'data' => $paquete->load('productos')
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
}
