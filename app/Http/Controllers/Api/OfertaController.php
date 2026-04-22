<?php
// app/Http/Controllers/Api/OfertaController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Oferta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OfertaController extends Controller
{
    /**
     * Listar todas las ofertas del restaurante actual
     */
    public function index(Request $request)
    {
        try {
            $restauranteId = $request->get('restaurante_id');
            
            $ofertas = Oferta::where('restaurante_id', $restauranteId)
                ->with('productos')
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $ofertas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar ofertas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener ofertas activas (para marquesina)
     */
    public function activas(Request $request)
    {
        try {
            $restauranteId = $request->get('restaurante_id');
            $diaSemana = strtolower($request->get('dia', date('l'))); // monday, tuesday, etc.
            
            $ofertas = Oferta::where('restaurante_id', $restauranteId)
                ->where('activo', true)
                ->where(function($query) use ($diaSemana) {
                    $query->whereNull('dias_semana')
                        ->orWhereJsonContains('dias_semana', $diaSemana);
                })
                ->with('productos')
                ->get();
            
            // Enriquecer productos con precio de oferta
            foreach ($ofertas as $oferta) {
                foreach ($oferta->productos as $producto) {
                    if ($oferta->tipo === 'descuento' && $oferta->descuento_porcentaje) {
                        $producto->precio_oferta = $producto->precio * (1 - $oferta->descuento_porcentaje / 100);
                    } elseif ($oferta->tipo === 'precio_especial' && $oferta->precio_especial) {
                        $producto->precio_oferta = $oferta->precio_especial;
                    } else {
                        $producto->precio_oferta = $producto->precio;
                    }
                    $producto->oferta_tipo = $oferta->tipo;
                    $producto->oferta_titulo = $oferta->titulo;
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $ofertas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar ofertas activas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nueva oferta
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'titulo' => 'required|string|max:255',
                'descripcion' => 'nullable|string',
                'tipo' => 'required|in:2x1,descuento,precio_especial,combo,promocion',
                'descuento_porcentaje' => 'required_if:tipo,descuento|nullable|numeric|min:0|max:100',
                'precio_especial' => 'required_if:tipo,precio_especial|nullable|numeric|min:0',
                'productos_ids' => 'required|array|min:1',
                'productos_ids.*' => 'exists:productos,id',
                'dias_semana' => 'nullable|array',
                'icono' => 'nullable|string|max:10'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $restauranteId = $request->get('restaurante_id');
            
            $oferta = Oferta::create([
                'restaurante_id' => $restauranteId,
                'titulo' => $request->titulo,
                'descripcion' => $request->descripcion,
                'descripcion_corta' => $request->descripcion_corta,
                'tipo' => $request->tipo,
                'descuento_porcentaje' => $request->descuento_porcentaje,
                'precio_especial' => $request->precio_especial,
                'dias_semana' => $request->dias_semana,
                'icono' => $request->icono ?? '🎉',
                'activo' => $request->activo ?? true
            ]);
            
            if ($request->has('productos_ids')) {
                $oferta->productos()->sync($request->productos_ids);
            }
            
            $oferta->load('productos');
            
            return response()->json([
                'success' => true,
                'message' => 'Oferta creada exitosamente',
                'data' => $oferta
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear oferta: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar una oferta específica
     */
    public function show($id)
    {
        try {
            $oferta = Oferta::with('productos')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $oferta
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Oferta no encontrada'
            ], 404);
        }
    }

    /**
     * Actualizar oferta
     */
    public function update(Request $request, $id)
    {
        try {
            $oferta = Oferta::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'titulo' => 'sometimes|required|string|max:255',
                'descripcion' => 'nullable|string',
                'tipo' => 'sometimes|required|in:2x1,descuento,precio_especial,combo,promocion',
                'descuento_porcentaje' => 'required_if:tipo,descuento|nullable|numeric|min:0|max:100',
                'precio_especial' => 'required_if:tipo,precio_especial|nullable|numeric|min:0',
                'productos_ids' => 'sometimes|required|array|min:1',
                'productos_ids.*' => 'exists:productos,id',
                'dias_semana' => 'nullable|array',
                'icono' => 'nullable|string|max:10',
                'activo' => 'boolean'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $oferta->update($request->only([
                'titulo', 'descripcion', 'descripcion_corta', 'tipo',
                'descuento_porcentaje', 'precio_especial', 'dias_semana',
                'icono', 'activo'
            ]));
            
            if ($request->has('productos_ids')) {
                $oferta->productos()->sync($request->productos_ids);
            }
            
            $oferta->load('productos');
            
            return response()->json([
                'success' => true,
                'message' => 'Oferta actualizada exitosamente',
                'data' => $oferta
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar oferta: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar oferta
     */
    public function destroy($id)
    {
        try {
            $oferta = Oferta::findOrFail($id);
            $oferta->productos()->detach();
            $oferta->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Oferta eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar oferta'
            ], 500);
        }
    }

    /**
     * Activar/Desactivar oferta
     */
    public function toggleActive($id)
    {
        try {
            $oferta = Oferta::findOrFail($id);
            $oferta->activo = !$oferta->activo;
            $oferta->save();
            
            return response()->json([
                'success' => true,
                'message' => $oferta->activo ? 'Oferta activada' : 'Oferta desactivada',
                'data' => ['activo' => $oferta->activo]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado'
            ], 500);
        }
    }
    public function activasPublic(Request $request)
{
    return $this->activas($request);
}

}