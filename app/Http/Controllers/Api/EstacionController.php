<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Estacion;
use Illuminate\Http\Request;

class EstacionController extends Controller
{
    public function index()
    {
        try {
            $estaciones = Estacion::orderBy('nombre')->get();
            return response()->json(['success' => true, 'data' => $estaciones]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100|unique:estaciones,nombre',
            'descripcion' => 'nullable|string|max:255',
        ]);

        try {
            $estacion = Estacion::create([
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Estación creada',
                'data' => $estacion,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $estacion = Estacion::findOrFail($id);
            return response()->json(['success' => true, 'data' => $estacion]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Estación no encontrada'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:100|unique:estaciones,nombre,' . $id,
            'descripcion' => 'nullable|string|max:255',
            'activo' => 'nullable|boolean',
        ]);

        try {
            $estacion = Estacion::findOrFail($id);
            $estacion->update([
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
                'activo' => $request->boolean('activo', $estacion->activo),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Estación actualizada',
                'data' => $estacion,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $estacion = Estacion::findOrFail($id);
            $estacion->delete();

            return response()->json([
                'success' => true,
                'message' => 'Estación eliminada',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
