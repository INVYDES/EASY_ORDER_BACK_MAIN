<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Nomina;
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
    /** Roles de personal operativo (tabla `users` + `restaurante_user`, sin tabla `empleados`). */
    private function staffRoleNombres(): array
    {
        return ['MESERO', 'COCINA', 'CAJA', 'ADMIN'];
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

    /** Compat: body/query puede usar `user_id` o `empleado_id` (mismo valor: users.id). */
    private function resolveUserId(Request $request): ?int
    {
        $v = $request->input('user_id', $request->input('empleado_id'));

        return $v !== null && $v !== '' ? (int) $v : null;
    }

    /**
     * Listar personal del restaurante (usuarios con rol operativo vinculados a la sucursal).
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

            $query = $this->empleadosBaseQuery($restauranteId)->with(['roles']);

            if ($request->filled('puesto')) {
                $rn = $this->mapPuestoToRole($request->puesto);
                if ($rn) {
                    $query->whereHas('roles', fn ($q) => $q->where('nombre', $rn));
                }
            }

            if ($request->filled('nombre')) {
                $query->where('name', 'LIKE', '%' . $request->nombre . '%');
            }

            $empleados = $query->orderBy('created_at', 'desc')->get()->map(function (User $u) {
                $staffRole = $u->roles->first(fn ($r) => in_array($r->nombre, $this->staffRoleNombres(), true));

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'username' => $u->username,
                    'puesto' => $staffRole ? strtolower($staffRole->nombre) : null,
                    'roles' => $u->roles,
                    'created_at' => $u->created_at,
                    'updated_at' => $u->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $empleados,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Alta de personal (crea fila en `users` y vínculos como registerEmpleado).
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

                $empleadoUser = User::create([
                    'propietario_id' => $restaurante->propietario_id,
                    'name' => $request->name,
                    'email' => $email,
                    'username' => 'tmp_' . Str::random(10),
                    'password' => Hash::make($request->password),
                    'restaurante_activo' => $restauranteId,
                ]);

                $empleadoUser->update(['username' => (string) $restaurante->propietario_id . $empleadoUser->id]);

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
     * GET /api/empleados/{empleado}  (id = users.id)
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
                    'puesto' => $staffRole ? strtolower($staffRole->nombre) : null,
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
                    'puesto' => $staffRole ? strtolower($staffRole->nombre) : null,
                    'roles' => $empleado->roles,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Quitar al usuario de la sucursal (no borra el usuario).
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
     * GET /api/asistencias/empleado/{empleado} (id = users.id)
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

    /**
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
                'valor_hora' => 'required|numeric|min:0',
                'salario_base' => 'nullable|numeric|min:0',
                'comision_ventas' => 'nullable|numeric|min:0',
                'bonos' => 'nullable|numeric|min:0',
                'descuentos' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            if (!$this->findStaffUser($userId, $restauranteId)) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $horasTotales = (float) Asistencia::where('user_id', $userId)
                ->where('restaurante_id', $restauranteId)
                ->whereDate('fecha', '>=', $request->periodo_inicio)
                ->whereDate('fecha', '<=', $request->periodo_fin)
                ->sum('horas_trabajadas');

            $valorHora = (float) $request->valor_hora;
            $pagoHoras = round($valorHora * $horasTotales, 2);
            $salarioBase = $request->filled('salario_base') ? round((float) $request->salario_base, 2) : $pagoHoras;
            $comisionVentas = round((float) $request->input('comision_ventas', 0), 2);
            $bonos = round((float) $request->input('bonos', 0), 2);
            $descuentos = round((float) $request->input('descuentos', 0), 2);

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
                $row = Nomina::updateOrCreate(
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
                        'estado' => 'PENDIENTE',
                    ]
                );
                $row->pago_total = round(
                    (float) $row->salario_base + (float) $row->comision_ventas + (float) $row->bonos - (float) $row->descuentos,
                    2
                );
                $row->save();

                return $row->fresh();
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

            $nominas = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $nominas,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/nominas/{nomina}
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
                'estado' => 'required|in:PENDIENTE,PAGADA,ANULADA,pendiente,pagada,anulada',
                'observaciones' => 'nullable|string',
                'fecha_pago' => 'nullable|date',
                'metodo_pago' => 'nullable|string|max:50',
                'referencia_pago' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $estado = strtoupper($request->estado);

            $update = ['estado' => $estado];
            foreach (['observaciones', 'fecha_pago', 'metodo_pago', 'referencia_pago'] as $field) {
                if ($request->has($field)) {
                    $update[$field] = $request->input($field);
                }
            }

            $nomina->update($update);

            return response()->json([
                'success' => true,
                'message' => 'Estado de nómina actualizado',
                'data' => $nomina->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/kpis/meseros
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
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $empleados = $this->empleadosBaseQuery($restauranteId)
                ->whereHas('roles', fn ($q) => $q->where('nombre', 'MESERO'))
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

                $asistencias = $asistenciasQuery->get();

                $horasTotales = $asistencias->sum('horas_trabajadas');
                $ventasGeneradas = $asistencias->sum('ventas_generadas');
                $turnos = $asistencias->count();

                $ventasPorMesero = $turnos > 0 ? round($ventasGeneradas / $turnos, 2) : 0;
                $ventasPorHora = $horasTotales > 0 ? round($ventasGeneradas / $horasTotales, 2) : 0;
                $ticketPromedio = $turnos > 0 ? round($ventasGeneradas / $turnos, 2) : 0;

                return [
                    'empleado' => $empleado->name,
                    'empleado_id' => $empleado->id,
                    'user_id' => $empleado->id,
                    'puesto' => 'mesero',
                    'horas_trabajadas' => round($horasTotales, 2),
                    'ventas_generadas' => round($ventasGeneradas, 2),
                    'turnos' => $turnos,
                    'ventas_por_hora' => $ventasPorHora,
                    'ticket_promedio' => $ticketPromedio,
                    'ventas_por_mesero' => $ventasPorMesero,
                ];
            });

            $totalVentas = $data->sum('ventas_generadas');
            $totalHoras = $data->sum('horas_trabajadas');
            $promedioTicket = $totalVentas > 0 && $data->count() > 0
                ? round($totalVentas / $data->count(), 2)
                : 0;

            $ranking = $data->sortByDesc('ventas_generadas')->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'meseros' => $data,
                    'ranking' => $ranking,
                    'resumen' => [
                        'total_ventas' => round($totalVentas, 2),
                        'total_horas' => round($totalHoras, 2),
                        'promedio_ticket' => $promedioTicket,
                        'total_meseros' => $data->count(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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

                $asistencias = $asistenciasQuery->get();

                $horasTotales = $asistencias->sum('horas_trabajadas');
                $ordenesCompletadas = 0;

                $ordenesPorHora = $horasTotales > 0 ? round($ordenesCompletadas / $horasTotales, 2) : 0;

                return [
                    'empleado' => $empleado->name,
                    'empleado_id' => $empleado->id,
                    'user_id' => $empleado->id,
                    'puesto' => 'cocina',
                    'horas_trabajadas' => round($horasTotales, 2),
                    'ordenes_completadas' => $ordenesCompletadas,
                    'ordenes_por_hora' => $ordenesPorHora,
                    'tiempo_promedio_preparacion' => null,
                    'retrasos' => 0,
                    'errores' => 0,
                    'desperdicio' => null,
                ];
            });

            $totalOrdenes = $data->sum('ordenes_completadas');
            $totalHoras = $data->sum('horas_trabajadas');
            $promedioOrdenesHora = $totalHoras > 0 ? round($totalOrdenes / $totalHoras, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'cocina' => $data,
                    'resumen' => [
                        'total_ordenes' => $totalOrdenes,
                        'total_horas' => round($totalHoras, 2),
                        'promedio_ordenes_hora' => $promedioOrdenesHora,
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

    private function getRestauranteId($user)
    {
        $headerId = request()->header('X-Restaurante-Id');
        $userRestId = is_object($user->restaurante_activo) ? $user->restaurante_activo->id : $user->restaurante_activo;
        $requestId = request()->restaurante_id;

        return (!empty($headerId)) ? $headerId : ((!empty($userRestId)) ? $userRestId : $requestId);
    }
}
