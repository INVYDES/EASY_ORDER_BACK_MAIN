<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RestauranteController;
use App\Http\Controllers\Api\PropietarioController;
use App\Http\Controllers\Api\LicenciaController;
use App\Http\Controllers\Api\PropietarioLicenciaController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\OrdenController;
use App\Http\Controllers\Api\OrdenDetalleController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\AnuncioController;
use App\Http\Controllers\Api\GastoController;
use App\Http\Controllers\Api\IngredienteController;
use App\Http\Controllers\Api\OfertaController;
use App\Http\Controllers\Api\PayPalController;
use App\Http\Controllers\Api\LicenciaPagoController;
use App\Http\Controllers\Api\MercadoPagoController;
use App\Http\Controllers\Api\MeseroController;
use App\Http\Controllers\Api\PaqueteController;

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS (SIN AUTENTICACIÓN)
|--------------------------------------------------------------------------
*/
Route::prefix('')->group(function () {

    // ========== AUTENTICACIÓN ==========
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login',            [AuthController::class, 'login']);
        Route::post('/register',         [AuthController::class, 'register']);
        Route::post('/register-cliente', [AuthController::class, 'registerCliente']);
        Route::post('/register-empleado',[AuthController::class, 'registerEmpleado']);
        Route::post('/forgot-password',  [AuthController::class, 'forgotPassword']);
    });
    Route::post('/reset-password',       [AuthController::class, 'resetPassword']);
    Route::post('/verify-reset-token',   [AuthController::class, 'verifyResetToken']);

    // ========== PROPIETARIOS ==========
    Route::post('/propietarios',         [PropietarioController::class, 'store']);

    // ========== WEBHOOKS (excluir de CSRF en VerifyCsrfToken) ==========
    Route::post('/paypal/licencia-webhook',      [LicenciaController::class, 'webhookPayPal']);
    Route::post('/mercadopago/licencia-webhook', [LicenciaController::class, 'webhookMercadoPago']);

    // ========== CALLBACKS PAYPAL ==========
    Route::get('/paypal/capturar',  [LicenciaPagoController::class, 'capturar']);
    Route::get('/paypal/cancelar',  [LicenciaPagoController::class, 'cancelar']);
    Route::get('/paypal/capture',   [PayPalController::class, 'captureOrder'])->name('paypal.capture');
    Route::get('/paypal/cancel',    [PayPalController::class, 'cancelOrder'])->name('paypal.cancel');

    // ========== ANUNCIOS ==========
    Route::get('/anuncios',         [AnuncioController::class, 'indexPublic']);
    Route::get('/anuncios/vigentes',[AnuncioController::class, 'vigentesPublic']);

    // ========== PRODUCTOS ==========
    Route::get('/productos/disponibles',             [ProductoController::class, 'disponiblesPublic']);
    Route::get('/productos/categoria/{categoriaId}', [ProductoController::class, 'porCategoria']);
    Route::get('/productos',                         [ProductoController::class, 'indexPublic']);
    Route::get('/productos/{id}',                    [ProductoController::class, 'showPublic']);

    // ========== CATEGORÍAS ==========
    Route::get('/categorias',     [CategoriaController::class, 'indexPublic']);
    Route::get('/categorias/{id}',[CategoriaController::class, 'showPublic']);

    // ========== OFERTAS ==========
    Route::get('/ofertas/activas',[OfertaController::class, 'activasPublic']);

    // ========== LICENCIAS ==========
    Route::get('/licencias/disponibles', [LicenciaController::class, 'disponibles']);
});

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS (SOLO TOKEN — SIN TENANT)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // ========== AUTENTICACIÓN ==========
    Route::post('/logout',              [AuthController::class, 'logout']);
    Route::get('/me',                   [AuthController::class, 'me']);
    Route::post('/cambiar-restaurante', [AuthController::class, 'cambiarRestaurante']);
    Route::post('/change-password',     [AuthController::class, 'changePassword']);
    Route::post('/empleados',           [AuthController::class, 'registerEmpleado']);

    // ========== PERFIL DE USUARIO ==========
    Route::get('/user',                   [UserController::class, 'show']);
    Route::get('/user/profile',           [UserController::class, 'show']);
    Route::put('/user/profile',           [UserController::class, 'update']);
    Route::get('/user/roles',             [UserController::class, 'roles']);
    Route::get('/user/rol-principal',     [UserController::class, 'rolPrincipal']);
    Route::get('/user/owner-restaurants', [UserController::class, 'getOwnerRestaurants']);

    // ========== RESTAURANTES ==========
    Route::get('/restaurantes/buscar',    [RestauranteController::class, 'buscarPorNombre']);

    // ========== MI LICENCIA ==========
    Route::get('/mi-licencia',            [LicenciaPagoController::class, 'miLicencia']);

    // ========== COMPRA DE LICENCIAS (sin tenant — puede ser su primera licencia) ==========
    Route::prefix('licencias')->group(function () {
        Route::post('/{licenciaId}/comprar',              [LicenciaController::class, 'comprarLicencia']);
        Route::post('/{licenciaId}/comprar-paypal',       [LicenciaController::class, 'comprarLicenciaPayPal']);
        Route::post('/{licenciaId}/comprar-mercadopago',  [LicenciaController::class, 'comprarLicenciaMercadoPago']);
    });
});

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS CON TENANT Y PERMISOS
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    // ========== CAJA ==========
    Route::prefix('caja')->group(function () {
        Route::get('/estado',            [CajaController::class, 'estado'])->middleware('permission:VER_CAJA');
        Route::post('/abrir',            [CajaController::class, 'abrir'])->middleware('permission:ABRIR_CAJA');
        Route::post('/cerrar',           [CajaController::class, 'cerrar'])->middleware('permission:CERRAR_CAJA');
        Route::get('/movimientos',       [CajaController::class, 'movimientos'])->middleware('permission:VER_CAJA');
        Route::post('/movimientos',      [CajaController::class, 'registrarMovimiento'])->middleware('permission:EDITAR_CAJA');
        Route::get('/corte',             [CajaController::class, 'corte'])->middleware('permission:VER_CAJA');
        Route::get('/historial',         [CajaController::class, 'historial'])->middleware('permission:VER_CAJA');
        Route::get('/historial/{id}',    [CajaController::class, 'show'])->middleware('permission:VER_CAJA');
        Route::post('/paypal/crear',     [CajaController::class, 'crearPagoPayPal'])->middleware('permission:CREAR_ORDENES');
        Route::post('/mercadopago/crear',[MercadoPagoController::class, 'crearPreferencia'])->middleware('permission:CREAR_ORDENES');
    });

    // ========== RESTAURANTES ==========
    Route::prefix('restaurantes')->group(function () {
        Route::get('/select-list',            [RestauranteController::class, 'selectList'])->middleware('permission:VER_RESTAURANTE');
        Route::get('/',                       [RestauranteController::class, 'index'])->middleware('permission:VER_RESTAURANTE');
        Route::post('/',                      [RestauranteController::class, 'store'])->middleware('permission:CREAR_RESTAURANTE');
        Route::get('/{restaurante}',          [RestauranteController::class, 'show'])->middleware('permission:VER_RESTAURANTE');
        Route::get('/{restaurante}/estadisticas', [RestauranteController::class, 'estadisticas'])->middleware('permission:VER_RESTAURANTE');
        Route::put('/{restaurante}',          [RestauranteController::class, 'update'])->middleware('permission:EDITAR_RESTAURANTE');
        Route::delete('/{restaurante}',       [RestauranteController::class, 'destroy'])->middleware('permission:ELIMINAR_RESTAURANTE');
    });

    // ========== PROPIETARIOS ==========
    Route::prefix('propietarios')->group(function () {
        Route::get('/',                            [PropietarioController::class, 'index'])->middleware('permission:VER_PROPIETARIOS');
        Route::get('/dashboard',                   [PropietarioController::class, 'dashboard'])->middleware('permission:VER_PROPIETARIOS');
        Route::get('/{propietario}',               [PropietarioController::class, 'show'])->middleware('permission:VER_PROPIETARIOS');
        Route::put('/{propietario}',               [PropietarioController::class, 'update'])->middleware('permission:EDITAR_PROPIETARIOS');
        Route::delete('/{propietario}',            [PropietarioController::class, 'destroy'])->middleware('permission:ELIMINAR_PROPIETARIOS');
        Route::post('/{propietario}/empleados',    [AuthController::class, 'registerEmpleado'])->middleware('permission:CREAR_EMPLEADOS');
        Route::get('/{propietario}/licencias-activas', [PropietarioLicenciaController::class, 'propietarioActivas'])->middleware('permission:VER_PROPIETARIO_LICENCIA');
    });

    // ========== PROPIETARIO-LICENCIA ==========
    Route::prefix('propietario-licencias')->group(function () {
        Route::get('/',                              [PropietarioLicenciaController::class, 'index'])->middleware('permission:VER_PROPIETARIO_LICENCIA');
        Route::post('/',                             [PropietarioLicenciaController::class, 'store'])->middleware('permission:ASIGNAR_LICENCIA');
        Route::get('/{propietarioLicencia}',         [PropietarioLicenciaController::class, 'show'])->middleware('permission:VER_PROPIETARIO_LICENCIA');
        Route::put('/{propietarioLicencia}',         [PropietarioLicenciaController::class, 'update'])->middleware('permission:EDITAR_PROPIETARIO_LICENCIA');
        Route::delete('/{propietarioLicencia}',      [PropietarioLicenciaController::class, 'destroy'])->middleware('permission:ELIMINAR_PROPIETARIO_LICENCIA');
        Route::post('/{propietarioLicencia}/renovar',[PropietarioLicenciaController::class, 'renovar'])->middleware('permission:ASIGNAR_LICENCIA');
        Route::post('/{propietarioLicencia}/cancelar',[PropietarioLicenciaController::class, 'cancelar'])->middleware('permission:EDITAR_PROPIETARIO_LICENCIA');
    });

    // ========== LICENCIAS (ADMIN) ==========
    Route::prefix('licencias')->group(function () {
        Route::get('/verificar-pago/{paymentId}', [LicenciaController::class, 'verificarPagoMercadoPago'])->middleware('permission:VER_LICENCIAS');
        Route::get('/',                           [LicenciaController::class, 'index'])->middleware('permission:VER_LICENCIAS');
        Route::post('/',                          [LicenciaController::class, 'store'])->middleware('permission:CREAR_LICENCIAS');
        Route::get('/{licencia}',                 [LicenciaController::class, 'show'])->middleware('permission:VER_LICENCIAS');
        Route::put('/{licencia}',                 [LicenciaController::class, 'update'])->middleware('permission:EDITAR_LICENCIAS');
        Route::delete('/{licencia}',              [LicenciaController::class, 'destroy'])->middleware('permission:ELIMINAR_LICENCIAS');
        Route::patch('/{licencia}/toggle-active', [LicenciaController::class, 'toggleActive'])->middleware('permission:EDITAR_LICENCIAS');
    });

    // ========== ROLES ==========
    Route::prefix('roles')->group(function () {
        Route::get('/select-list',            [RoleController::class, 'selectList'])->middleware('permission:VER_ROLES');
        Route::get('/available-permissions',  [RoleController::class, 'availablePermissions'])->middleware('permission:VER_ROLES');
        Route::get('/',                       [RoleController::class, 'index'])->middleware('permission:VER_ROLES');
        Route::post('/',                      [RoleController::class, 'store'])->middleware('permission:CREAR_ROLES');
        Route::get('/{role}',                 [RoleController::class, 'show'])->middleware('permission:VER_ROLES');
        Route::get('/{role}/users',           [RoleController::class, 'users'])->middleware('permission:VER_ROLES');
        Route::put('/{role}',                 [RoleController::class, 'update'])->middleware('permission:EDITAR_ROLES');
        Route::delete('/{role}',              [RoleController::class, 'destroy'])->middleware('permission:ELIMINAR_ROLES');
        Route::post('/{role}/assign-permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:EDITAR_ROLES');
        Route::post('/{role}/remove-permissions', [RoleController::class, 'removePermissions'])->middleware('permission:EDITAR_ROLES');
    });

    // ========== PERMISOS ==========
    Route::prefix('permissions')->group(function () {
        Route::get('/grouped',          [PermissionController::class, 'grouped'])->middleware('permission:VER_PERMISOS');
        Route::get('/role/{rol}',       [PermissionController::class, 'byRole'])->middleware('permission:VER_PERMISOS');
        Route::get('/',                 [PermissionController::class, 'index'])->middleware('permission:VER_PERMISOS');
        Route::post('/',                [PermissionController::class, 'store'])->middleware('permission:CREAR_PERMISOS');
        Route::get('/{permission}',     [PermissionController::class, 'show'])->middleware('permission:VER_PERMISOS');
        Route::put('/{permission}',     [PermissionController::class, 'update'])->middleware('permission:EDITAR_PERMISOS');
        Route::delete('/{permission}',  [PermissionController::class, 'destroy'])->middleware('permission:ELIMINAR_PERMISOS');
        Route::post('/{permission}/assign-roles', [PermissionController::class, 'assignToRoles'])->middleware('permission:EDITAR_PERMISOS');
    });

    // ========== PRODUCTOS ==========
    Route::prefix('productos')->group(function () {
        Route::get('/select-list',           [ProductoController::class, 'selectList'])->middleware('permission:VER_PRODUCTOS');
        Route::get('/bajo-stock',            [ProductoController::class, 'bajoStock'])->middleware('permission:VER_PRODUCTOS');
        Route::post('/import',               [ProductoController::class, 'import'])->middleware('permission:CREAR_PRODUCTOS');
        Route::get('/',                      [ProductoController::class, 'index'])->middleware('permission:VER_PRODUCTOS');
        Route::post('/',                     [ProductoController::class, 'store'])->middleware('permission:CREAR_PRODUCTOS');
        Route::get('/{producto}',            [ProductoController::class, 'show'])->middleware('permission:VER_PRODUCTOS');
        Route::put('/{producto}',            [ProductoController::class, 'update'])->middleware('permission:EDITAR_PRODUCTOS');
        Route::delete('/{producto}',         [ProductoController::class, 'destroy'])->middleware('permission:ELIMINAR_PRODUCTOS');
        Route::patch('/{producto}/toggle-active',  [ProductoController::class, 'toggleActive'])->middleware('permission:EDITAR_PRODUCTOS');
        Route::post('/{producto}/ajustar-stock',   [ProductoController::class, 'ajustarStock'])->middleware('permission:EDITAR_PRODUCTOS');
    });

    // ========== PAQUETES ==========
    Route::prefix('paquetes')->group(function () {
        Route::get('/',                     [PaqueteController::class, 'index'])->middleware('permission:VER_PRODUCTOS');
        Route::post('/',                    [PaqueteController::class, 'store'])->middleware('permission:CREAR_PRODUCTOS');
        Route::get('/{id}',                 [PaqueteController::class, 'show'])->middleware('permission:VER_PRODUCTOS');
        Route::put('/{id}',                 [PaqueteController::class, 'update'])->middleware('permission:EDITAR_PRODUCTOS');
        Route::delete('/{id}',              [PaqueteController::class, 'destroy'])->middleware('permission:ELIMINAR_PRODUCTOS');
        Route::patch('/{id}/toggle-active', [PaqueteController::class, 'toggleActive'])->middleware('permission:EDITAR_PRODUCTOS');
    });

    // ========== INGREDIENTES ==========
    Route::prefix('ingredientes')->group(function () {
        Route::get('/producto/{productoId}',       [IngredienteController::class, 'deProducto'])->middleware('permission:VER_PRODUCTOS');
        Route::post('/producto/{productoId}/sync', [IngredienteController::class, 'syncProducto'])->middleware('permission:EDITAR_PRODUCTOS');
        Route::get('/',                            [IngredienteController::class, 'index'])->middleware('permission:VER_PRODUCTOS');
        Route::post('/',                           [IngredienteController::class, 'store'])->middleware('permission:CREAR_PRODUCTOS');
        Route::get('/{id}/historial',              [IngredienteController::class, 'historial'])->middleware('permission:VER_PRODUCTOS');
        Route::post('/{id}/ajustar-stock',         [IngredienteController::class, 'ajustarStock'])->middleware('permission:EDITAR_PRODUCTOS');
        Route::put('/{id}',                        [IngredienteController::class, 'update'])->middleware('permission:EDITAR_PRODUCTOS');
        Route::delete('/{id}',                     [IngredienteController::class, 'destroy'])->middleware('permission:ELIMINAR_PRODUCTOS');
    });

    // ========== CATEGORÍAS ==========
    Route::prefix('categorias')->group(function () {
        Route::get('/select-list',          [CategoriaController::class, 'selectList'])->middleware('permission:VER_CATEGORIAS');
        Route::get('/resumen',              [CategoriaController::class, 'resumen'])->middleware('permission:VER_CATEGORIAS');
        Route::post('/reordenar',           [CategoriaController::class, 'reordenar'])->middleware('permission:EDITAR_CATEGORIAS');
        Route::get('/',                     [CategoriaController::class, 'index'])->middleware('permission:VER_CATEGORIAS');
        Route::post('/',                    [CategoriaController::class, 'store'])->middleware('permission:CREAR_CATEGORIAS');
        Route::post('/{id}/toggle-active',  [CategoriaController::class, 'toggleActive'])->middleware('permission:EDITAR_CATEGORIAS');
        Route::get('/{categoria}',          [CategoriaController::class, 'show'])->middleware('permission:VER_CATEGORIAS');
        Route::put('/{categoria}',          [CategoriaController::class, 'update'])->middleware('permission:EDITAR_CATEGORIAS');
        Route::delete('/{categoria}',       [CategoriaController::class, 'destroy'])->middleware('permission:ELIMINAR_CATEGORIAS');
    });

    // ========== GASTOS ==========
    Route::prefix('gastos')->group(function () {
        Route::get('/resumen',  [GastoController::class, 'resumen'])->middleware('permission:VER_REPORTES');
        Route::get('/',         [GastoController::class, 'index'])->middleware('permission:VER_REPORTES');
        Route::post('/',        [GastoController::class, 'store'])->middleware('permission:VER_REPORTES');
        Route::put('/{id}',     [GastoController::class, 'update'])->middleware('permission:VER_REPORTES');
        Route::delete('/{id}',  [GastoController::class, 'destroy'])->middleware('permission:VER_REPORTES');
    });

    // ========== ANUNCIOS (ADMIN) ==========
    Route::prefix('admin/anuncios')->group(function () {
        Route::get('/',         [AnuncioController::class, 'index'])->middleware('permission:VER_RESTAURANTE');
        Route::post('/',        [AnuncioController::class, 'store'])->middleware('permission:EDITAR_RESTAURANTE');
        Route::put('/{id}',     [AnuncioController::class, 'update'])->middleware('permission:EDITAR_RESTAURANTE');
        Route::delete('/{id}',  [AnuncioController::class, 'destroy'])->middleware('permission:EDITAR_RESTAURANTE');
    });

    // ========== ÓRDENES ==========
    Route::prefix('ordenes')->group(function () {
        Route::get('/resumen',  [OrdenController::class, 'resumen'])->middleware('permission:VER_ORDENES');
        Route::get('/hoy',      [OrdenController::class, 'hoy'])->middleware('permission:VER_ORDENES');
        Route::get('/',         [OrdenController::class, 'index'])->middleware('permission:VER_ORDENES');
        Route::post('/',        [OrdenController::class, 'store'])->middleware('permission:CREAR_ORDENES');
        Route::get('/{orden}',  [OrdenController::class, 'show'])->middleware('permission:VER_ORDENES');
        Route::put('/{orden}',  [OrdenController::class, 'update'])->middleware('permission:EDITAR_ORDENES');
        Route::put('/{orden}/station-status',                    [OrdenController::class, 'updateStationStatus'])->middleware('permission:EDITAR_ORDENES');
        Route::post('/{orden}/actualizar-estado-estacion',       [OrdenDetalleController::class, 'actualizarEstadoPorEstacion'])->middleware('permission:EDITAR_ORDENES');
        Route::post('/{orden}/cerrar',                           [OrdenController::class, 'cerrar'])->middleware('permission:CERRAR_ORDENES');
    });

    // ========== DETALLES DE ÓRDENES ==========
    Route::prefix('ordenes/{orden}/detalles')->group(function () {
        Route::post('/multiple',    [OrdenDetalleController::class, 'updateMultiple'])->middleware('permission:EDITAR_ORDENES');
        Route::get('/',             [OrdenDetalleController::class, 'index'])->middleware('permission:VER_ORDENES');
        Route::post('/',            [OrdenDetalleController::class, 'store'])->middleware('permission:CREAR_ORDENES');
        Route::get('/{detalle}',    [OrdenDetalleController::class, 'show'])->middleware('permission:VER_ORDENES');
        Route::put('/{detalle}',    [OrdenDetalleController::class, 'update'])->middleware('permission:EDITAR_ORDENES');
        Route::delete('/{detalle}', [OrdenDetalleController::class, 'destroy'])->middleware('permission:ELIMINAR_ORDENES');
    });

    // ========== CLIENTES ==========
    Route::prefix('clientes')->group(function () {
        Route::get('/select-list',       [ClienteController::class, 'selectList'])->middleware('permission:VER_CLIENTES');
        Route::get('/',                  [ClienteController::class, 'index'])->middleware('permission:VER_CLIENTES');
        Route::post('/',                 [ClienteController::class, 'store'])->middleware('permission:CREAR_CLIENTES');
        Route::get('/{cliente}',         [ClienteController::class, 'show'])->middleware('permission:VER_CLIENTE');
        Route::get('/{cliente}/historial',[ClienteController::class, 'historial'])->middleware('permission:VER_HISTORIAL_CLIENTE');
        Route::put('/{cliente}',         [ClienteController::class, 'update'])->middleware('permission:EDITAR_CLIENTES');
        Route::delete('/{cliente}',      [ClienteController::class, 'destroy'])->middleware('permission:ELIMINAR_CLIENTES');
    });

    // ========== REPORTES ==========
    Route::prefix('reportes')->middleware('permission:VER_REPORTES')->group(function () {
        Route::get('/ventas',                    [ReporteController::class, 'ventasPorPeriodo']);
        Route::get('/productos-mas-vendidos',    [ReporteController::class, 'productosMasVendidos']);
        Route::get('/clientes-frecuentes',       [ReporteController::class, 'clientesFrecuentes']);
        Route::get('/dashboard',                 [ReporteController::class, 'dashboard']);
        Route::get('/reporte-productos',         [ReporteController::class, 'reporteProductos']);
        Route::get('/download/{tipo}/{formato}', [ReporteController::class, 'download']);
        Route::post('/exportar',                 [ReporteController::class, 'exportar'])->middleware('permission:EXPORTAR_REPORTES');
    });

    // ========== USUARIOS ==========
    Route::prefix('users')->group(function () {
        Route::put('/{id}',    [UserController::class, 'updateById'])->middleware('permission:VER_RESTAURANTE');
        Route::delete('/{id}', [UserController::class, 'destroy'])->middleware('permission:ELIMINAR_EMPLEADOS');
    });

    // ========== MESEROS ==========
    Route::prefix('meseros')->group(function () {
        Route::get('/',                    [MeseroController::class, 'index']);
        Route::get('/mis-mesas',           [MeseroController::class, 'misMesas']);
        Route::get('/mis-ordenes',         [MeseroController::class, 'misOrdenes']);
        Route::post('/configurar-mesas',   [MeseroController::class, 'configurarTotalMesas']);
        Route::post('/asignar-mesas',      [MeseroController::class, 'asignarMesas']);
        Route::get('/metricas-ventas',     [MeseroController::class, 'metricasVentas']); 
    });

    // ========== OFERTAS ==========
    Route::prefix('ofertas')->group(function () {
        Route::get('/',         [OfertaController::class, 'index'])->middleware('permission:VER_RESTAURANTE');
        Route::post('/',        [OfertaController::class, 'store'])->middleware('permission:EDITAR_RESTAURANTE');
        Route::get('/{id}',     [OfertaController::class, 'show'])->middleware('permission:VER_RESTAURANTE');
        Route::put('/{id}',     [OfertaController::class, 'update'])->middleware('permission:EDITAR_RESTAURANTE');
        Route::delete('/{id}',  [OfertaController::class, 'destroy'])->middleware('permission:EDITAR_RESTAURANTE');
        Route::patch('/{id}/toggle', [OfertaController::class, 'toggleActive'])->middleware('permission:EDITAR_RESTAURANTE');
    });

    // ========== LOGS ==========
    Route::prefix('logs')->group(function () {
        Route::get('/acciones',  [LogController::class, 'acciones'])->middleware('permission:VER_LOGS');
        Route::get('/tablas',    [LogController::class, 'tablas'])->middleware('permission:VER_LOGS');
        Route::get('/',          [LogController::class, 'index'])->middleware('permission:VER_LOGS');
        Route::delete('/limpiar',[LogController::class, 'limpiar'])->middleware('permission:ELIMINAR_LOGS');
        Route::get('/{log}',     [LogController::class, 'show'])->middleware('permission:VER_LOGS');
    });

});

/*
|--------------------------------------------------------------------------
| RUTAS DE PRUEBA (SOLO DESARROLLO)
|--------------------------------------------------------------------------
*/
if (env('APP_ENV') === 'local') {
    Route::middleware(['auth:sanctum', 'permission:TEST'])
        ->get('/test-middleware', fn() => response()->json([
            'success' => true,
            'message' => 'Middleware test funcionando',
            'user'    => auth()->user()?->only(['id', 'name', 'email']),
        ]));
}