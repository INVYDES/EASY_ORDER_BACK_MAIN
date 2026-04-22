<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        // Obtener IDs de categorías
        $categoriaBebidas = DB::table('categorias')->where('nombre', 'Bebidas')->first();
        $categoriaComidas = DB::table('categorias')->where('nombre', 'Comidas')->first();
        $categoriaPostres = DB::table('categorias')->where('nombre', 'Postres')->first();

        $productos = [
            // Bebidas
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaBebidas->id,
                'nombre' => 'Coca Cola',
                'descripcion' => 'Refresco de cola 600ml',
                'precio' => 25.00,
                'stock' => 50,
                'stock_minimo' => 10,
                'activo' => 1
            ],
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaBebidas->id,
                'nombre' => 'Agua Natural',
                'descripcion' => 'Agua purificada 1L',
                'precio' => 15.00,
                'stock' => 100,
                'stock_minimo' => 20,
                'activo' => 1
            ],
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaBebidas->id,
                'nombre' => 'Café Americano',
                'descripcion' => 'Café americano recién hecho',
                'precio' => 35.00,
                'stock' => 30,
                'stock_minimo' => 5,
                'activo' => 1
            ],
            
            // Comidas
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaComidas->id,
                'nombre' => 'Hamburguesa Clásica',
                'descripcion' => 'Hamburguesa con queso, lechuga y tomate',
                'precio' => 85.00,
                'stock' => 20,
                'stock_minimo' => 5,
                'activo' => 1
            ],
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaComidas->id,
                'nombre' => 'Pizza Margarita',
                'descripcion' => 'Pizza con salsa de tomate, mozzarella y albahaca',
                'precio' => 150.00,
                'stock' => 15,
                'stock_minimo' => 3,
                'activo' => 1
            ],
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaComidas->id,
                'nombre' => 'Ensalada César',
                'descripcion' => 'Ensalada con pollo, crutones y aderezo César',
                'precio' => 95.00,
                'stock' => 10,
                'stock_minimo' => 2,
                'activo' => 1
            ],
            
            // Postres
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaPostres->id,
                'nombre' => 'Pastel de Chocolate',
                'descripcion' => 'Rebanada de pastel de chocolate',
                'precio' => 65.00,
                'stock' => 8,
                'stock_minimo' => 2,
                'activo' => 1
            ],
            [
                'restaurante_id' => 1,
                'categoria_id' => $categoriaPostres->id,
                'nombre' => 'Flan Napolitano',
                'descripcion' => 'Flan de vainilla con caramelo',
                'precio' => 45.00,
                'stock' => 12,
                'stock_minimo' => 3,
                'activo' => 1
            ],
        ];

        foreach ($productos as $producto) {
            DB::table('productos')->insert([
                'restaurante_id' => $producto['restaurante_id'],
                'categoria_id' => $producto['categoria_id'],
                'nombre' => $producto['nombre'],
                'descripcion' => $producto['descripcion'],
                'precio' => $producto['precio'],
                'stock' => $producto['stock'],
                'stock_minimo' => $producto['stock_minimo'],
                'activo' => $producto['activo'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}