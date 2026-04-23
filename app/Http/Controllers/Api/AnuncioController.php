<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anuncio;
use Illuminate\Http\Request;

class AnuncioController extends Controller
{
    // Lista para el admin
    public function index(Request $request)
    {
        try {
            $restaurante = app('restaurante_activo');
            $anuncios = Anuncio::with('producto:id,nombre,precio,imagen')
                ->where('restaurante_id', $restaurante->id)
                ->orderBy('orden')->orderByDesc('created_at')
                ->get();

            return response()->json(['success'=>true,'data'=>$anuncios->map(fn($a)=>$this->transform($a))]);
        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error','error'=>$e->getMessage()],500);
        }
    }

    // Solo anuncios VIGENTES — para la marquesina
    public function vigentes(Request $request)
    {
        try {
            $restaurante = app('restaurante_activo');
            $tipo        = $request->get('tipo', 'cliente'); // cliente | interno

            $query = Anuncio::with('producto:id,nombre,precio,imagen')
                ->where('restaurante_id', $restaurante->id)
                ->vigentes();

            if ($tipo === 'cliente') {
                $query->where('mostrar_cliente', true);
            } else {
                $query->where('mostrar_interno', true);
            }

            $anuncios = $query->get();
            return response()->json(['success'=>true,'data'=>$anuncios->map(fn($a)=>$this->transform($a))]);
        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error','error'=>$e->getMessage()],500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'titulo'           => 'required|string|max:150',
            'contenido'        => 'nullable|string|max:500',
            'tipo'             => 'required|in:info,promo,alerta,producto',
            'producto_id'      => 'nullable|exists:productos,id',
            'precio_promo'     => 'nullable|numeric|min:0',
            'emoji'            => 'nullable|string|max:10',
            'color'            => 'nullable|in:indigo,emerald,amber,rose,blue,purple',
            'mostrar_cliente'  => 'boolean',
            'mostrar_interno'  => 'boolean',
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date|after_or_equal:fecha_inicio',
            'orden'            => 'nullable|integer',
        ]);
        try {
            $restaurante = app('restaurante_activo');
            $anuncio = Anuncio::create([
                'restaurante_id'  => $restaurante->id,
                'titulo'          => $request->titulo,
                'contenido'       => $request->contenido,
                'tipo'            => $request->tipo,
                'producto_id'     => $request->producto_id,
                'precio_promo'    => $request->precio_promo,
                'emoji'           => $request->emoji ?? $this->emojiPorTipo($request->tipo),
                'color'           => $request->color  ?? $this->colorPorTipo($request->tipo),
                'activo'          => true,
                'mostrar_cliente' => $request->boolean('mostrar_cliente', true),
                'mostrar_interno' => $request->boolean('mostrar_interno', false),
                'fecha_inicio'    => $request->fecha_inicio,
                'fecha_fin'       => $request->fecha_fin,
                'orden'           => $request->orden ?? 0,
            ]);
            return response()->json(['success'=>true,'message'=>'Anuncio creado','data'=>$this->transform($anuncio)],201);
        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error','error'=>$e->getMessage()],500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'titulo'          => 'sometimes|string|max:150',
            'contenido'       => 'nullable|string|max:500',
            'tipo'            => 'sometimes|in:info,promo,alerta,producto',
            'emoji'           => 'nullable|string|max:10',
            'color'           => 'nullable|in:indigo,emerald,amber,rose,blue,purple',
            'activo'          => 'sometimes|boolean',
            'mostrar_cliente' => 'sometimes|boolean',
            'mostrar_interno' => 'sometimes|boolean',
            'fecha_inicio'    => 'nullable|date',
            'fecha_fin'       => 'nullable|date',
            'orden'           => 'nullable|integer',
        ]);
        try {
            $restaurante = app('restaurante_activo');
            $anuncio = Anuncio::where('restaurante_id',$restaurante->id)->findOrFail($id);
            $anuncio->update($request->only([
                'titulo','contenido','tipo','producto_id','precio_promo',
                'emoji','color','activo','mostrar_cliente','mostrar_interno',
                'fecha_inicio','fecha_fin','orden',
            ]));
            return response()->json(['success'=>true,'message'=>'Actualizado','data'=>$this->transform($anuncio)]);
        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error','error'=>$e->getMessage()],500);
        }
    }

    public function destroy($id)
    {
        try {
            $restaurante = app('restaurante_activo');
            Anuncio::where('restaurante_id',$restaurante->id)->findOrFail($id)->delete();
            return response()->json(['success'=>true,'message'=>'Eliminado']);
        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error','error'=>$e->getMessage()],500);
        }
    }

    private function transform(Anuncio $a): array
    {
        return [
            'id'              => $a->id,
            'titulo'          => $a->titulo,
            'contenido'       => $a->contenido,
            'tipo'            => $a->tipo,
            'emoji'           => $a->emoji,
            'color'           => $a->color,
            'activo'          => $a->activo,
            'mostrar_cliente' => $a->mostrar_cliente,
            'mostrar_interno' => $a->mostrar_interno,
            'fecha_inicio'    => $a->fecha_inicio?->format('Y-m-d'),
            'fecha_fin'       => $a->fecha_fin?->format('Y-m-d'),
            'orden'           => $a->orden,
            'producto'        => $a->producto ? [
                'id'       => $a->producto->id,
                'nombre'   => $a->producto->nombre,
                'precio'   => (float) $a->producto->precio,
                'imagen'   => $a->producto->imagen,
            ] : null,
            'precio_promo'    => $a->precio_promo,
            'vigente'         => $a->activo &&
                (!$a->fecha_inicio || $a->fecha_inicio->isPast()) &&
                (!$a->fecha_fin    || $a->fecha_fin->isFuture()),
            'created_at'      => $a->created_at,
        ];
    }

    private function emojiPorTipo(string $tipo): string
    {
        return ['info'=>'ℹ️','promo'=>'🎉','alerta'=>'⚠️','producto'=>'🍽️'][$tipo] ?? '📢';
    }
    private function colorPorTipo(string $tipo): string
    {
        return ['info'=>'blue','promo'=>'emerald','alerta'=>'amber','producto'=>'indigo'][$tipo] ?? 'indigo';
    }
    // Anuncios públicos con filtro — para frontend de cliente
    public function indexPublic(Request $request)
    {
        try {
            $restauranteId  = $request->get('restaurante_id');
            $mostrarCliente = $request->boolean('mostrar_cliente', false);
            $mostrarInterno = $request->boolean('mostrar_interno', false);

            $query = Anuncio::with('producto:id,nombre,precio,imagen')
                ->where('activo', true);

            // Obligamos a filtrar por restaurante para evitar mezclas
            if ($restauranteId) {
                $query->where('restaurante_id', $restauranteId);
            } else {
                return response()->json(['success' => true, 'data' => []]);
            }

            if ($mostrarCliente) {
                $query->where('mostrar_cliente', true);
            }

            if ($mostrarInterno) {
                $query->where('mostrar_interno', true);
            }

            $anuncios = $query->orderBy('orden')->get();

            return response()->json([
                'success' => true,
                'data'    => $anuncios->map(fn($a) => $this->transform($a))
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    } 

    // Anuncios vigentes públicos (sin autenticación) — para marquesina del cliente
    public function vigentesPublic(Request $request)
    {
        try {
            $restauranteId = $request->get('restaurante_id');
            if (!$restauranteId) {
                return response()->json(['success' => false, 'message' => 'Se requiere restaurante_id'], 422);
            }

            $tipo  = $request->get('tipo', 'cliente');
            $query = Anuncio::with('producto:id,nombre,precio,imagen')
                ->where('restaurante_id', $restauranteId)
                ->vigentes();

            if ($tipo === 'cliente') {
                $query->where('mostrar_cliente', true);
            } else {
                $query->where('mostrar_interno', true);
            }

            $anuncios = $query->get();

            return response()->json([
                'success' => true,
                'data'    => $anuncios->map(fn($a) => $this->transform($a))
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }
}
