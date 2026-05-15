<?php

/**
 * Script para crear el enlace simbólico del storage en servidores compartidos
 */

$target = __DIR__ . '/../storage/app/public';
$link = __DIR__ . '/storage';

echo "<h3>Configurador de Enlace Simbólico</h3>";
echo "Objetivo: <b>$target</b><br>";
echo "Enlace: <b>$link</b><br><br>";

if (file_exists($link)) {
    if (is_link($link)) {
        echo "<span style='color: orange;'>El enlace simbólico ya existe.</span>";
    } else {
        echo "<span style='color: red;'>Ya existe una carpeta o archivo llamado 'storage' en la carpeta public. Debes borrarla o renombrarla antes de ejecutar este script.</span>";
    }
} else {
    if (symlink($target, $link)) {
        echo "<span style='color: green;'>¡Éxito! El enlace simbólico ha sido creado correctamente.</span>";
    } else {
        echo "<span style='color: red;'>Error: No se pudo crear el enlace simbólico. Verifica los permisos de la carpeta 'public'.</span>";
    }
}

echo "<br><br><p><i>Por seguridad, borra este archivo (link.php) una vez que hayas terminado.</i></p>";
