<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Obtener el usuario autenticado con roles y restaurantes
     */
    public function show(Request $request)
    {
        try {

            $user = $request->user();

            $user->load([
                'roles',
                'restauranteActivo',
                'restaurantes'
            ]);

            return response()->json([
                'success' => true,
                'data' => $user
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Actualizar perfil del usuario
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
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user->update(
                $request->only(['name', 'email', 'username'])
            );

            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado correctamente',
                'data' => $user
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar perfil',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Cambiar contraseña
     */
    public function password(Request $request)
    {
        try {

            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'password' => 'required|string|min:8|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verificar contraseña actual
            if (!Hash::check($request->current_password, $user->password)) {

                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta'
                ], 403);
            }

            $user->update([
                'password' => Hash::make($request->password)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada correctamente'
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar contraseña',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Obtener todos los roles del usuario
     */
    public function roles(Request $request)
    {
        try {

            $user = $request->user();

            return response()->json([
                'success' => true,
                'data' => $user->roles
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Obtener rol principal del usuario
     */
    public function rolPrincipal(Request $request)
    {
        try {

            $user = $request->user();
            $rol = $user->roles->first();

            return response()->json([
                'success' => true,
                'data' => $rol
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener rol principal',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}