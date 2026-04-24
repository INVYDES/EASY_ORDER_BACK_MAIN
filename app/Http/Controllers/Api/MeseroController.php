<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Restaurante;
use App\Models\MesaMesero;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MeseroController extends Controller
{
    /**
     * Obtener lista de meseros con sus mesas asignadas
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // 1. Obtener ID de forma estricta (Header -> Perfil -> Request)
            $headerId = $request->header('X-Restaurante-Id');
            $userRestId = is_object($user->restaurante_activo) ? $user->restaurante_activo->id : $user->restaurante_activo;
            $requestId = $request->restaurante_id;

            $restauranteId = (!empty($headerId)) ? $headerId : ((!empty($userRestId)) ? $userRestId : $requestId);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            // Buscamos usuarios que tengan el rol con ID 3 o nombre 'Mesero'
            $meseros = User::whereHas('roles', function($q) {
                    $q->where('roles.id', 3)
                      ->orWhereRaw('LOWER(roles.nombre) = ?', ['mesero']);
                })
                ->where('propietario_id', $user->propietario_id)
                ->where(function($q) use ($restauranteId) {
                    $q->where('restaurante_activo', $restauranteId)
                      ->orWhereHas('restaurantes', function($sq) use ($restauranteId) {
                          $sq->where('restaurantes.id', $restauranteId);
                      });
                })
                ->with('roles')
                ->get();

            $asignaciones = MesaMesero::where('restaurante_id', $restauranteId)->get();

            $data = $meseros->map(function($mesero) use ($asignaciones) {
                return [
                    'id' => $mesero->id,
                    'name' => $mesero->name,
                    'username' => $mesero->username,
                    'rol_id' => $mesero->roles->first()?->id,
                    'mesas' => $asignaciones->where('user_id', $mesero->id)->pluck('numero_mesa')->values()
                ];
            });

            $restaurante = Restaurante::find($restauranteId);

            return response()->json([
                'success' => true,
                'data' => [
                    'meseros' => $data,
                    'total_mesas' => $restaurante->total_mesas ?? 0
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar el número total de mesas del restaurante
     */
    public function configurarTotalMesas(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = is_object($user->restaurante_activo) ? $user->restaurante_activo->id : $user->restaurante_activo;

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
                'message' => 'Configuración actualizada',
                'total_mesas' => $restaurante->total_mesas
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Asignar mesas a un mesero
     */
    public function asignarMesas(Request $request)
    {
        DB::beginTransaction();
        try {
            $userAuth = $request->user();
            $restauranteId = is_object($userAuth->restaurante_activo) ? $userAuth->restaurante_activo->id : $userAuth->restaurante_activo;

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'rol_id' => 'required|exists:roles,id',
                'mesas' => 'present|array'
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            MesaMesero::where('user_id', $request->user_id)
                ->where('restaurante_id', $restauranteId)
                ->delete();

            foreach ($request->mesas as $numMesa) {
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
     * Obtener las órdenes que corresponden a las mesas asignadas al mesero logueado
     * Si es Admin o Propietario, ve todas las órdenes.
     */
    public function misOrdenes(Request $request)
    {
        try {
            $user = $request->user();
            $headerId = $request->header('X-Restaurante-Id');
            $userRestId = is_object($user->restaurante_activo) ? $user->restaurante_activo->id : $user->restaurante_activo;
            
            $restauranteId = (!empty($headerId)) ? $headerId : $userRestId;

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó sucursal activa'], 400);
            }

            // Verificamos si el usuario es Propietario (1) o Administrador (2)
            $esAdminOPropietario = $user->roles()->whereIn('roles.id', [1, 2])->exists();
 
            $query = \App\Models\Orden::where('restaurante_id', $restauranteId)
                ->with(['detalles.producto', 'user', 'cliente']);

            // Si NO es admin/propietario, aplicamos el filtro de mesas asignadas
            if (!$esAdminOPropietario) {
                $misMesas = MesaMesero::where('user_id', $user->id)
                    ->where('restaurante_id', $restauranteId)
                    ->pluck('numero_mesa')
                    ->toArray();

                if (empty($misMesas)) {
                    return response()->json(['success' => true, 'data' => []]);
                }

                $query->whereIn('mesa', $misMesas);
            }

            $estado = $request->query('estado');
            if (!empty($estado) && $estado !== 'todas') {
                $query->where('estado', $estado);
            }

            // Filtro de fechas
            if ($request->has('fecha_desde')) {
                $query->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->has('fecha_hasta')) {
                $query->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            $ordenes = $query->orderBy('id', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $ordenes
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
