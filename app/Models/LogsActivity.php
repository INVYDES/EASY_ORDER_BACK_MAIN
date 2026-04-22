<?php
namespace App\Models;

use Illuminate\Support\Facades\Auth;
use App\Models\Log;

trait LogsActivity
{
    public static function bootLogsActivity()
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::$event(function ($model) use ($event) {
                $user = Auth::user();
                $ip = app()->runningInConsole() ? null : request()->ip();

                Log::create([
                    'user_id' => $user ? $user->id : null,
                    'accion' => strtoupper($event),
                    'tabla_afectada' => $model->getTable(),
                    'registro_id' => $model->id ?? null,
                    'descripcion' => "{$event} automático en {$model->getTable()}",
                    'ip_address' => $ip,
                ]);
            });
        }
    }
}