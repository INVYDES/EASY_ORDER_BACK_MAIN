<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Propietario;
use App\Models\Restaurante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class PlataformaController extends Controller
{
    /**
     * Listar todos los propietarios con sus restaurantes y licencias
     */
    public function index(Request $request)
    {
        try {
            if (!$request->user()->hasPermission('gestionar_plataforma')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para acceder al panel de plataforma'
                ], 403);
            }

            $propietarios = Propietario::with(['restaurantes', 'licencias.licencia'])
                ->orderBy('nombre', 'asc')
                ->get()
                ->map(function($prop) {
                    $licencia = $prop->getLicenciaActiva();
                    
                    return [
                        'id' => $prop->id,
                        'nombre_completo' => $prop->nombre . ' ' . $prop->apellido,
                        'correo' => $prop->correo,
                        'telefono' => $prop->telefono,
                        'rfc' => $prop->rfc,
                        'regimen_fiscal' => $prop->regimen_fiscal,
                        'total_restaurantes' => $prop->restaurantes->count(),
                        'licencia_actual' => $licencia ? [
                            'nombre' => $licencia->licencia->nombre ?? 'N/A',
                            'estado' => $licencia->estado,
                            'expira' => $licencia->fecha_expiracion,
                        ] : null,
                        'restaurantes' => $prop->restaurantes->map(function($res) {
                            return [
                                'id' => $res->id,
                                'nombre' => $res->nombre,
                                'ubicacion' => "{$res->calle}, {$res->ciudad}, {$res->estado}",
                                'telefono' => $res->telefono,
                                'total_mesas' => $res->total_mesas,
                            ];
                        })
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $propietarios
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener datos de la plataforma: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Estadísticas generales de la plataforma
     */
    public function stats(Request $request)
    {
        try {
            if (!$request->user()->hasPermission('gestionar_plataforma')) {
                return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
            }

            $stats = [
                'total_propietarios' => Propietario::count(),
                'total_restaurantes' => Restaurante::count(),
                'licencias_activas' => DB::table('propietario_licencia')->where('estado', 'ACTIVA')->count(),
                'total_usuarios' => User::count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar un propietario y todo lo relacionado
     */
    public function destroyPropietario($id)
    {
        try {
            DB::beginTransaction();

            $propietario = Propietario::findOrFail($id);

            // 1. Eliminar restaurantes (esto disparará la limpieza de cada uno)
            foreach ($propietario->restaurantes as $rest) {
                $this->eliminarDatosRestaurante($rest->id);
                $rest->forceDelete(); // forceDelete porque usa SoftDeletes
            }

            // 2. Eliminar licencias
            DB::table('propietario_licencia')->where('propietario_id', $id)->delete();

            // 3. Eliminar usuarios vinculados
            User::where('propietario_id', $id)->forceDelete();

            // 4. Eliminar al propietario
            $propietario->forceDelete();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Propietario y todos sus datos eliminados correctamente']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al eliminar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar un restaurante específico y sus datos
     */
    public function destroyRestaurante($id)
    {
        try {
            DB::beginTransaction();
            $this->eliminarDatosRestaurante($id);
            Restaurante::findOrFail($id)->forceDelete();
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Restaurante y sus datos eliminados correctamente']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Limpia todas las tablas relacionadas a un restaurante
     */
    private function eliminarDatosRestaurante($restauranteId)
    {
        // Tablas que dependen del restaurante_id
        $tablas = [
            'orden_detalles' => 'orden_id', // Caso especial: depende de ordenes
            'ordenes'        => 'restaurante_id',
            'productos'      => 'restaurante_id',
            'categorias'     => 'restaurante_id',
            'caja_movimientos' => 'caja_id', // Caso especial: depende de cajas
            'cajas'          => 'restaurante_id',
            'asistencias'    => 'restaurante_id',
            'nominas'        => 'restaurante_id',
            'gastos'         => 'restaurante_id',
            'ingredientes'   => 'restaurante_id',
            'anuncios'       => 'restaurante_id',
            'horarios'       => 'restaurante_id',
            'mesas'          => 'restaurante_id',
            'restaurante_user' => 'restaurante_id'
        ];

        // 1. Detalles de ordenes y tickets de soporte (cascada manual)
        $ordenIds = DB::table('ordenes')->where('restaurante_id', $restauranteId)->pluck('id');
        DB::table('orden_detalles')->whereIn('orden_id', $ordenIds)->delete();
        DB::table('tickets')->where('restaurante_id', $restauranteId)->delete();

        // 2. Movimientos de caja (cascada manual)
        $cajaIds = DB::table('cajas')->where('restaurante_id', $restauranteId)->pluck('id');
        DB::table('caja_movimientos')->whereIn('caja_id', $cajaIds)->delete();

        // 3. El resto de tablas directas
        foreach ($tablas as $tabla => $columna) {
            if ($columna === 'restaurante_id') {
                DB::table($tabla)->where('restaurante_id', $restauranteId)->delete();
            }
        }
    }
}
