<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            PermissionRoleSeeder::class,
            LicenciaSeeder::class,
            PropietarioSeeder::class,
            UserSeeder::class,
            RestauranteSeeder::class,
            CategoriaSeeder::class,
            ProductoSeeder::class,
            IngredienteSeeder::class,
            ProductoIngredienteSeeder::class,
            PaqueteSeeder::class,
            PaqueteProductoSeeder::class,
            MesaMeseroSeeder::class,
            CajaSeeder::class,
            CajaMovimientoSeeder::class,
            ClienteSeeder::class,
        ]);
    }
}