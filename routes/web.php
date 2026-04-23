<?php

use Illuminate\Support\Facades\Artisan; // Añade esta línea al principio si no está

Route::get('/instalar-puente', function () {
    $exitCode = Artisan::call('storage:link');
    return 'Puente de imágenes creado con éxito: ' . $exitCode;
});
