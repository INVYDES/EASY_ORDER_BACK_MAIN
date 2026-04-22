<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Restaurantes (1-4)
            ['id' => 1, 'nombre' => 'VER_RESTAURANTE', 'descripcion' => 'Ver información del restaurante'],
            ['id' => 2, 'nombre' => 'CREAR_RESTAURANTE', 'descripcion' => 'Crear restaurante'],
            ['id' => 3, 'nombre' => 'EDITAR_RESTAURANTE', 'descripcion' => 'Editar restaurante'],
            ['id' => 4, 'nombre' => 'ELIMINAR_RESTAURANTE', 'descripcion' => 'Eliminar restaurante'],
            
            // Productos (5-8)
            ['id' => 5, 'nombre' => 'VER_PRODUCTOS', 'descripcion' => 'Ver productos'],
            ['id' => 6, 'nombre' => 'CREAR_PRODUCTOS', 'descripcion' => 'Crear productos'],
            ['id' => 7, 'nombre' => 'EDITAR_PRODUCTOS', 'descripcion' => 'Editar productos'],
            ['id' => 8, 'nombre' => 'ELIMINAR_PRODUCTOS', 'descripcion' => 'Eliminar productos'],
            
            // Ordenes (9-12)
            ['id' => 9, 'nombre' => 'VER_ORDENES', 'descripcion' => 'Ver ordenes'],
            ['id' => 10, 'nombre' => 'CREAR_ORDENES', 'descripcion' => 'Crear ordenes'],
            ['id' => 11, 'nombre' => 'EDITAR_ORDENES', 'descripcion' => 'Editar ordenes'],
            ['id' => 12, 'nombre' => 'CERRAR_ORDENES', 'descripcion' => 'Cerrar ordenes'],
            
            // Ventas (13-14)
            ['id' => 13, 'nombre' => 'VER_VENTAS', 'descripcion' => 'Ver ventas'],
            ['id' => 14, 'nombre' => 'CREAR_VENTAS', 'descripcion' => 'Crear ventas'],
            
            // Usuarios (15-18)
            ['id' => 15, 'nombre' => 'VER_USUARIOS', 'descripcion' => 'Ver usuarios'],
            ['id' => 16, 'nombre' => 'CREAR_USUARIOS', 'descripcion' => 'Crear usuarios'],
            ['id' => 17, 'nombre' => 'EDITAR_USUARIOS', 'descripcion' => 'Editar usuarios'],
            ['id' => 18, 'nombre' => 'ELIMINAR_USUARIOS', 'descripcion' => 'Eliminar usuarios'],
            
            // Propietarios (19-23)
            ['id' => 19, 'nombre' => 'VER_TODOS_PROPIETARIOS', 'descripcion' => 'Ver todos los propietarios'],
            ['id' => 20, 'nombre' => 'EDITAR_TODOS_PROPIETARIOS', 'descripcion' => 'Editar cualquier propietario'],
            ['id' => 21, 'nombre' => 'ELIMINAR_PROPIETARIOS', 'descripcion' => 'Eliminar propietarios'],
            ['id' => 22, 'nombre' => 'EDITAR_PROPIETARIO_LICENCIA', 'descripcion' => 'Editar asignaciones de licencias'],
            ['id' => 23, 'nombre' => 'ELIMINAR_PROPIETARIO_LICENCIA', 'descripcion' => 'Eliminar asignaciones de licencias'],
            
            // Clientes (24-28, 57)
            ['id' => 24, 'nombre' => 'VER_CLIENTES', 'descripcion' => 'Ver listado de clientes'],
            ['id' => 25, 'nombre' => 'VER_CLIENTE', 'descripcion' => 'Ver detalle de un cliente'],
            ['id' => 26, 'nombre' => 'CREAR_CLIENTES', 'descripcion' => 'Crear nuevos clientes'],
            ['id' => 27, 'nombre' => 'EDITAR_CLIENTES', 'descripcion' => 'Editar clientes existentes'],
            ['id' => 28, 'nombre' => 'ELIMINAR_CLIENTES', 'descripcion' => 'Eliminar clientes'],
            ['id' => 57, 'nombre' => 'VER_HISTORIAL_CLIENTE', 'descripcion' => 'Ver historial de compras de un cliente'],
            
            // Reportes (29-30)
            ['id' => 29, 'nombre' => 'VER_REPORTES', 'descripcion' => 'Ver reportes y estadísticas'],
            ['id' => 30, 'nombre' => 'EXPORTAR_REPORTES', 'descripcion' => 'Exportar reportes'],
            
            // Categorías (38-42)
            ['id' => 38, 'nombre' => 'VER_CATEGORIAS', 'descripcion' => 'Ver listado de categorías'],
            ['id' => 39, 'nombre' => 'VER_CATEGORIA', 'descripcion' => 'Ver detalle de una categoría'],
            ['id' => 40, 'nombre' => 'CREAR_CATEGORIAS', 'descripcion' => 'Crear nuevas categorías'],
            ['id' => 41, 'nombre' => 'EDITAR_CATEGORIAS', 'descripcion' => 'Editar categorías existentes'],
            ['id' => 42, 'nombre' => 'ELIMINAR_CATEGORIAS', 'descripcion' => 'Eliminar categorías'],
            
            // Logs (43-44)
            ['id' => 43, 'nombre' => 'VER_LOGS', 'descripcion' => 'Ver logs del sistema'],
            ['id' => 44, 'nombre' => 'ELIMINAR_LOGS', 'descripcion' => 'Eliminar logs antiguos'],
            
            // Caja (58-61)
            ['id' => 58, 'nombre' => 'VER_CAJA', 'descripcion' => 'Ver información de caja'],
            ['id' => 59, 'nombre' => 'ABRIR_CAJA', 'descripcion' => 'Abrir caja'],
            ['id' => 60, 'nombre' => 'CERRAR_CAJA', 'descripcion' => 'Cerrar caja'],
            ['id' => 61, 'nombre' => 'EDITAR_CAJA', 'descripcion' => 'Registrar movimientos en caja'],
            
            // Ingredientes (62-66)
            ['id' => 62, 'nombre' => 'VER_INGREDIENTES', 'descripcion' => 'Ver listado de ingredientes'],
            ['id' => 63, 'nombre' => 'CREAR_INGREDIENTES', 'descripcion' => 'Crear nuevos ingredientes'],
            ['id' => 64, 'nombre' => 'EDITAR_INGREDIENTES', 'descripcion' => 'Editar ingredientes existentes'],
            ['id' => 65, 'nombre' => 'ELIMINAR_INGREDIENTES', 'descripcion' => 'Eliminar ingredientes'],
            ['id' => 66, 'nombre' => 'AJUSTAR_STOCK_INGREDIENTES', 'descripcion' => 'Ajustar inventario de ingredientes'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['id' => $perm['id']],
                [
                    'id' => $perm['id'],
                    'nombre' => $perm['nombre'],
                    'descripcion' => $perm['descripcion'],
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );
        }
    }
}