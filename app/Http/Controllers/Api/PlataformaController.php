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
}
