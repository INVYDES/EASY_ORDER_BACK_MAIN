<?php
$host = 'db5020276576.hosting-data.io';
$db   = 'dbs15586511';
$user = 'dbu4165132';
$pass = 'Ferykaren2414$';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     
     $restauranteId = 4;
     $categoriasBase = [
        ['Cocina', '#10B981'],
        ['Barra',  '#6366F1'],
        ['Postres', '#EC4899'],
    ];

    echo "Reparando sucursal 4 (El Faro)...\n";
    
    foreach ($categoriasBase as $cat) {
        $stmt = $pdo->prepare("SELECT id FROM categorias WHERE restaurante_id = ? AND nombre = ?");
        $stmt->execute([$restauranteId, $cat[0]]);
        if (!$stmt->fetch()) {
            $sql = "INSERT INTO categorias (restaurante_id, nombre, color, activo, orden, created_at, updated_at) VALUES (?, ?, ?, 1, 0, NOW(), NOW())";
            $pdo->prepare($sql)->execute([$restauranteId, $cat[0], $cat[1]]);
            echo "✅ Creada: {$cat[0]}\n";
        } else {
            echo "ℹ️ Ya existía: {$cat[0]}\n";
        }
    }
    
    echo "\n¡Éxito! El restaurante ID 4 ya tiene sus categorías base.\n";
    
} catch (\PDOException $e) {
     echo "❌ Error de BD: " . $e->getMessage() . "\n";
}
