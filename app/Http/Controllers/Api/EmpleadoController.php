<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Nomina;
use App\Models\NominaDetalle;
use App\Models\ConfiguracionNomina;
use App\Models\Restaurante;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmpleadoController extends Controller
{
    /** Roles de personal operativo */
    private function staffRoleNombres(): array
    {
        return ['MESERO', 'COCINA', 'CAJA', 'ADMIN', 'MENU'];
    }

    private function empleadosBaseQuery(int $restauranteId)
    {
        return User::query()
            ->whereHas('restaurantes', fn ($q) => $q->where('restaurantes.id', $restauranteId))
            ->whereHas('roles', fn ($q) => $q->whereIn('nombre', $this->staffRoleNombres()));
    }

    private function findStaffUser(int $userId, int $restauranteId): ?User
    {
        return $this->empleadosBaseQuery($restauranteId)
            ->with('roles')
            ->where('users.id', $userId)
            ->first();
    }

    private function mapPuestoToRole(string $puesto): ?string
    {
        return match (strtolower($puesto)) {
            'mesero' => 'MESERO',
            'cocina' => 'COCINA',
            'caja' => 'CAJA',
            'admin' => 'ADMIN',
            default => null,
        };
    }

    private function resolveUserId(Request $request): ?int
    {
        $v = $request->input('user_id', $request->input('empleado_id'));
        return $v !== null && $v !== '' ? (int) $v : null;
    }

    private function getRestauranteId($user)
    {
        $headerId = request()->header('X-Restaurante-Id');
        $userRestId = is_object($user->restaurante_activo) ? $user->restaurante_activo->id : $user->restaurante_activo;
        $requestId = request()->restaurante_id;

        return (!empty($headerId)) ? $headerId : ((!empty($userRestId)) ? $userRestId : $requestId);
    }

    /**
     * Listar personal del restaurante
     * GET /api/empleados
     */
   public function index(Request $request)
{
    try {
        $user = $request->user();
        $restauranteId = (int) $this->getRestauranteId($user);

        if (empty($restauranteId)) {
            return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
        }

        $query = $this->empleadosBaseQuery($restauranteId)->with(['roles', 'restauranteActivo']);

        if ($request->filled('puesto')) {
            $rn = $this->mapPuestoToRole($request->puesto);
            if ($rn) {
                $query->whereHas('roles', fn ($q) => $q->where('nombre', $rn));
            }
        }

        if ($request->filled('nombre')) {
            $query->where('name', 'LIKE', '%' . $request->nombre . '%');
        }

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        $empleados = $query->orderBy('created_at', 'desc')->get()->map(function (User $u) {
            $staffRole = $u->roles->first(fn ($r) => in_array($r->nombre, $this->staffRoleNombres(), true));

            return [
                'id'                 => $u->id,
                'name'               => $u->name,
                'email'              => $u->email,
                'username'           => $u->username,
                'activo'             => (bool) $u->activo,
                'puesto'             => $staffRole ? strtolower($staffRole->nombre) : null,
                'tipo_empleado'      => $u->tipo_empleado,
                'salario_base'       => $u->salario_base,
                'salario_por_hora'   => $u->salario_por_hora,
                'comision_por_venta' => $u->comision_por_venta,
                'fecha_contratacion' => $u->fecha_contratacion,
                'roles'              => $u->roles->map(fn($r) => [
                    'id'     => $r->id,
                    'nombre' => $r->nombre,
                ]),
                'restaurante_activo' => $u->restauranteActivo ? [
                    'id'     => $u->restauranteActivo->id,
                    'nombre' => $u->restauranteActivo->nombre,
                ] : null,
                'created_at'         => $u->created_at,
                'updated_at'         => $u->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $empleados,
        ]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

    /**
     * Alta de personal
     * POST /api/empleados
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $restaurante = Restaurante::find($restauranteId);
            if (!$restaurante) {
                return response()->json(['success' => false, 'message' => 'Restaurante no encontrado'], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8|confirmed',
                'rol_id' => 'required|exists:roles,id',
                'tipo_empleado' => 'nullable|in:cocina,mesero,caja,administrativo,gerente',
                'salario_base' => 'nullable|numeric|min:0',
                'comision_por_venta' => 'nullable|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $role = Role::find($request->rol_id);
            if (!$role || !in_array($role->nombre, $this->staffRoleNombres(), true)) {
                return response()->json(['success' => false, 'message' => 'El rol debe ser de personal (MESERO, COCINA, CAJA o ADMIN)'], 422);
            }

            $nuevo = DB::transaction(function () use ($request, $restaurante, $restauranteId) {
                $email = 'emp_' . $restaurante->propietario_id . '_' . Str::random(8) . '@sin-correo.local';

                // Determinar salario base
                $salarioBase = $request->input('salario_base', 0);
                if ($salarioBase == 0) {
                    $salarioBase = match ($request->input('tipo_empleado', 'cocina')) {
                        'gerente' => 12000,
                        'administrativo' => 10000,
                        'cocina' => 8000,
                        'mesero' => 6000,
                        'caja' => 7000,
                        default => 8000,
                    };
                }

                $empleadoUser = User::create([
                    'propietario_id' => $restaurante->propietario_id,
                    'name' => $request->name,
                    'email' => $email,
                    'username' => 'tmp_' . Str::random(10),
                    'password' => Hash::make($request->password),
                    'restaurante_activo' => $restauranteId,
                    'tipo_empleado' => $request->input('tipo_empleado'),
                    'salario_base' => $salarioBase,
                    'salario_por_hora' => $salarioBase / 160,
                    'comision_por_venta' => $request->input('comision_por_venta', $request->input('tipo_empleado') === 'mesero' ? 5 : 0),
                    'fecha_contratacion' => now()->toDateString(),
                ]);

                $empleadoUser->update(['username' => $restaurante->propietario_id . $empleadoUser->id]);

                $empleadoUser->roles()->attach($request->rol_id);
                $empleadoUser->restaurantes()->syncWithoutDetaching([$restauranteId]);

                return $empleadoUser->load('roles');
            });

            return response()->json([
                'success' => true,
                'message' => 'Empleado creado correctamente',
                'data' => $nuevo,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/empleados/{empleado}
     */
    public function show(Request $request, $empleadoId)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $empleado = $this->findStaffUser((int) $empleadoId, $restauranteId);

            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $staffRole = $empleado->roles->first(fn ($r) => in_array($r->nombre, $this->staffRoleNombres(), true));

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $empleado->id,
                    'name' => $empleado->name,
                    'email' => $empleado->email,
                    'username' => $empleado->username,
                    'activo' => (bool) $empleado->activo,
                    'puesto' => $staffRole ? strtolower($staffRole->nombre) : null,
                    'tipo_empleado' => $empleado->tipo_empleado,
                    'salario_base' => $empleado->salario_base,
                    'salario_por_hora' => $empleado->salario_por_hora,
                    'comision_por_venta' => $empleado->comision_por_venta,
                    'fecha_contratacion' => $empleado->fecha_contratacion,
                    'roles' => $empleado->roles,
                    'created_at' => $empleado->created_at,
                    'updated_at' => $empleado->updated_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/empleados/{empleado}
     */
    public function update(Request $request, $empleadoId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $empleado = $this->findStaffUser((int) $empleadoId, $restauranteId);

            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'password' => 'sometimes|string|min:8|confirmed',
                'rol_id' => 'sometimes|exists:roles,id',
                'activo' => 'sometimes|boolean',
                'tipo_empleado' => 'nullable|in:cocina,mesero,caja,administrativo,gerente',
                'salario_base' => 'nullable|numeric|min:0',
                'comision_por_venta' => 'nullable|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            if ($request->filled('rol_id')) {
                $role = Role::find($request->rol_id);
                if (!$role || !in_array($role->nombre, $this->staffRoleNombres(), true)) {
                    return response()->json(['success' => false, 'message' => 'El rol debe ser de personal (MESERO, COCINA, CAJA o ADMIN)'], 422);
                }
            }

            DB::transaction(function () use ($request, $empleado) {
                if ($request->filled('name')) {
                    $empleado->name = $request->name;
                }
                if ($request->filled('password')) {
                    $empleado->password = Hash::make($request->password);
                }
                if ($request->has('activo')) {
                    $empleado->activo = filter_var($request->activo, FILTER_VALIDATE_BOOLEAN);
                }
                if ($request->filled('tipo_empleado')) {
                    $empleado->tipo_empleado = $request->tipo_empleado;
                }
                if ($request->filled('salario_base')) {
                    $empleado->salario_base = $request->salario_base;
                    // Solo recalcular por hora si no se envió explícitamente el valor por hora
                    if (!$request->filled('salario_por_hora')) {
                        $empleado->salario_por_hora = $request->salario_base / 160;
                    }
                }
                if ($request->filled('salario_por_hora')) {
                    $empleado->salario_por_hora = $request->salario_por_hora;
                }
                if ($request->filled('comision_por_venta')) {
                    $empleado->comision_por_venta = $request->comision_por_venta;
                }
                $empleado->save();

                if ($request->filled('rol_id')) {
                    $empleado->roles()->sync([$request->rol_id]);
                }
            });

            $empleado->load('roles');
            $staffRole = $empleado->roles->first(fn ($r) => in_array($r->nombre, $this->staffRoleNombres(), true));

            return response()->json([
                'success' => true,
                'message' => 'Empleado actualizado correctamente',
                'data' => [
                    'id' => $empleado->id,
                    'name' => $empleado->name,
                    'email' => $empleado->email,
                    'username' => $empleado->username,
                    'activo' => (bool) $empleado->activo,
                    'puesto' => $staffRole ? strtolower($staffRole->nombre) : null,
                    'tipo_empleado' => $empleado->tipo_empleado,
                    'salario_base' => $empleado->salario_base,
                    'salario_por_hora' => $empleado->salario_por_hora,
                    'comision_por_venta' => $empleado->comision_por_venta,
                    'fecha_contratacion' => $empleado->fecha_contratacion,
                    'roles' => $empleado->roles,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Alternar estado activo/inactivo
     * PATCH /api/empleados/{empleado}/toggle-activo
     */
    public function toggleActivo(Request $request, $empleadoId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $empleado = $this->findStaffUser((int) $empleadoId, $restauranteId);

            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $empleado->activo = !$empleado->activo;
            $empleado->save();

            return response()->json([
                'success' => true,
                'message' => 'Estado del empleado actualizado: ' . ($empleado->activo ? 'Activo' : 'Inactivo'),
                'data' => [
                    'id' => $empleado->id,
                    'activo' => (bool) $empleado->activo,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar empleado (desvincular de sucursal)
     * DELETE /api/empleados/{empleado}
     */
    public function destroy(Request $request, $empleadoId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $empleado = $this->findStaffUser((int) $empleadoId, $restauranteId);

            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $empleado->restaurantes()->detach($restauranteId);

            return response()->json([
                'success' => true,
                'message' => 'Empleado desvinculado de la sucursal correctamente',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/asistencias
     */
    public function registrarAsistencia(Request $request)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $userId = $this->resolveUserId($request);

            $validator = Validator::make(array_merge($request->all(), ['user_id' => $userId]), [
                'user_id' => 'required|exists:users,id',
                'fecha' => 'required|date',
                'hora_entrada' => 'required|date_format:H:i',
                'hora_salida' => 'nullable|date_format:H:i',
                'ventas_generadas' => 'nullable|numeric|min:0',
                'tipo_registro' => 'nullable|in:normal,manual,sistema',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            if (!$this->findStaffUser($userId, $restauranteId)) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado en esta sucursal'], 404);
            }

            $horasTrabajadas = 0.0;
            if ($request->filled('hora_salida')) {
                $entrada = Carbon::parse($request->fecha . ' ' . $request->hora_entrada);
                $salida = Carbon::parse($request->fecha . ' ' . $request->hora_salida);
                if ($salida->lt($entrada)) {
                    $salida->addDay();
                }
                $horasTrabajadas = $entrada->diffInMinutes($salida) / 60;
            }

            $asistencia = DB::transaction(function () use ($request, $restauranteId, $userId, $horasTrabajadas) {
                return Asistencia::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'fecha' => $request->fecha,
                    ],
                    [
                        'restaurante_id' => $restauranteId,
                        'hora_entrada' => $request->hora_entrada,
                        'hora_salida' => $request->hora_salida,
                        'horas_trabajadas' => round($horasTrabajadas, 2),
                        'ventas_generadas' => $request->input('ventas_generadas', 0),
                        'tipo_registro' => $request->input('tipo_registro', 'manual'),
                        'ip_registro' => $request->ip(),
                    ]
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Asistencia registrada correctamente',
                'data' => $asistencia,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/asistencias/empleado/{empleado}
     */
    public function getAsistencias(Request $request, $empleadoId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            if (!$this->findStaffUser((int) $empleadoId, $restauranteId)) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $query = Asistencia::where('user_id', (int) $empleadoId)
                ->where('restaurante_id', $restauranteId);

            if ($request->filled('fecha_desde')) {
                $query->whereDate('fecha', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('fecha', '<=', $request->fecha_hasta);
            }

            $asistencias = $query->orderBy('fecha', 'desc')->get();

            $totalHoras = $asistencias->sum('horas_trabajadas');
            $totalVentas = $asistencias->sum('ventas_generadas');

            return response()->json([
                'success' => true,
                'data' => [
                    'asistencias' => $asistencias,
                    'resumen' => [
                        'total_horas' => round($totalHoras, 2),
                        'total_ventas' => round($totalVentas, 2),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // MÓDULO DE NÓMINAS COMPLETO
    // ==========================================

    /**
     * Generar nómina para un empleado
     * POST /api/nominas/generar
     */
    public function generarNomina(Request $request)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $userId = $this->resolveUserId($request);

            $validator = Validator::make(array_merge($request->all(), ['user_id' => $userId]), [
                'user_id' => 'required|exists:users,id',
                'periodo_inicio' => 'required|date',
                'periodo_fin' => 'required|date|after_or_equal:periodo_inicio',
                'valor_hora' => 'nullable|numeric|min:0',
                'salario_base' => 'nullable|numeric|min:0',
                'comision_ventas' => 'nullable|numeric|min:0',
                'bonos' => 'nullable|numeric|min:0',
                'descuentos' => 'nullable|numeric|min:0',
                'observaciones' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            if (!$this->findStaffUser($userId, $restauranteId)) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $empleado = User::find($userId);
            
            // Calcular horas trabajadas en el período
            $horasTotales = (float) Asistencia::where('user_id', $userId)
                ->where('restaurante_id', $restauranteId)
                ->whereDate('fecha', '>=', $request->periodo_inicio)
                ->whereDate('fecha', '<=', $request->periodo_fin)
                ->sum('horas_trabajadas');

            // Calcular ventas generadas en el período
            $ventasGeneradas = (float) Asistencia::where('user_id', $userId)
                ->where('restaurante_id', $restauranteId)
                ->whereDate('fecha', '>=', $request->periodo_inicio)
                ->whereDate('fecha', '<=', $request->periodo_fin)
                ->sum('ventas_generadas');

            // Obtener configuración de nómina
            $config = ConfiguracionNomina::where('restaurante_id', $restauranteId)->first();
            
            // Usar valores de entrada o los del empleado/configuración
            $valorHora = $request->filled('valor_hora') 
                ? (float) $request->valor_hora 
                : ($empleado->salario_por_hora > 0 ? $empleado->salario_por_hora : ($config->valor_hora_por_defecto ?? 50));
            
            $salarioBase = $request->filled('salario_base') 
                ? (float) $request->salario_base 
                : ($empleado->salario_base > 0 ? $empleado->salario_base : ($config->salario_base_por_defecto ?? 8000));
            
            $pagoHoras = round($valorHora * $horasTotales, 2);
            
            $comisionPorcentaje = $empleado->comision_por_venta > 0 
                ? $empleado->comision_por_venta 
                : ($config->porcentaje_comision_ventas ?? 5);
            
            $comisionVentas = $request->filled('comision_ventas')
                ? (float) $request->comision_ventas
                : round($ventasGeneradas * ($comisionPorcentaje / 100), 2);
            
            $bonos = (float) $request->input('bonos', 0);
            $descuentos = (float) $request->input('descuentos', 0);

            $nomina = DB::transaction(function () use (
                $userId,
                $restauranteId,
                $request,
                $horasTotales,
                $valorHora,
                $pagoHoras,
                $salarioBase,
                $comisionVentas,
                $bonos,
                $descuentos
            ) {
                $nomina = Nomina::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'periodo_inicio' => $request->periodo_inicio,
                        'periodo_fin' => $request->periodo_fin,
                    ],
                    [
                        'restaurante_id' => $restauranteId,
                        'horas_totales' => round($horasTotales, 2),
                        'salario_base' => $salarioBase,
                        'valor_hora' => round($valorHora, 2),
                        'pago_horas' => $pagoHoras,
                        'comision_ventas' => $comisionVentas,
                        'bonos' => $bonos,
                        'descuentos' => $descuentos,
                        'observaciones' => $request->input('observaciones'),
                        'estado' => 'PENDIENTE',
                    ]
                );
                
                $nomina->pago_total = round(
                    (float) $nomina->salario_base + 
                    (float) $nomina->comision_ventas + 
                    (float) $nomina->bonos - 
                    (float) $nomina->descuentos,
                    2
                );
                $nomina->save();

                return $nomina->fresh();
            });

            return response()->json([
                'success' => true,
                'message' => 'Nómina generada correctamente',
                'data' => $nomina,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener listado de nóminas
     * GET /api/nominas
     */
    public function getNominas(Request $request)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $query = Nomina::where('restaurante_id', $restauranteId)->with('user');

            $filterUserId = $this->resolveUserId($request);
            if ($filterUserId) {
                $query->where('user_id', $filterUserId);
            }

            if ($request->filled('estado')) {
                $query->where('estado', strtoupper($request->estado));
            }

            if ($request->filled('fecha_desde')) {
                $query->whereDate('periodo_fin', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('periodo_fin', '<=', $request->fecha_hasta);
            }

            $perPage = $request->input('per_page', 20);
            $nominas = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $nominas,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener una nómina específica
     * GET /api/nominas/{id}
     */
    public function getNomina(Request $request, $nominaId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('id', $nominaId)
                ->where('restaurante_id', $restauranteId)
                ->with('user')
                ->first();

            if (!$nomina) {
                return response()->json(['success' => false, 'message' => 'Nómina no encontrada'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $nomina,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * EDITAR/ACTUALIZAR nómina existente
     * PUT /api/nominas/{id}
     */
    public function updateNomina(Request $request, $nominaId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('id', $nominaId)
                ->where('restaurante_id', $restauranteId)
                ->first();

            if (!$nomina) {
                return response()->json(['success' => false, 'message' => 'Nómina no encontrada'], 404);
            }

            $validator = Validator::make($request->all(), [
                'periodo_inicio' => 'sometimes|date',
                'periodo_fin' => 'sometimes|date|after_or_equal:periodo_inicio',
                'horas_totales' => 'nullable|numeric|min:0',
                'salario_base' => 'nullable|numeric|min:0',
                'valor_hora' => 'nullable|numeric|min:0',
                'pago_horas' => 'nullable|numeric|min:0',
                'comision_ventas' => 'nullable|numeric|min:0',
                'bonos' => 'nullable|numeric|min:0',
                'descuentos' => 'nullable|numeric|min:0',
                'observaciones' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            DB::transaction(function () use ($request, $nomina) {
                $camposActualizar = [];
                
                if ($request->has('periodo_inicio')) {
                    $camposActualizar['periodo_inicio'] = $request->periodo_inicio;
                }
                if ($request->has('periodo_fin')) {
                    $camposActualizar['periodo_fin'] = $request->periodo_fin;
                }
                if ($request->has('horas_totales')) {
                    $camposActualizar['horas_totales'] = $request->horas_totales;
                }
                if ($request->has('salario_base')) {
                    $camposActualizar['salario_base'] = $request->salario_base;
                }
                if ($request->has('valor_hora')) {
                    $camposActualizar['valor_hora'] = $request->valor_hora;
                }
                if ($request->has('pago_horas')) {
                    $camposActualizar['pago_horas'] = $request->pago_horas;
                }
                if ($request->has('comision_ventas')) {
                    $camposActualizar['comision_ventas'] = $request->comision_ventas;
                }
                if ($request->has('bonos')) {
                    $camposActualizar['bonos'] = $request->bonos;
                }
                if ($request->has('descuentos')) {
                    $camposActualizar['descuentos'] = $request->descuentos;
                }
                if ($request->has('observaciones')) {
                    $camposActualizar['observaciones'] = $request->observaciones;
                }

                if (!empty($camposActualizar)) {
                    $nomina->update($camposActualizar);
                }
                
                // Recalcular pago total
                $nomina->pago_total = round(
                    (float) $nomina->salario_base + 
                    (float) $nomina->comision_ventas + 
                    (float) $nomina->bonos - 
                    (float) $nomina->descuentos,
                    2
                );
                $nomina->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Nómina actualizada correctamente',
                'data' => $nomina->fresh()->load('user'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar estado de nómina (PENDIENTE, PAGADA, ANULADA)
     * PUT /api/nominas/{id}/estado
     */
    public function actualizarEstadoNomina(Request $request, $nominaId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('id', $nominaId)
                ->where('restaurante_id', $restauranteId)
                ->first();

            if (!$nomina) {
                return response()->json(['success' => false, 'message' => 'Nómina no encontrada'], 404);
            }

            $validator = Validator::make($request->all(), [
                'estado' => 'required|in:PENDIENTE,PAGADA,ANULADA',
                'observaciones' => 'nullable|string',
                'fecha_pago' => 'nullable|date',
                'metodo_pago' => 'nullable|string|max:50',
                'referencia_pago' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $updateData = ['estado' => strtoupper($request->estado)];
            
            if ($request->filled('observaciones')) {
                $updateData['observaciones'] = $request->observaciones;
            }
            if ($request->filled('fecha_pago')) {
                $updateData['fecha_pago'] = $request->fecha_pago;
            }
            if ($request->filled('metodo_pago')) {
                $updateData['metodo_pago'] = $request->metodo_pago;
            }
            if ($request->filled('referencia_pago')) {
                $updateData['referencia_pago'] = $request->referencia_pago;
            }

            $nomina->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Estado de nómina actualizado a ' . $updateData['estado'],
                'data' => $nomina->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Eliminar nómina (soft delete)
     * DELETE /api/nominas/{id}
     */
    public function deleteNomina(Request $request, $nominaId)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $nomina = Nomina::where('id', $nominaId)
                ->where('restaurante_id', $restauranteId)
                ->first();

            if (!$nomina) {
                return response()->json(['success' => false, 'message' => 'Nómina no encontrada'], 404);
            }

            $nomina->delete();

            return response()->json([
                'success' => true,
                'message' => 'Nómina eliminada correctamente',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener resumen de nóminas
     * GET /api/nominas/resumen
     */
    public function resumenNominas(Request $request)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $query = Nomina::where('restaurante_id', $restauranteId);

            if ($request->filled('fecha_desde')) {
                $query->whereDate('periodo_fin', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('periodo_inicio', '<=', $request->fecha_hasta);
            }

            $nominas = $query->get();

            $resumen = [
                'total_nominas' => $nominas->count(),
                'total_pagado' => round($nominas->where('estado', 'PAGADA')->sum('pago_total'), 2),
                'total_pendiente' => round($nominas->where('estado', 'PENDIENTE')->sum('pago_total'), 2),
                'total_horas' => round($nominas->sum('horas_totales'), 2),
                'total_comisiones' => round($nominas->sum('comision_ventas'), 2),
                'total_bonos' => round($nominas->sum('bonos'), 2),
                'total_descuentos' => round($nominas->sum('descuentos'), 2),
                'por_estado' => [
                    'PENDIENTE' => $nominas->where('estado', 'PENDIENTE')->count(),
                    'PAGADA' => $nominas->where('estado', 'PAGADA')->count(),
                    'ANULADA' => $nominas->where('estado', 'ANULADA')->count()
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $resumen,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener configuración de nómina del restaurante
     * GET /api/nomina/configuracion
     */
    public function getConfiguracionNomina(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó sucursal activa'], 400);
            }

            $config = ConfiguracionNomina::where('restaurante_id', $restauranteId)->first();

            if (!$config) {
                $config = ConfiguracionNomina::create([
                    'restaurante_id' => $restauranteId,
                    'salario_base_por_defecto' => 8000.00,
                    'valor_hora_por_defecto' => 50.00,
                    'porcentaje_comision_ventas' => 5.00,
                    'dias_pago' => '15,30',
                    'activo' => 1
                ]);
            }

            return response()->json(['success' => true, 'data' => $config]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar configuración de nómina
     * PUT /api/nomina/configuracion
     */
    public function updateConfiguracionNomina(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteId = (int) $this->getRestauranteId($user);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó sucursal activa'], 400);
            }

            $validator = Validator::make($request->all(), [
                'salario_base_por_defecto' => 'nullable|numeric|min:0',
                'valor_hora_por_defecto' => 'nullable|numeric|min:0',
                'porcentaje_comision_ventas' => 'nullable|numeric|min:0|max:100',
                'dias_pago' => 'nullable|string',
                'activo' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $config = ConfiguracionNomina::where('restaurante_id', $restauranteId)->first();

            if (!$config) {
                $config = new ConfiguracionNomina();
                $config->restaurante_id = $restauranteId;
            }

            $config->fill($request->only([
                'salario_base_por_defecto',
                'valor_hora_por_defecto',
                'porcentaje_comision_ventas',
                'dias_pago',
                'activo'
            ]));

            $config->save();

            return response()->json(['success' => true, 'data' => $config, 'message' => 'Configuración actualizada']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

   /**
 * GET /api/kpis/meseros
 * Obtener KPIs de rendimiento de meseros
 */
public function getKpiMeseros(Request $request)
{
    try {
        $authUser = $request->user();
        $restauranteId = (int) $this->getRestauranteId($authUser);

        if (empty($restauranteId)) {
            return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
        }

        $validator = Validator::make($request->all(), [
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date',
            'mesero_id' => 'nullable|exists:users,id',
            'top' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Obtener meseros del restaurante
        $query = $this->empleadosBaseQuery($restauranteId)
            ->whereHas('roles', fn ($q) => $q->where('nombre', 'MESERO'));

        if ($request->filled('mesero_id')) {
            $query->where('users.id', $request->mesero_id);
        }

        $empleados = $query->get();

        $data = $empleados->map(function (User $empleado) use ($request, $restauranteId) {
            // Query de asistencias
            $asistenciasQuery = Asistencia::where('user_id', $empleado->id)
                ->where('restaurante_id', $restauranteId);

            if ($request->filled('fecha_desde')) {
                $asistenciasQuery->whereDate('fecha', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $asistenciasQuery->whereDate('fecha', '<=', $request->fecha_hasta);
            }

            $asistencias = $asistenciasQuery->get();

            $horasTotales = $asistencias->sum('horas_trabajadas');
            $ventasGeneradas = $asistencias->sum('ventas_generadas');
            $turnos = $asistencias->count();

            // Ventas reales desde órdenes (más preciso)
            // Obtener mesas asignadas al mesero
            $mesasAsignadas = \App\Models\MesaMesero::where('user_id', $empleado->id)
                ->where('restaurante_id', $restauranteId)
                ->pluck('numero_mesa')
                ->toArray();

            $ventasReales = 0;
            $ordenesCompletadas = 0;

            if (!empty($mesasAsignadas)) {
                $ordenesQuery = \App\Models\Orden::where('restaurante_id', $restauranteId)
                    ->whereIn('estado', ['CERRADA', 'ENTREGADA'])
                    ->whereIn('mesa', $mesasAsignadas);

                if ($request->filled('fecha_desde')) {
                    $ordenesQuery->whereDate('created_at', '>=', $request->fecha_desde);
                }
                if ($request->filled('fecha_hasta')) {
                    $ordenesQuery->whereDate('created_at', '<=', $request->fecha_hasta);
                }

                $ventasReales = $ordenesQuery->sum('total');
                $ordenesCompletadas = $ordenesQuery->count();
            }

            // Calcular indicadores
            $ventasPorHora = $horasTotales > 0 ? round($ventasReales / $horasTotales, 2) : 0;
            $ticketPromedio = $ordenesCompletadas > 0 ? round($ventasReales / $ordenesCompletadas, 2) : 0;
            $eficiencia = $turnos > 0 ? round($ventasReales / $turnos, 2) : 0;
            
            // Calcular comisión estimada
            $comisionEstimada = 0;
            if ($empleado->comision_por_venta > 0) {
                $comisionEstimada = round($ventasReales * ($empleado->comision_por_venta / 100), 2);
            }

        $satisfaccionStats = DB::table('satisfacciones')
    ->where('user_id', $empleado->id)
    ->where('restaurante_id', $restauranteId)
    ->when($request->filled('fecha_desde'), fn($q) =>
        $q->where('created_at', '>=', $request->fecha_desde . ' 00:00:00'))
    ->when($request->filled('fecha_hasta'), fn($q) =>
        $q->where('created_at', '<=', $request->fecha_hasta . ' 23:59:59'))
    ->selectRaw('
        COUNT(*) as total,
        ROUND(AVG(calificacion), 2) as promedio,
        SUM(CASE WHEN calificacion = 5 THEN 1 ELSE 0 END) as cinco_estrellas,
        SUM(CASE WHEN calificacion = 1 THEN 1 ELSE 0 END) as una_estrella
    ')
    ->first();

$satisfaccion = $satisfaccionStats->total > 0 ? [
    'promedio'        => $satisfaccionStats->promedio,
    'total'           => $satisfaccionStats->total,
    'cinco_estrellas' => $satisfaccionStats->cinco_estrellas,
    'una_estrella'    => $satisfaccionStats->una_estrella,
    'semaforo'        => match(true) {
        $satisfaccionStats->promedio >= 4.0 => 'verde',
        $satisfaccionStats->promedio >= 3.0 => 'amarillo',
        default                             => 'rojo',
    },
] : null;

            return [
                'empleado' => $empleado->name,
                'empleado_id' => $empleado->id,
                'user_id' => $empleado->id,
                'puesto' => 'mesero',
                'tipo_empleado' => $empleado->tipo_empleado,
                'salario_base' => $empleado->salario_base,
                'comision_por_venta' => $empleado->comision_por_venta,
                
                // Métricas de asistencia
                'horas_trabajadas' => round($horasTotales, 2),
                'turnos' => $turnos,
                'dias_trabajados' => $turnos,
                'promedio_horas_dia' => $turnos > 0 ? round($horasTotales / $turnos, 2) : 0,
                
                // Métricas de ventas
                'ventas_generadas' => round($ventasReales, 2),
                'ventas_por_hora' => $ventasPorHora,
                'ticket_promedio' => $ticketPromedio,
                'ventas_por_turno' => $eficiencia,
                'ordenes_atendidas' => $ordenesCompletadas,
                'mesas_atendidas' => count($mesasAsignadas),
                
                // Comisiones y compensación
                'comision_estimada' => $comisionEstimada,
                'ingreso_total_estimado' => round($empleado->salario_base + $comisionEstimada, 2),
                
                // Eficiencia
                'eficiencia_ventas' => $ordenesCompletadas > 0 ? round($ventasReales / $ordenesCompletadas, 2) : 0,
                'satisfaccion' => $satisfaccion,
            ];
        });

        // Ordenar por ventas generadas
        $data = $data->sortByDesc('ventas_generadas')->values();

        // Aplicar límite de top si se especifica
        $top = $request->input('top');
        if ($top && $top > 0) {
            $data = $data->take($top);
        }

        // Calcular totales generales
        $totalVentas = $data->sum('ventas_generadas');
        $totalHoras = $data->sum('horas_trabajadas');
        $totalOrdenes = $data->sum('ordenes_atendidas');
        $totalComisiones = $data->sum('comision_estimada');
        
        $promedioTicket = $totalOrdenes > 0 ? round($totalVentas / $totalOrdenes, 2) : 0;
        $productividadGeneral = $totalHoras > 0 ? round($totalVentas / $totalHoras, 2) : 0;

        // Ranking de meseros
        $ranking = $data->map(function($item, $index) {
            return [
                'posicion' => $index + 1,
                'mesero_id' => $item['empleado_id'],
                'mesero_nombre' => $item['empleado'],
                'total_ventas' => $item['ventas_generadas'],
                'total_ordenes' => $item['ordenes_atendidas'],
                'ticket_promedio' => $item['ticket_promedio'],
            ];
        });

        // Mejor mesero del período
        $mejorMesero = $data->isNotEmpty() ? $data->first() : null;

        // Datos para gráficos
        $graficoData = [
            'labels' => $data->pluck('empleado')->toArray(),
            'ventas' => $data->pluck('ventas_generadas')->toArray(),
            'ordenes' => $data->pluck('ordenes_atendidas')->toArray(),
            'horas' => $data->pluck('horas_trabajadas')->toArray(),
            'ticket_promedio' => $data->pluck('ticket_promedio')->toArray(),
            'comisiones' => $data->pluck('comision_estimada')->toArray(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'meseros' => $data,
                'ranking' => $ranking,
                'top_mesero' => $mejorMesero ? [
                    'id' => $mejorMesero['empleado_id'],
                    'nombre' => $mejorMesero['empleado'],
                    'total_ventas' => $mejorMesero['ventas_generadas'],
                    'total_ordenes' => $mejorMesero['ordenes_atendidas'],
                    'ticket_promedio' => $mejorMesero['ticket_promedio'],
                    'horas_trabajadas' => $mejorMesero['horas_trabajadas']
                ] : null,
                'resumen' => [
                    'total_meseros' => $data->count(),
                    'total_ventas' => round($totalVentas, 2),
                    'total_horas' => round($totalHoras, 2),
                    'total_ordenes' => $totalOrdenes,
                    'total_comisiones' => round($totalComisiones, 2),
                    'promedio_ticket' => $promedioTicket,
                    'productividad_general' => $productividadGeneral,
                    'promedio_ventas_por_mesero' => $data->count() > 0 ? round($totalVentas / $data->count(), 2) : 0,
                    'periodo' => [
                        'desde' => $request->filled('fecha_desde') ? $request->fecha_desde : null,
                        'hasta' => $request->filled('fecha_hasta') ? $request->fecha_hasta : null,
                    ]
                ],
                'data_para_grafico' => $graficoData,
            ],
        ]);

    } catch (\Exception $e) {
        \Log::error('Error en getKpiMeseros: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'restaurante_id' => $restauranteId ?? null
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener KPIs de meseros: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * GET /api/kpis/meseros/{id}
     * Perfil de rendimiento INDIVIDUAL de un mesero específico.
     *
     * Incluye:
     *  - Métricas base (ventas, órdenes, horas, ticket promedio, comisión)
     *  - Tendencia diaria de ventas (para gráfico de línea)
     *  - Horario pico (hora con más órdenes cerradas)
     *  - Top 5 productos más pedidos en sus mesas
     *  - Racha de asistencia consecutiva
     *  - Comparativa contra el promedio del equipo de meseros
     *  - Evaluación de satisfacción
     */
    public function getKpiMeseroIndividual(Request $request, $id)
    {
        try {
            $authUser      = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
                'id'          => 'required|integer|exists:users,id',
                'fecha_desde' => 'nullable|date',
                'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            // ── 1. Obtener el mesero ──────────────────────────────────────────
            $empleado = $this->empleadosBaseQuery($restauranteId)
                ->whereHas('roles', fn ($q) => $q->where('nombre', 'MESERO'))
                ->where('users.id', $id)
                ->first();

            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'Mesero no encontrado en esta sucursal'], 404);
            }

            // ── 2. Asistencias del período ────────────────────────────────────
            $asistenciasQuery = Asistencia::where('user_id', $empleado->id)
                ->where('restaurante_id', $restauranteId);

            if ($request->filled('fecha_desde')) {
                $asistenciasQuery->whereDate('fecha', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $asistenciasQuery->whereDate('fecha', '<=', $request->fecha_hasta);
            }

            $asistencias     = $asistenciasQuery->orderBy('fecha')->get();
            $horasTotales    = $asistencias->sum('horas_trabajadas');
            $turnos          = $asistencias->count();

            // ── 3. Mesas asignadas ───────────────────────────────────────────
            $mesasAsignadas = \App\Models\MesaMesero::where('user_id', $empleado->id)
                ->where('restaurante_id', $restauranteId)
                ->pluck('numero_mesa')
                ->toArray();

            // ── 4. Órdenes cerradas en esas mesas ───────────────────────────
            $ordenesBase = \App\Models\Orden::where('restaurante_id', $restauranteId)
                ->whereIn('estado', ['CERRADA', 'ENTREGADA']);

            if (!empty($mesasAsignadas)) {
                $ordenesBase->whereIn('mesa', $mesasAsignadas);
            } else {
                // Sin mesas asignadas → sin órdenes
                $ordenesBase->whereRaw('1 = 0');
            }

            if ($request->filled('fecha_desde')) {
                $ordenesBase->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $ordenesBase->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            $ordenes           = $ordenesBase->get();
            $ventasReales      = $ordenes->sum('total');
            $ordenesCompletadas = $ordenes->count();

            // ── 5. Métricas base ─────────────────────────────────────────────
            $ventasPorHora   = $horasTotales > 0  ? round($ventasReales / $horasTotales, 2) : 0;
            $ticketPromedio  = $ordenesCompletadas > 0 ? round($ventasReales / $ordenesCompletadas, 2) : 0;
            $ventasPorTurno  = $turnos > 0          ? round($ventasReales / $turnos, 2) : 0;
            $comisionEstimada = $empleado->comision_por_venta > 0
                ? round($ventasReales * ($empleado->comision_por_venta / 100), 2)
                : 0;

            // ── 6. Tendencia diaria de ventas (para gráfico de línea) ────────
            $tendenciaDiariaRaw = \App\Models\Orden::where('restaurante_id', $restauranteId)
                ->whereIn('estado', ['CERRADA', 'ENTREGADA'])
                ->when(!empty($mesasAsignadas), fn ($q) => $q->whereIn('mesa', $mesasAsignadas))
                ->when($request->filled('fecha_desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->fecha_hasta))
                ->selectRaw('DATE(created_at) as fecha, COUNT(*) as ordenes, ROUND(SUM(total),2) as ventas')
                ->groupByRaw('DATE(created_at)')
                ->orderBy('fecha')
                ->get();

            $tendenciaDiaria = $tendenciaDiariaRaw->map(fn ($r) => [
                'fecha'   => $r->fecha,
                'ordenes' => (int) $r->ordenes,
                'ventas'  => (float) $r->ventas,
            ])->values();

            // ── 7. Horario pico (hora con más órdenes) ───────────────────────
            $horariosRaw = \App\Models\Orden::where('restaurante_id', $restauranteId)
                ->whereIn('estado', ['CERRADA', 'ENTREGADA'])
                ->when(!empty($mesasAsignadas), fn ($q) => $q->whereIn('mesa', $mesasAsignadas))
                ->when($request->filled('fecha_desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->fecha_hasta))
                ->selectRaw('HOUR(created_at) as hora, COUNT(*) as total_ordenes, ROUND(SUM(total),2) as total_ventas')
                ->groupByRaw('HOUR(created_at)')
                ->orderByDesc('total_ordenes')
                ->get();

            $horarioPico = $horariosRaw->first()
                ? [
                    'hora'          => (int) $horariosRaw->first()->hora,
                    'label'         => sprintf('%02d:00 - %02d:59', $horariosRaw->first()->hora, $horariosRaw->first()->hora),
                    'ordenes'       => (int) $horariosRaw->first()->total_ordenes,
                    'ventas'        => (float) $horariosRaw->first()->total_ventas,
                ]
                : null;

            $distribucionHoraria = $horariosRaw->map(fn ($r) => [
                'hora'    => (int) $r->hora,
                'label'   => sprintf('%02d:00', $r->hora),
                'ordenes' => (int) $r->total_ordenes,
                'ventas'  => (float) $r->total_ventas,
            ])->values();

            // ── 8. Top 5 productos más pedidos en sus mesas ─────────────────
            $topProductos = DB::table('orden_detalles as od')
                ->join('ordenes as o', 'o.id', '=', 'od.orden_id')
                ->join('productos as p', 'p.id', '=', 'od.producto_id')
                ->where('o.restaurante_id', $restauranteId)
                ->whereIn('o.estado', ['CERRADA', 'ENTREGADA'])
                ->when(!empty($mesasAsignadas), fn ($q) => $q->whereIn('o.mesa', $mesasAsignadas))
                ->when($request->filled('fecha_desde'), fn ($q) => $q->whereDate('o.created_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn ($q) => $q->whereDate('o.created_at', '<=', $request->fecha_hasta))
                ->selectRaw('p.id as producto_id, p.nombre as producto, SUM(od.cantidad) as cantidad_total, ROUND(SUM(od.subtotal),2) as ingreso_total')
                ->groupBy('p.id', 'p.nombre')
                ->orderByDesc('cantidad_total')
                ->limit(5)
                ->get()
                ->map(fn ($r) => [
                    'producto_id'   => $r->producto_id,
                    'producto'      => $r->producto,
                    'cantidad'      => (float) $r->cantidad_total,
                    'ingreso_total' => (float) $r->ingreso_total,
                ])->values();

            // ── 9. Racha de asistencia consecutiva (días) ───────────────────
            $rachaActual = 0;
            $rachaMaxima = 0;
            $rachaTemp   = 0;
            $fechaAnterior = null;
            foreach ($asistencias as $asistencia) {
                $fechaActual = \Carbon\Carbon::parse($asistencia->fecha)->startOfDay();
                if ($fechaAnterior === null) {
                    $rachaTemp = 1;
                } else {
                    $diff = $fechaAnterior->diffInDays($fechaActual);
                    $rachaTemp = $diff === 1 ? $rachaTemp + 1 : 1;
                }
                if ($rachaTemp > $rachaMaxima) {
                    $rachaMaxima = $rachaTemp;
                }
                $fechaAnterior = $fechaActual;
            }
            // Racha actual: ¿está activa hasta hoy o ayer?
            if ($fechaAnterior !== null) {
                $hoy = \Carbon\Carbon::today();
                $diffFin = $fechaAnterior->diffInDays($hoy);
                $rachaActual = $diffFin <= 1 ? $rachaTemp : 0;
            }

            // ── 10. Satisfacción ─────────────────────────────────────────────
            $satisfaccionStats = DB::table('satisfacciones')
                ->where('user_id', $empleado->id)
                ->where('restaurante_id', $restauranteId)
                ->when($request->filled('fecha_desde'), fn ($q) =>
                    $q->where('created_at', '>=', $request->fecha_desde . ' 00:00:00'))
                ->when($request->filled('fecha_hasta'), fn ($q) =>
                    $q->where('created_at', '<=', $request->fecha_hasta . ' 23:59:59'))
                ->selectRaw('
                    COUNT(*) as total,
                    ROUND(AVG(calificacion), 2) as promedio,
                    SUM(CASE WHEN calificacion = 5 THEN 1 ELSE 0 END) as cinco_estrellas,
                    SUM(CASE WHEN calificacion = 4 THEN 1 ELSE 0 END) as cuatro_estrellas,
                    SUM(CASE WHEN calificacion = 3 THEN 1 ELSE 0 END) as tres_estrellas,
                    SUM(CASE WHEN calificacion = 2 THEN 1 ELSE 0 END) as dos_estrellas,
                    SUM(CASE WHEN calificacion = 1 THEN 1 ELSE 0 END) as una_estrella
                ')
                ->first();

            $satisfaccion = $satisfaccionStats->total > 0 ? [
                'promedio'          => (float) $satisfaccionStats->promedio,
                'total_calificaciones' => (int) $satisfaccionStats->total,
                'distribucion'      => [
                    '5' => (int) $satisfaccionStats->cinco_estrellas,
                    '4' => (int) $satisfaccionStats->cuatro_estrellas,
                    '3' => (int) $satisfaccionStats->tres_estrellas,
                    '2' => (int) $satisfaccionStats->dos_estrellas,
                    '1' => (int) $satisfaccionStats->una_estrella,
                ],
                'semaforo' => match (true) {
                    $satisfaccionStats->promedio >= 4.0 => 'verde',
                    $satisfaccionStats->promedio >= 3.0 => 'amarillo',
                    default                             => 'rojo',
                },
            ] : null;

            // ── 11. Comparativa vs. promedio del equipo ───────────────────────
            $todosMeseros = $this->empleadosBaseQuery($restauranteId)
                ->whereHas('roles', fn ($q) => $q->where('nombre', 'MESERO'))
                ->where('users.id', '!=', $empleado->id)
                ->pluck('users.id');

            [$equipoVentas, $equipoOrdenes, $equipoHoras] = [0, 0, 0];
            $totalCompaneros = $todosMeseros->count();

            if ($totalCompaneros > 0) {
                // Ventas del equipo (sin el mesero actual)
                $mesas_otros = \App\Models\MesaMesero::whereIn('user_id', $todosMeseros)
                    ->where('restaurante_id', $restauranteId)
                    ->pluck('numero_mesa')
                    ->toArray();

                $equipoVentas = \App\Models\Orden::where('restaurante_id', $restauranteId)
                    ->whereIn('estado', ['CERRADA', 'ENTREGADA'])
                    ->when(!empty($mesas_otros), fn ($q) => $q->whereIn('mesa', $mesas_otros))
                    ->when($request->filled('fecha_desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->fecha_desde))
                    ->when($request->filled('fecha_hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->fecha_hasta))
                    ->sum('total');

                $equipoOrdenes = \App\Models\Orden::where('restaurante_id', $restauranteId)
                    ->whereIn('estado', ['CERRADA', 'ENTREGADA'])
                    ->when(!empty($mesas_otros), fn ($q) => $q->whereIn('mesa', $mesas_otros))
                    ->when($request->filled('fecha_desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->fecha_desde))
                    ->when($request->filled('fecha_hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->fecha_hasta))
                    ->count();

                $equipoHoras = Asistencia::whereIn('user_id', $todosMeseros)
                    ->where('restaurante_id', $restauranteId)
                    ->when($request->filled('fecha_desde'), fn ($q) => $q->whereDate('fecha', '>=', $request->fecha_desde))
                    ->when($request->filled('fecha_hasta'), fn ($q) => $q->whereDate('fecha', '<=', $request->fecha_hasta))
                    ->sum('horas_trabajadas');
            }

            $promedioEquipoVentas  = $totalCompaneros > 0 ? round($equipoVentas / $totalCompaneros, 2) : 0;
            $promedioEquipoOrdenes = $totalCompaneros > 0 ? round($equipoOrdenes / $totalCompaneros, 2) : 0;
            $promedioEquipoHoras   = $totalCompaneros > 0 ? round($equipoHoras / $totalCompaneros, 2) : 0;

            $comparativa = [
                'ventas' => [
                    'mesero'        => round($ventasReales, 2),
                    'promedio_equipo' => $promedioEquipoVentas,
                    'diferencia'    => round($ventasReales - $promedioEquipoVentas, 2),
                    'pct_vs_equipo' => $promedioEquipoVentas > 0
                        ? round((($ventasReales - $promedioEquipoVentas) / $promedioEquipoVentas) * 100, 1)
                        : null,
                ],
                'ordenes' => [
                    'mesero'          => $ordenesCompletadas,
                    'promedio_equipo' => $promedioEquipoOrdenes,
                    'diferencia'      => round($ordenesCompletadas - $promedioEquipoOrdenes, 1),
                    'pct_vs_equipo'   => $promedioEquipoOrdenes > 0
                        ? round((($ordenesCompletadas - $promedioEquipoOrdenes) / $promedioEquipoOrdenes) * 100, 1)
                        : null,
                ],
                'horas' => [
                    'mesero'          => round($horasTotales, 2),
                    'promedio_equipo' => $promedioEquipoHoras,
                    'diferencia'      => round($horasTotales - $promedioEquipoHoras, 2),
                    'pct_vs_equipo'   => $promedioEquipoHoras > 0
                        ? round((($horasTotales - $promedioEquipoHoras) / $promedioEquipoHoras) * 100, 1)
                        : null,
                ],
            ];

            // ── 12. Semáforo general de rendimiento ───────────────────────────
            $score = 0;
            if ($comparativa['ventas']['pct_vs_equipo'] !== null) {
                $score += $comparativa['ventas']['pct_vs_equipo'];
            }
            if ($satisfaccion && $satisfaccion['promedio'] >= 4.0) $score += 20;
            if ($satisfaccion && $satisfaccion['promedio'] >= 3.0) $score += 10;

            $semaforoGeneral = match (true) {
                $score >= 10  => 'verde',
                $score >= -10 => 'amarillo',
                default       => 'rojo',
            };

            return response()->json([
                'success' => true,
                'modo'    => 'individual',
                'data'    => [
                    // Información del empleado
                    'empleado' => [
                        'id'                 => $empleado->id,
                        'nombre'             => $empleado->name,
                        'email'              => $empleado->email,
                        'tipo_empleado'      => $empleado->tipo_empleado,
                        'salario_base'       => (float) $empleado->salario_base,
                        'comision_por_venta' => (float) $empleado->comision_por_venta,
                        'mesas_asignadas'    => $mesasAsignadas,
                    ],

                    // Métricas del período
                    'metricas' => [
                        'periodo' => [
                            'desde' => $request->filled('fecha_desde') ? $request->fecha_desde : null,
                            'hasta' => $request->filled('fecha_hasta') ? $request->fecha_hasta : null,
                        ],
                        'horas_trabajadas'    => round($horasTotales, 2),
                        'turnos'              => $turnos,
                        'promedio_horas_dia'  => $turnos > 0 ? round($horasTotales / $turnos, 2) : 0,
                        'ventas_totales'      => round($ventasReales, 2),
                        'ventas_por_hora'     => $ventasPorHora,
                        'ventas_por_turno'    => $ventasPorTurno,
                        'ordenes_atendidas'   => $ordenesCompletadas,
                        'ticket_promedio'     => $ticketPromedio,
                        'comision_estimada'   => $comisionEstimada,
                        'ingreso_total_estimado' => round((float)$empleado->salario_base + $comisionEstimada, 2),
                        'semaforo_general'    => $semaforoGeneral,
                    ],

                    // Racha de asistencia
                    'asistencia' => [
                        'racha_actual_dias' => $rachaActual,
                        'racha_maxima_dias' => $rachaMaxima,
                        'total_turnos'      => $turnos,
                    ],

                    // Satisfacción de clientes
                    'satisfaccion' => $satisfaccion,

                    // Tendencia diaria (para gráfico de línea)
                    'tendencia_diaria' => $tendenciaDiaria,

                    // Horario pico
                    'horario_pico'         => $horarioPico,
                    'distribucion_horaria' => $distribucionHoraria,

                    // Top productos
                    'top_productos' => $topProductos,

                    // Comparativa vs. equipo
                    'comparativa_equipo' => $comparativa,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en getKpiMeseroIndividual: ' . $e->getMessage(), [
                'trace'          => $e->getTraceAsString(),
                'restaurante_id' => $restauranteId ?? null,
                'mesero_id'      => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener KPI del mesero: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
 * GET /api/kpis/cocina
 */
public function getKpiCocina(Request $request)
{
    try {
        $authUser = $request->user();
        $restauranteId = (int) $this->getRestauranteId($authUser);

        if (empty($restauranteId)) {
            return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
        }

        $empleados = $this->empleadosBaseQuery($restauranteId)
            ->whereHas('roles', fn ($q) => $q->where('nombre', 'COCINA'))
            ->get();

        $data = $empleados->map(function (User $empleado) use ($request, $restauranteId) {
            $asistenciasQuery = Asistencia::where('user_id', $empleado->id)
                ->where('restaurante_id', $restauranteId);

            if ($request->filled('fecha_desde')) {
                $asistenciasQuery->whereDate('fecha', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $asistenciasQuery->whereDate('fecha', '<=', $request->fecha_hasta);
            }

            $asistencias   = $asistenciasQuery->get();
            $horasTotales  = $asistencias->sum('horas_trabajadas');
            $turnosTrabajados = $asistencias->count();

            // Items completados durante los turnos del cocinero
            // (cruzando horarios de asistencia con updated_at de orden_detalles)
            $itemsCompletados = DB::table('orden_detalles as od')
                ->join('ordenes as o', 'o.id', '=', 'od.orden_id')
                ->join('asistencias as a', function ($join) use ($empleado, $restauranteId) {
                    $join->on('a.user_id', '=', DB::raw($empleado->id))
                         ->on('a.fecha', '=', DB::raw('DATE(od.updated_at)'))
                         ->where('a.restaurante_id', '=', $restauranteId);
                })
                ->where('o.restaurante_id', $restauranteId)
                ->where('od.estado_preparacion', 'LISTO')
                ->when($request->filled('fecha_desde'), fn($q) =>
                    $q->whereDate('od.updated_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn($q) =>
                    $q->whereDate('od.updated_at', '<=', $request->fecha_hasta))
                ->count();

            // Tiempo promedio de preparación del cocinero
            $tiempoPromedio = DB::table('orden_detalles as od')
                ->join('ordenes as o', 'o.id', '=', 'od.orden_id')
                ->join('asistencias as a', function ($join) use ($empleado, $restauranteId) {
                    $join->on('a.user_id', '=', DB::raw($empleado->id))
                         ->on('a.fecha', '=', DB::raw('DATE(od.updated_at)'))
                         ->where('a.restaurante_id', '=', $restauranteId);
                })
                ->where('o.restaurante_id', $restauranteId)
                ->whereIn('od.estado_preparacion', ['LISTO', 'ENTREGADO'])
                ->when($request->filled('fecha_desde'), fn($q) =>
                    $q->whereDate('od.updated_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn($q) =>
                    $q->whereDate('od.updated_at', '<=', $request->fecha_hasta))
                ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, od.created_at, od.updated_at)'));

            // Reprocesos del cocinero
            $reprocesos = DB::table('orden_detalles as od')
                ->join('ordenes as o', 'o.id', '=', 'od.orden_id')
                ->join('asistencias as a', function ($join) use ($empleado, $restauranteId) {
                    $join->on('a.user_id', '=', DB::raw($empleado->id))
                         ->on('a.fecha', '=', DB::raw('DATE(od.updated_at)'))
                         ->where('a.restaurante_id', '=', $restauranteId);
                })
                ->where('o.restaurante_id', $restauranteId)
                ->where('od.reprocesado', true)
                ->when($request->filled('fecha_desde'), fn($q) =>
                    $q->whereDate('od.updated_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn($q) =>
                    $q->whereDate('od.updated_at', '<=', $request->fecha_hasta))
                ->count();

            // Retrasos del cocinero
            $retrasos = DB::table('orden_detalles as od')
                ->join('ordenes as o', 'o.id', '=', 'od.orden_id')
                ->join('productos as p', 'p.id', '=', 'od.producto_id')
                ->join('asistencias as a', function ($join) use ($empleado, $restauranteId) {
                    $join->on('a.user_id', '=', DB::raw($empleado->id))
                         ->on('a.fecha', '=', DB::raw('DATE(od.updated_at)'))
                         ->where('a.restaurante_id', '=', $restauranteId);
                })
                ->where('o.restaurante_id', $restauranteId)
                ->whereIn('od.estado_preparacion', ['LISTO', 'ENTREGADO'])
                ->whereRaw('TIMESTAMPDIFF(MINUTE, od.created_at, od.updated_at) > p.minutos_produccion')
                ->when($request->filled('fecha_desde'), fn($q) =>
                    $q->whereDate('od.updated_at', '>=', $request->fecha_desde))
                ->when($request->filled('fecha_hasta'), fn($q) =>
                    $q->whereDate('od.updated_at', '<=', $request->fecha_hasta))
                ->count();

            $ordenesPorHora      = $horasTotales > 0 ? round($itemsCompletados / $horasTotales, 2) : 0;
            $eficiencia          = $itemsCompletados > 0
                ? round((($itemsCompletados - $retrasos) / $itemsCompletados) * 100, 2)
                : 0;
            $pctReprocesos       = $itemsCompletados > 0
                ? round(($reprocesos / $itemsCompletados) * 100, 2)
                : 0;

            $semaforo = match(true) {
                $eficiencia >= 85 => 'verde',
                $eficiencia >= 65 => 'amarillo',
                default           => 'rojo',
            };

            return [
                'empleado_id'               => $empleado->id,
                'empleado'                  => $empleado->name,
                'puesto'                    => 'cocina',
                'tipo_empleado'             => $empleado->tipo_empleado,
                'horas_trabajadas'          => round($horasTotales, 2),
                'turnos_trabajados'         => $turnosTrabajados,
                'items_completados'         => $itemsCompletados,
                'ordenes_por_hora'          => $ordenesPorHora,
                'tiempo_promedio_min'       => $tiempoPromedio ? round($tiempoPromedio, 2) : null,
                'retrasos'                  => $retrasos,
                'reprocesos'                => $reprocesos,
                'pct_reprocesos'            => $pctReprocesos,
                'eficiencia_pct'            => $eficiencia,
                'semaforo'                  => $semaforo,
            ];
        });

        $data = $data->sortByDesc('eficiencia_pct')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'cocina'  => $data,
                'resumen' => [
                    'total_cocineros'         => $data->count(),
                    'total_items_completados' => $data->sum('items_completados'),
                    'total_horas'             => round($data->sum('horas_trabajadas'), 2),
                    'total_retrasos'          => $data->sum('retrasos'),
                    'total_reprocesos'        => $data->sum('reprocesos'),
                    'promedio_eficiencia_pct' => $data->count() > 0
                        ? round($data->avg('eficiencia_pct'), 2) : 0,
                    'promedio_ordenes_hora'   => $data->count() > 0
                        ? round($data->avg('ordenes_por_hora'), 2) : 0,
                    'periodo' => [
                        'desde' => $request->fecha_desde,
                        'hasta' => $request->fecha_hasta,
                    ],
                ],
            ],
        ]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
/**
 * GET /api/kpis/cocina/retrasos
 * Porcentaje de órdenes que rebasaron el tiempo estimado
 */
public function getKpiCocinaRetrasos(Request $request)
{
    try {
        $authUser = $request->user();
        $restauranteId = (int) $this->getRestauranteId($authUser);

        if (empty($restauranteId)) {
            return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
        }

        $query = DB::table('orden_detalles')
            ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
            ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
            ->where('ordenes.restaurante_id', $restauranteId)
            ->whereIn('orden_detalles.estado_preparacion', ['LISTO', 'ENTREGADO'])
            ->whereNotNull('productos.minutos_produccion')
            ->where('productos.minutos_produccion', '>', 0);

        if ($request->filled('fecha_desde')) {
            $query->whereDate('orden_detalles.created_at', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('orden_detalles.created_at', '<=', $request->fecha_hasta);
        }

        // Total de items
        $totalItems = (clone $query)->count();

        // Items retrasados (tiempo_real > tiempo_estimado)
        $itemsRetrasados = (clone $query)
            ->whereRaw('TIMESTAMPDIFF(MINUTE, orden_detalles.created_at, orden_detalles.updated_at) > productos.minutos_produccion')
            ->count();

        $porcentajeRetrasos = $totalItems > 0 ? round(($itemsRetrasados / $totalItems) * 100, 2) : 0;

        // Datos para gráfica
        $retrasosPorEstacion = DB::table('orden_detalles')
            ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
            ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
            ->leftJoin('categorias', 'productos.categoria_id', '=', 'categorias.id')
            ->where('ordenes.restaurante_id', $restauranteId)
            ->whereIn('orden_detalles.estado_preparacion', ['LISTO', 'ENTREGADO'])
            ->whereNotNull('productos.minutos_produccion')
            ->where('productos.minutos_produccion', '>', 0)
            ->when($request->filled('fecha_desde'), fn($q) => 
                $q->whereDate('orden_detalles.created_at', '>=', $request->fecha_desde))
            ->when($request->filled('fecha_hasta'), fn($q) => 
                $q->whereDate('orden_detalles.created_at', '<=', $request->fecha_hasta))
            ->select(
                DB::raw('COALESCE(categorias.nombre, "Sin categoría") as estacion'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, orden_detalles.created_at, orden_detalles.updated_at) > productos.minutos_produccion THEN 1 ELSE 0 END) as retrasados')
            )
            ->groupBy('estacion')
            ->get()
            ->map(fn($item) => [
                'estacion' => $item->estacion,
                'total' => $item->total,
                'retrasados' => $item->retrasados,
                'porcentaje' => $item->total > 0 ? round(($item->retrasados / $item->total) * 100, 2) : 0,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'resumen' => [
                    'total_items' => $totalItems,
                    'items_retrasados' => $itemsRetrasados,
                    'porcentaje_retrasos' => $porcentajeRetrasos,
                    'semaforo' => $porcentajeRetrasos <= 10 ? 'verde' : ($porcentajeRetrasos <= 20 ? 'amarillo' : 'rojo'),
                ],
                'por_estacion' => $retrasosPorEstacion,
                'periodo' => [
                    'desde' => $request->fecha_desde,
                    'hasta' => $request->fecha_hasta,
                ],
            ],
        ]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
public function getKpiCocinaReprocesos(Request $request)
{
    try {
        $authUser = $request->user();
        $restauranteId = (int) $this->getRestauranteId($authUser);

        if (empty($restauranteId)) {
            return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
        }

        $base = DB::table('orden_detalles as od')
            ->join('ordenes as o', 'o.id', '=', 'od.orden_id')
            ->join('productos as p', 'p.id', '=', 'od.producto_id')
            ->leftJoin('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->where('o.restaurante_id', $restauranteId)
            ->when($request->filled('fecha_desde'), fn($q) =>
                $q->whereDate('od.created_at', '>=', $request->fecha_desde))
            ->when($request->filled('fecha_hasta'), fn($q) =>
                $q->whereDate('od.created_at', '<=', $request->fecha_hasta));

        $totalItems  = (clone $base)->count();
        $reprocesos  = (clone $base)->where('od.reprocesado', true)->count();
        $pctReprocesos = $totalItems > 0 ? round(($reprocesos / $totalItems) * 100, 2) : 0;

        // Detalle por producto
        $porProducto = (clone $base)
            ->where('od.reprocesado', true)
            ->select(
                'p.id as producto_id',
                'p.nombre as producto',
                DB::raw('COALESCE(c.nombre, "Sin categoría") as estacion'),
                DB::raw('COUNT(*) as total_reprocesos'),
                DB::raw('ROUND(COUNT(*) * 100.0 / ' . max($totalItems, 1) . ', 2) as pct_del_total')
            )
            ->groupBy('p.id', 'p.nombre', 'c.nombre')
            ->orderByDesc('total_reprocesos')
            ->limit(10)
            ->get();

        // Detalle por estación (categoría)
        $porEstacion = (clone $base)
            ->select(
                DB::raw('COALESCE(c.nombre, "Sin categoría") as estacion'),
                DB::raw('COUNT(*) as total_items'),
                DB::raw('SUM(CASE WHEN od.reprocesado = 1 THEN 1 ELSE 0 END) as reprocesos'),
                DB::raw('ROUND(SUM(CASE WHEN od.reprocesado = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 2) as pct_reprocesos')
            )
            ->groupBy('c.nombre')
            ->orderByDesc('reprocesos')
            ->get();

        // Tendencia diaria
        $tendencia = (clone $base)
            ->where('od.reprocesado', true)
            ->select(
                DB::raw('DATE(od.created_at) as fecha'),
                DB::raw('COUNT(*) as reprocesos')
            )
            ->groupBy(DB::raw('DATE(od.created_at)'))
            ->orderBy('fecha')
            ->get();

        $semaforo = match(true) {
            $pctReprocesos <= 2  => 'verde',
            $pctReprocesos <= 5  => 'amarillo',
            default              => 'rojo',
        };

        return response()->json([
            'success' => true,
            'data' => [
                'resumen' => [
                    'total_platillos'      => $totalItems,
                    'total_reprocesos'     => $reprocesos,
                    'pct_reprocesos'       => $pctReprocesos,
                    'semaforo'             => $semaforo,
                ],
                'por_producto' => $porProducto,
                'por_estacion' => $porEstacion,
                'tendencia'    => $tendencia,
                'periodo'      => [
                    'desde' => $request->fecha_desde,
                    'hasta' => $request->fecha_hasta,
                ],
            ],
        ]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
    /**
     * GET /api/kpis/admin
     */
    public function getKpiAdmin(Request $request)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $validator = Validator::make($request->all(), [
                'fecha_desde' => 'nullable|date',
                'fecha_hasta' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $ventasQuery = DB::table('ordenes')->where('restaurante_id', $restauranteId)
                ->whereIn('estado', ['completada', 'pagada']);

            if ($request->filled('fecha_desde')) {
                $ventasQuery->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $ventasQuery->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            $ventasTotales = $ventasQuery->sum('total');
            $totalGastado = $ventasTotales > 0 ? $ventasTotales : 0;

            $nominasQuery = Nomina::where('restaurante_id', $restauranteId)
                ->where('estado', 'PAGADA');

            if ($request->filled('fecha_desde')) {
                $nominasQuery->whereDate('periodo_fin', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $nominasQuery->whereDate('periodo_fin', '<=', $request->fecha_hasta);
            }

            $nominaTotal = $nominasQuery->sum('pago_total');

            $gastosQuery = DB::table('gastos')->where('restaurante_id', $restauranteId);
            if ($request->filled('fecha_desde')) {
                $gastosQuery->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $gastosQuery->whereDate('created_at', '<=', $request->fecha_hasta);
            }
            $insumosTotal = $gastosQuery->sum('monto');

            $costoNominaPorcentaje = $totalGastado > 0 ? round(($nominaTotal / $totalGastado) * 100, 2) : 0;
            $costoInsumosPorcentaje = $totalGastado > 0 ? round(($insumosTotal / $totalGastado) * 100, 2) : 0;

            $empleadosActivos = $this->empleadosBaseQuery($restauranteId)->count();

            $promedioNominaEmpleado = $empleadosActivos > 0 ? round($nominaTotal / $empleadosActivos, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'resumen_financiero' => [
                        'ventas_totales' => round($totalGastado, 2),
                        'nomina_total' => round($nominaTotal, 2),
                        'insumos_total' => round($insumosTotal, 2),
                        'costo_nomina_porcentaje' => $costoNominaPorcentaje,
                        'costo_insumos_porcentaje' => $costoInsumosPorcentaje,
                    ],
                    'empleados' => [
                        'activos' => $empleadosActivos,
                        'promedio_nomina_empleado' => $promedioNominaEmpleado,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/kpis/dashboard
     */
    public function getKpiDashboard(Request $request)
    {
        try {
            $authUser = $request->user();
            $restauranteId = (int) $this->getRestauranteId($authUser);

            if (empty($restauranteId)) {
                return response()->json(['success' => false, 'message' => 'No se detectó el ID de la sucursal activa'], 400);
            }

            $validator = Validator::make($request->all(), [
                'fecha_desde' => 'nullable|date',
                'fecha_hasta' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $nominasQuery = Nomina::where('restaurante_id', $restauranteId)
                ->where('estado', 'PAGADA');

            if ($request->filled('fecha_desde')) {
                $nominasQuery->whereDate('periodo_fin', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $nominasQuery->whereDate('periodo_fin', '<=', $request->fecha_hasta);
            }

            $nominaTotal = $nominasQuery->sum('pago_total');

            $ventasQuery = DB::table('ordenes')
                ->where('restaurante_id', $restauranteId)
                ->whereIn('estado', ['completada', 'pagada']);

            if ($request->filled('fecha_desde')) {
                $ventasQuery->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $ventasQuery->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            $ventasTotales = $ventasQuery->sum('total');
            $totalOrdenes = $ventasQuery->count();

            $gastosQuery = DB::table('gastos')
                ->where('restaurante_id', $restauranteId);

            if ($request->filled('fecha_desde')) {
                $gastosQuery->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $gastosQuery->whereDate('created_at', '<=', $request->fecha_hasta);
            }

            $gastosTotal = $gastosQuery->sum('monto');

            $empleadosPorRol = DB::table('restaurante_user as ru')
                ->join('role_user as ru2', 'ru2.user_id', '=', 'ru.user_id')
                ->join('roles as r', 'r.id', '=', 'ru2.role_id')
                ->where('ru.restaurante_id', $restauranteId)
                ->whereIn('r.nombre', $this->staffRoleNombres())
                ->selectRaw('LOWER(r.nombre) as rol, COUNT(DISTINCT ru.user_id) as total')
                ->groupBy('r.nombre')
                ->pluck('total', 'rol')
                ->toArray();

            $ventasPorMesero = DB::table('asistencias')
                ->where('restaurante_id', $restauranteId)
                ->selectRaw('user_id, SUM(ventas_generadas) as total_ventas')
                ->groupBy('user_id')
                ->orderByDesc('total_ventas')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'resumen' => [
                        'ventas_totales' => round($ventasTotales, 2),
                        'total_ordenes' => $totalOrdenes,
                        'ticket_promedio' => $totalOrdenes > 0 ? round($ventasTotales / $totalOrdenes, 2) : 0,
                        'nomina_total' => round($nominaTotal, 2),
                        'gastos_total' => round($gastosTotal, 2),
                        'costo_nomina_porcentaje' => $ventasTotales > 0 ? round(($nominaTotal / $ventasTotales) * 100, 2) : 0,
                        'costo_insumos_porcentaje' => $ventasTotales > 0 ? round(($gastosTotal / $ventasTotales) * 100, 2) : 0,
                    ],
                    'empleados' => [
                        'total_activos' => array_sum($empleadosPorRol),
                        'por_rol' => [
                            'mesero' => (int) ($empleadosPorRol['mesero'] ?? 0),
                            'cocina' => (int) ($empleadosPorRol['cocina'] ?? 0),
                            'caja' => (int) ($empleadosPorRol['caja'] ?? 0),
                            'admin' => (int) ($empleadosPorRol['admin'] ?? 0),
                        ],
                    ],
                    'ranking_ventas' => $ventasPorMesero,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}