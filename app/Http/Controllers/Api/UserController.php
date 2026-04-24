<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Role;
use App\Models\Propietario;
use App\Models\Restaurante;
use App\Models\Log;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Obtener el usuario autenticado con roles y restaurantes
     */
    public function show(Request $request)
    {
        try {
            $user = $request->user();
            $user->load(['roles', 'restauranteActivo', 'restaurantes']);
            return response()->json(['success' => true, 'data' => $user]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener usuario', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar perfil
     */
    public function update(Request $request)
    {
        try {
            $user = $request->user();
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'username' => 'sometimes|string|max:50|unique:users,username,' . $user->id,
            ]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }
            $user->update($request->only(['name', 'email', 'username']));
            return response()->json(['success' => true, 'message' => 'Perfil actualizado correctamente', 'data' => $user]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar perfil', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar un usuario por ID (Uso administrativo)
     */
    public function updateById(Request $request, $id)
    {
        try {
            $admin = $request->user();
            $user = User::findOrFail($id);

            if ($admin->propietario_id !== $user->propietario_id && $admin->id !== $user->propietario_id) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $allData = $request->all();
            if (empty($allData)) {
                $allData = json_decode($request->getContent(), true) ?? [];
            }

            // Lógica de extracción MULTI-CAMPO (Mantenemos la lógica ganadora)
            $raId = null;
            if (isset($allData['restaurante_id']) && $allData['restaurante_id'] !== null) {
                $raId = (int)$allData['restaurante_id'];
            } elseif (isset($allData['restaurante_activo'])) {
                $raRaw = $allData['restaurante_activo'];
                if (is_numeric($raRaw)) {
                    $raId = (int)$raRaw;
                } elseif (is_array($raRaw)) {
                    $raId = $raRaw['id'] ?? null;
                } elseif (is_object($raRaw)) {
                    $raId = $raRaw->id ?? null;
                }
            }

            $fields = [];
            $params = [];
            
            if (isset($allData['name'])) { $fields[] = 'name = ?'; $params[] = $allData['name']; }
            if (isset($allData['email'])) { $fields[] = 'email = ?'; $params[] = $allData['email']; }
            if (isset($allData['username'])) { $fields[] = 'username = ?'; $params[] = $allData['username']; }
            if (!empty($allData['password'])) { $fields[] = 'password = ?'; $params[] = Hash::make($allData['password']); }
            
            if ($raId !== null) { 
                $fields[] = 'restaurante_activo = ?'; 
                $params[] = (int)$raId; 
                $user->restaurantes()->sync([(int)$raId]);
            }
            
            if (!empty($fields)) {
                $fields[] = 'updated_at = ?';
                $params[] = now();
                $params[] = $id;
                DB::statement("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?", $params);
            }

            $user = User::with(['roles', 'restauranteActivo'])->find($id);

            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener todos los restaurantes del dueño del administrador
     */
    public function getOwnerRestaurants(Request $request)
    {
        try {
            $user = $request->user();
            $propietarioId = $user->propietario_id ?: $user->id;

            $restaurantes = Restaurante::where('propietario_id', $propietarioId)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'ciudad']);

            return response()->json(['success' => true, 'data' => $restaurantes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener lista', 'error' => $e->getMessage()], 500);
        }
    }
}
