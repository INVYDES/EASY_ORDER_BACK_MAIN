<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionRoleSeeder extends Seeder
{
    /**
     * IDs de permisos que realmente existen
     */
    private $existingPermissions = [
        1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,
        31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59,60,61,
        62,63,64,65,66,67
    ];

    public function run()
    {
        // Limpiar asignaciones existentes (opcional)
        // DB::table('permission_role')->truncate();

        // =====================================================
        // PROPIETARIO (ID 1) - TODOS LOS PERMISOS EXISTENTES
        // =====================================================
        foreach ($this->existingPermissions as $permisoId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permisoId,
                'role_id' => 1
            ]);
        }

        // =====================================================
        // ADMIN (ID 2) - PERMISOS DE ADMIN
        // =====================================================
        $adminPermisos = [
            1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18, // Gestión completa
            19,20,21,22,23, // Propietarios (solo admin)
            24,25,26,27,28, // Clientes
            29,30, // Reportes
            38,39,40,41,42, // Categorías
            43,44, // Logs
            57, // Historial cliente
            58,59,60,61 // Caja
        ];
        
        foreach ($adminPermisos as $permisoId) {
            if (in_array($permisoId, $this->existingPermissions)) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permisoId,
                    'role_id' => 2
                ]);
            }
        }

        // =====================================================
        // MESERO (ID 3)
        // =====================================================
        $meseroPermisos = [
            1, // VER_RESTAURANTE
            5, // VER_PRODUCTOS
            9,10,11,12, // VER,CREAR,EDITAR,CERRAR ORDENES
            13,14, // VER,CREAR VENTAS
            24,25,26,27, // VER,CREAR,EDITAR CLIENTES
            29, // VER_REPORTES
            38,39, // VER_CATEGORIAS
            57 // VER_HISTORIAL_CLIENTE
        ];
        
        foreach ($meseroPermisos as $permisoId) {
            if (in_array($permisoId, $this->existingPermissions)) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permisoId,
                    'role_id' => 3
                ]);
            }
        }

        // =====================================================
        // COCINA (ID 4)
        // =====================================================
        $cocinaPermisos = [
            1, // VER_RESTAURANTE
            5, // VER_PRODUCTOS
            9, // VER_ORDENES
            12, // CERRAR_ORDENES
            38,39 // VER_CATEGORIAS
        ];
        
        foreach ($cocinaPermisos as $permisoId) {
            if (in_array($permisoId, $this->existingPermissions)) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permisoId,
                    'role_id' => 4
                ]);
            }
        }

        // =====================================================
        // CAJA (ID 5)
        // =====================================================
        $cajaPermisos = [
            1, // VER_RESTAURANTE
            5, // VER_PRODUCTOS
            9, // VER_ORDENES
            12, // CERRAR_ORDENES
            13,14, // VER,CREAR VENTAS
            24,25, // VER_CLIENTES
            29,30, // VER,EXPORTAR REPORTES
            38,39, // VER_CATEGORIAS
            57, // VER_HISTORIAL_CLIENTE
            58,59,60,61 // CAJA COMPLETO
        ];
        
        foreach ($cajaPermisos as $permisoId) {
            if (in_array($permisoId, $this->existingPermissions)) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permisoId,
                    'role_id' => 5
                ]);
            }
        }

        // =====================================================
        // BARRA (ID 6)
        // =====================================================
        $barraPermisos = [
            1, // VER_RESTAURANTE
            5, // VER_PRODUCTOS
            9, // VER_ORDENES
            12, // CERRAR_ORDENES
            38,39 // VER_CATEGORIAS
        ];
        
        foreach ($barraPermisos as $permisoId) {
            if (in_array($permisoId, $this->existingPermissions)) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permisoId,
                    'role_id' => 6
                ]);
            }
        }

        // =====================================================
        // MENU (ID 7) - KIOSKO
        // =====================================================
        $menuPermisos = [
            1, // VER_RESTAURANTE
            5, // VER_PRODUCTOS
            10, // CREAR_ORDENES
            38, // VER_CATEGORIAS
        ];
        
        foreach ($menuPermisos as $permisoId) {
            if (in_array($permisoId, $this->existingPermissions)) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permisoId,
                    'role_id' => 7
                ]);
            }
        }

        $this->command->info('✅ Permisos asignados correctamente a los roles!');
    }
}