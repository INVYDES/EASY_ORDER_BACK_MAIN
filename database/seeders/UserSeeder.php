<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Usuario PROPIETARIO
        DB::table('users')->updateOrInsert(
            ['id' => 1],
            [
                'id' => 1,
                'propietario_id' => 1,
                'name' => 'Juan Perez',
                'email' => 'juan@empresa.com',
                'username' => 'juan_admin',
                'password' => Hash::make('password123'),
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar rol PROPIETARIO al usuario 1
        DB::table('role_user')->updateOrInsert(
            ['user_id' => 1, 'role_id' => 1],
            [
                'user_id' => 1,
                'role_id' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Usuario ADMIN
        DB::table('users')->updateOrInsert(
            ['id' => 2],
            [
                'id' => 2,
                'propietario_id' => 1,
                'name' => 'Admin Sistema',
                'email' => 'admin@empresa.com',
                'username' => 'admin',
                'password' => Hash::make('password123'),
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar rol ADMIN al usuario 2
        DB::table('role_user')->updateOrInsert(
            ['user_id' => 2, 'role_id' => 2],
            [
                'user_id' => 2,
                'role_id' => 2,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Usuario MESERO
        DB::table('users')->updateOrInsert(
            ['id' => 3],
            [
                'id' => 3,
                'propietario_id' => 1,
                'name' => 'Maria Garcia',
                'email' => 'mesero@empresa.com',
                'username' => 'maria_mesera',
                'password' => Hash::make('password123'),
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar rol MESERO al usuario 3
        DB::table('role_user')->updateOrInsert(
            ['user_id' => 3, 'role_id' => 3],
            [
                'user_id' => 3,
                'role_id' => 3,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Usuario COCINA
        DB::table('users')->updateOrInsert(
            ['id' => 4],
            [
                'id' => 4,
                'propietario_id' => 1,
                'name' => 'Carlos Lopez',
                'email' => 'cocina@empresa.com',
                'username' => 'carlos_cocina',
                'password' => Hash::make('password123'),
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar rol COCINA al usuario 4
        DB::table('role_user')->updateOrInsert(
            ['user_id' => 4, 'role_id' => 4],
            [
                'user_id' => 4,
                'role_id' => 4,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Usuario CAJA
        DB::table('users')->updateOrInsert(
            ['id' => 5],
            [
                'id' => 5,
                'propietario_id' => 1,
                'name' => 'Laura Diaz',
                'email' => 'caja@empresa.com',
                'username' => 'laura_caja',
                'password' => Hash::make('password123'),
                'created_at' => now(),
                'updated_at' => now()
            ]
        );

        // Asignar rol CAJA al usuario 5
        DB::table('role_user')->updateOrInsert(
            ['user_id' => 5, 'role_id' => 5],
            [
                'user_id' => 5,
                'role_id' => 5,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }
}