<?php

namespace App\Http\Controllers;

use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TimelineController extends Controller
{
    /**
     * Timeline del usuario autenticado
     */
    public function myTimeline(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $eventos = $user->timelineEvents()
            ->with(['investment.vehicle', 'investment.business', 'investment.user'])
            ->ordenado()
            ->get()
            ->map(function ($evento) {
                return $this->formatEventoResponse($evento);
            });

        return response()->json([
            'user' => $user,
            'eventos' => $eventos
        ]);
    }

    /**
     * Timeline de un usuario específico (por ID)
     */
    public function userTimeline($userId)
    {
        if (!is_numeric($userId)) {
            return response()->json([
                'message' => 'ID de usuario inválido'
            ], 400);
        }

        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $eventos = $user->timelineEvents()
            ->with(['investment.vehicle', 'investment.business', 'investment.user'])
            ->ordenado()
            ->get()
            ->map(function ($evento) {
                return $this->formatEventoResponse($evento);
            });

        return response()->json([
            'user' => $user,
            'eventos' => $eventos
        ]);
    }

    /**
     * Timeline de un negocio específico
     */
    public function businessTimeline($businessId)
    {
        if (!is_numeric($businessId)) {
            return response()->json([
                'message' => 'ID de negocio inválido'
            ], 400);
        }

        $business = Business::find($businessId);

        if (!$business) {
            return response()->json([
                'message' => 'Negocio no encontrado'
            ], 404);
        }

        $eventos = $business->timelineEvents()
            ->with(['investment.vehicle', 'investment.user', 'investment.business'])
            ->ordenado()
            ->get()
            ->map(function ($evento) {
                return $this->formatEventoResponse($evento);
            });

        return response()->json([
            'business' => $business,
            'eventos' => $eventos
        ]);
    }

    /**
     * Crear evento en timeline - TODOS LOS CAMPOS COMPLETOS
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                // Campos polimórficos del owner
                'owner_type' => [
                    'required',
                    'string',
                    Rule::in(['App\\Models\\User', 'App\\Models\\Business'])
                ],
                'owner_id' => 'required|integer|min:1',

                // Relación con inversión (opcional)
                'investment_id' => 'nullable|exists:investments,id',

                // Información principal del evento
                'titulo' => 'required|string|max:255',
                'descripcion' => 'nullable|string|max:5000',
                'tipo_evento' => 'nullable|string|max:100',

                // Archivos y visualización
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'icono' => 'nullable|string|max:50',
                'color' => [
                    'nullable',
                    'string',
                    'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'
                ],

                // Fechas
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',

                // Estado y ordenamiento
                'estado' => [
                    'required',
                    'string',
                    Rule::in(['pendiente', 'en_proceso', 'completado', 'cancelado'])
                ],
                'orden' => 'nullable|integer|min:0',

                // Datos financieros
                'monto' => 'nullable|numeric|min:0|max:999999999.99',
            ], [
                // Mensajes owner
                'owner_type.required' => 'El tipo de propietario es obligatorio',
                'owner_type.in' => 'El tipo de propietario debe ser User o Business',
                'owner_id.required' => 'El ID del propietario es obligatorio',
                'owner_id.integer' => 'El ID del propietario debe ser un número entero',
                'owner_id.min' => 'El ID del propietario debe ser un número válido mayor a 0',

                // Mensajes investment
                'investment_id.exists' => 'La inversión seleccionada no existe',

                // Mensajes información principal
                'titulo.required' => 'El título es obligatorio',
                'titulo.max' => 'El título no puede exceder 255 caracteres',
                'descripcion.max' => 'La descripción no puede exceder 5000 caracteres',
                'tipo_evento.max' => 'El tipo de evento no puede exceder 100 caracteres',

                // Mensajes archivos
                'logo.image' => 'El logo debe ser una imagen',
                'logo.mimes' => 'El logo debe ser JPG, PNG, JPEG, GIF, SVG o WEBP',
                'logo.max' => 'El logo no puede superar los 2MB',
                'icono.max' => 'El icono no puede exceder 50 caracteres',
                'color.regex' => 'El color debe ser un código hexadecimal válido (ej: #FF5733)',

                // Mensajes fechas
                'fecha_inicio.required' => 'La fecha de inicio es obligatoria',
                'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida',
                'fecha_fin.date' => 'La fecha fin debe ser una fecha válida',
                'fecha_fin.after_or_equal' => 'La fecha fin debe ser igual o posterior a la fecha de inicio',

                // Mensajes estado
                'estado.required' => 'El estado es obligatorio',
                'estado.in' => 'El estado debe ser: pendiente, en_proceso, completado o cancelado',
                'orden.min' => 'El orden debe ser un número positivo',

                // Mensajes monto
                'monto.numeric' => 'El monto debe ser un número',
                'monto.min' => 'El monto no puede ser negativo',
                'monto.max' => 'El monto no puede exceder 999,999,999.99',
            ]);

            // Validar que el owner existe
            $ownerModel = $validated['owner_type'];
            $owner = $ownerModel::find($validated['owner_id']);

            if (!$owner) {
                return response()->json([
                    'message' => 'El propietario especificado no existe'
                ], 404);
            }

            // Procesar logo si existe
            if ($request->hasFile('logo')) {
                $validated['logo'] = $this->procesarLogo($request->file('logo'), $validated['titulo']);
            }

            // Auto-asignar orden si no se proporciona
            if (!isset($validated['orden'])) {
                $ultimoOrden = TimelineEvent::where('owner_type', $validated['owner_type'])
                    ->where('owner_id', $validated['owner_id'])
                    ->max('orden');
                $validated['orden'] = ($ultimoOrden ?? 0) + 1;
            }

            // Auto-asignar color basado en estado si no se proporciona
            if (!isset($validated['color'])) {
                $validated['color'] = $this->getColorPorEstado($validated['estado']);
            }

            // Crear evento
            $evento = TimelineEvent::create($validated);
            $evento->load(['investment.vehicle', 'investment.business', 'investment.user', 'owner']);

            return response()->json([
                'message' => 'Evento creado exitosamente',
                'evento' => $this->formatEventoResponse($evento)
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el evento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar evento - TODOS LOS CAMPOS COMPLETOS
     */
    public function update(Request $request, $id)
    {
        try {
            if (!is_numeric($id)) {
                return response()->json([
                    'message' => 'ID de evento inválido'
                ], 400);
            }

            $evento = TimelineEvent::find($id);

            if (!$evento) {
                return response()->json([
                    'message' => 'Evento no encontrado'
                ], 404);
            }

            $validated = $request->validate([
                // Relación con inversión (opcional)
                'investment_id' => 'nullable|exists:investments,id',

                // Información principal del evento
                'titulo' => 'sometimes|required|string|max:255',
                'descripcion' => 'nullable|string|max:5000',
                'tipo_evento' => 'nullable|string|max:100',

                // Archivos y visualización
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'icono' => 'nullable|string|max:50',
                'color' => [
                    'nullable',
                    'string',
                    'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'
                ],

                // Fechas
                'fecha_inicio' => 'sometimes|required|date',
                'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',

                // Estado y ordenamiento
                'estado' => [
                    'sometimes',
                    'required',
                    'string',
                    Rule::in(['pendiente', 'en_proceso', 'completado', 'cancelado'])
                ],
                'orden' => 'nullable|integer|min:0',

                // Datos financieros
                'monto' => 'nullable|numeric|min:0|max:999999999.99',
            ], [
                // Mensajes investment
                'investment_id.exists' => 'La inversión seleccionada no existe',

                // Mensajes información principal
                'titulo.required' => 'El título es obligatorio',
                'titulo.max' => 'El título no puede exceder 255 caracteres',
                'descripcion.max' => 'La descripción no puede exceder 5000 caracteres',
                'tipo_evento.max' => 'El tipo de evento no puede exceder 100 caracteres',

                // Mensajes archivos
                'logo.image' => 'El logo debe ser una imagen',
                'logo.mimes' => 'El logo debe ser JPG, PNG, JPEG, GIF, SVG o WEBP',
                'logo.max' => 'El logo no puede superar los 2MB',
                'icono.max' => 'El icono no puede exceder 50 caracteres',
                'color.regex' => 'El color debe ser un código hexadecimal válido (ej: #FF5733)',

                // Mensajes fechas
                'fecha_inicio.required' => 'La fecha de inicio es obligatoria',
                'fecha_inicio.date' => 'La fecha de inicio debe ser una fecha válida',
                'fecha_fin.date' => 'La fecha fin debe ser una fecha válida',
                'fecha_fin.after_or_equal' => 'La fecha fin debe ser igual o posterior a la fecha de inicio',

                // Mensajes estado
                'estado.required' => 'El estado es obligatorio',
                'estado.in' => 'El estado debe ser: pendiente, en_proceso, completado o cancelado',
                'orden.min' => 'El orden debe ser un número positivo',

                // Mensajes monto
                'monto.numeric' => 'El monto debe ser un número',
                'monto.min' => 'El monto no puede ser negativo',
                'monto.max' => 'El monto no puede exceder 999,999,999.99',
            ]);

            // Procesar nuevo logo si existe
            if ($request->hasFile('logo')) {
                // Eliminar logo anterior
                if ($evento->logo) {
                    $this->eliminarLogo($evento->logo);
                }

                $titulo = $request->titulo ?? $evento->titulo;
                $validated['logo'] = $this->procesarLogo($request->file('logo'), $titulo);
            }

            // Actualizar color si cambió el estado y no se especificó color
            if (isset($validated['estado']) && !isset($validated['color'])) {
                $validated['color'] = $this->getColorPorEstado($validated['estado']);
            }

            $evento->update($validated);
            $evento->load(['investment.vehicle', 'investment.business', 'investment.user', 'owner']);

            return response()->json([
                'message' => 'Evento actualizado exitosamente',
                'evento' => $this->formatEventoResponse($evento)
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar el evento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar evento
     */
    public function destroy($id)
    {
        try {
            if (!is_numeric($id)) {
                return response()->json([
                    'message' => 'ID de evento inválido'
                ], 400);
            }

            $evento = TimelineEvent::find($id);

            if (!$evento) {
                return response()->json([
                    'message' => 'Evento no encontrado'
                ], 404);
            }

            // Eliminar logo si existe
            if ($evento->logo) {
                $this->eliminarLogo($evento->logo);
            }

            $evento->delete();

            return response()->json([
                'message' => 'Evento eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el evento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reordenar eventos de un propietario
     */
    public function reordenar(Request $request)
    {
        try {
            $validated = $request->validate([
                'owner_type' => [
                    'required',
                    'string',
                    Rule::in(['App\\Models\\User', 'App\\Models\\Business'])
                ],
                'owner_id' => 'required|integer|min:1',
                'eventos' => 'required|array|min:1',
                'eventos.*.id' => 'required|exists:timeline_events,id',
                'eventos.*.orden' => 'required|integer|min:0'
            ], [
                'owner_type.required' => 'El tipo de propietario es obligatorio',
                'owner_type.in' => 'El tipo de propietario debe ser User o Business',
                'owner_id.required' => 'El ID del propietario es obligatorio',
                'owner_id.min' => 'El ID del propietario debe ser válido',
                'eventos.required' => 'La lista de eventos es obligatoria',
                'eventos.min' => 'Debe proporcionar al menos un evento',
                'eventos.*.id.required' => 'El ID del evento es obligatorio',
                'eventos.*.id.exists' => 'Uno o más eventos no existen',
                'eventos.*.orden.required' => 'El orden es obligatorio para cada evento',
                'eventos.*.orden.min' => 'El orden debe ser un número positivo',
            ]);

            foreach ($validated['eventos'] as $item) {
                TimelineEvent::where('id', $item['id'])
                    ->where('owner_type', $validated['owner_type'])
                    ->where('owner_id', $validated['owner_id'])
                    ->update(['orden' => $item['orden']]);
            }

            return response()->json([
                'message' => 'Orden actualizado exitosamente'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al reordenar eventos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un evento específico
     */
    public function show($id)
    {
        try {
            if (!is_numeric($id)) {
                return response()->json([
                    'message' => 'ID de evento inválido'
                ], 400);
            }

            $evento = TimelineEvent::with([
                'investment.vehicle',
                'investment.business',
                'investment.user',
                'owner'
            ])->find($id);

            if (!$evento) {
                return response()->json([
                    'message' => 'Evento no encontrado'
                ], 404);
            }

            return response()->json($this->formatEventoResponse($evento));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener el evento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Filtrar eventos por estado
     */
    public function filtrarPorEstado(Request $request)
    {
        try {
            $validated = $request->validate([
                'owner_type' => [
                    'required',
                    'string',
                    Rule::in(['App\\Models\\User', 'App\\Models\\Business'])
                ],
                'owner_id' => 'required|integer|min:1',
                'estado' => [
                    'required',
                    'string',
                    Rule::in(['pendiente', 'en_proceso', 'completado', 'cancelado'])
                ]
            ], [
                'owner_type.required' => 'El tipo de propietario es obligatorio',
                'owner_type.in' => 'El tipo de propietario debe ser User o Business',
                'owner_id.required' => 'El ID del propietario es obligatorio',
                'owner_id.min' => 'El ID del propietario debe ser válido',
                'estado.required' => 'El estado es obligatorio',
                'estado.in' => 'El estado debe ser: pendiente, en_proceso, completado o cancelado',
            ]);

            $eventos = TimelineEvent::where('owner_type', $validated['owner_type'])
                ->where('owner_id', $validated['owner_id'])
                ->where('estado', $validated['estado'])
                ->ordenado()
                ->with(['investment.vehicle', 'investment.business', 'investment.user'])
                ->get()
                ->map(function ($evento) {
                    return $this->formatEventoResponse($evento);
                });

            return response()->json($eventos);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al filtrar eventos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Filtrar eventos por rango de fechas
     */
    public function filtrarPorFechas(Request $request)
    {
        try {
            $validated = $request->validate([
                'owner_type' => [
                    'required',
                    'string',
                    Rule::in(['App\\Models\\User', 'App\\Models\\Business'])
                ],
                'owner_id' => 'required|integer|min:1',
                'fecha_desde' => 'required|date',
                'fecha_hasta' => 'required|date|after_or_equal:fecha_desde'
            ], [
                'owner_type.required' => 'El tipo de propietario es obligatorio',
                'owner_type.in' => 'El tipo de propietario debe ser User o Business',
                'owner_id.required' => 'El ID del propietario es obligatorio',
                'owner_id.min' => 'El ID del propietario debe ser válido',
                'fecha_desde.required' => 'La fecha desde es obligatoria',
                'fecha_desde.date' => 'La fecha desde debe ser válida',
                'fecha_hasta.required' => 'La fecha hasta es obligatoria',
                'fecha_hasta.date' => 'La fecha hasta debe ser válida',
                'fecha_hasta.after_or_equal' => 'La fecha hasta debe ser igual o posterior a fecha desde',
            ]);

            $eventos = TimelineEvent::where('owner_type', $validated['owner_type'])
                ->where('owner_id', $validated['owner_id'])
                ->whereBetween('fecha_inicio', [
                    $validated['fecha_desde'],
                    $validated['fecha_hasta']
                ])
                ->ordenado()
                ->with(['investment.vehicle', 'investment.business', 'investment.user'])
                ->get()
                ->map(function ($evento) {
                    return $this->formatEventoResponse($evento);
                });

            return response()->json($eventos);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al filtrar eventos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Filtrar eventos por tipo
     */
    public function filtrarPorTipo(Request $request)
    {
        try {
            $validated = $request->validate([
                'owner_type' => [
                    'required',
                    'string',
                    Rule::in(['App\\Models\\User', 'App\\Models\\Business'])
                ],
                'owner_id' => 'required|integer|min:1',
                'tipo_evento' => 'required|string|max:100'
            ], [
                'owner_type.required' => 'El tipo de propietario es obligatorio',
                'owner_type.in' => 'El tipo de propietario debe ser User o Business',
                'owner_id.required' => 'El ID del propietario es obligatorio',
                'owner_id.min' => 'El ID del propietario debe ser válido',
                'tipo_evento.required' => 'El tipo de evento es obligatorio',
                'tipo_evento.max' => 'El tipo de evento no puede exceder 100 caracteres',
            ]);

            $eventos = TimelineEvent::where('owner_type', $validated['owner_type'])
                ->where('owner_id', $validated['owner_id'])
                ->where('tipo_evento', $validated['tipo_evento'])
                ->ordenado()
                ->with(['investment.vehicle', 'investment.business', 'investment.user'])
                ->get()
                ->map(function ($evento) {
                    return $this->formatEventoResponse($evento);
                });

            return response()->json($eventos);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al filtrar eventos por tipo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas del timeline - COMPLETAS
     */
    public function estadisticas(Request $request)
    {
        try {
            $validated = $request->validate([
                'owner_type' => [
                    'required',
                    'string',
                    Rule::in(['App\\Models\\User', 'App\\Models\\Business'])
                ],
                'owner_id' => 'required|integer|min:1'
            ], [
                'owner_type.required' => 'El tipo de propietario es obligatorio',
                'owner_type.in' => 'El tipo de propietario debe ser User o Business',
                'owner_id.required' => 'El ID del propietario es obligatorio',
                'owner_id.min' => 'El ID del propietario debe ser un número válido mayor a 0',
            ]);

            $ownerModel = $validated['owner_type'];

            if (!class_exists($ownerModel)) {
                return response()->json([
                    'message' => 'Tipo de propietario inválido'
                ], 400);
            }

            $owner = $ownerModel::find($validated['owner_id']);

            if (!$owner) {
                return response()->json([
                    'message' => 'Propietario no encontrado'
                ], 404);
            }

            $query = TimelineEvent::forOwner($validated['owner_type'], $validated['owner_id']);

            $stats = [
                // Totales generales
                'total_eventos' => $query->count(),

                // Por estado
                'por_estado' => [
                    'pendientes' => (clone $query)->where('estado', 'pendiente')->count(),
                    'en_proceso' => (clone $query)->where('estado', 'en_proceso')->count(),
                    'completados' => (clone $query)->where('estado', 'completado')->count(),
                    'cancelados' => (clone $query)->where('estado', 'cancelado')->count(),
                ],

                // Datos financieros
                'financiero' => [
                    'monto_total' => (clone $query)->sum('monto') ?? 0,
                    'monto_completado' => (clone $query)->where('estado', 'completado')->sum('monto') ?? 0,
                    'monto_pendiente' => (clone $query)->whereIn('estado', ['pendiente', 'en_proceso'])->sum('monto') ?? 0,
                    'monto_promedio' => (clone $query)->avg('monto') ?? 0,
                ],

                // Por tipo de evento
                'por_tipo' => (clone $query)
                    ->selectRaw('tipo_evento, COUNT(*) as cantidad, SUM(monto) as monto_total')
                    ->whereNotNull('tipo_evento')
                    ->groupBy('tipo_evento')
                    ->get(),

                // Eventos con inversión
                'con_inversion' => (clone $query)->whereNotNull('investment_id')->count(),
                'sin_inversion' => (clone $query)->whereNull('investment_id')->count(),

                // Temporalidad
                'proximos_eventos' => (clone $query)
                    ->where('fecha_inicio', '>', now())
                    ->where('estado', '!=', 'cancelado')
                    ->count(),
                'eventos_vencidos' => (clone $query)
                    ->where('fecha_fin', '<', now())
                    ->whereIn('estado', ['pendiente', 'en_proceso'])
                    ->count(),

                // Información adicional
                'ultimo_evento_fecha' => (clone $query)->max('fecha_inicio'),
                'proximo_evento_fecha' => (clone $query)
                    ->where('fecha_inicio', '>', now())
                    ->where('estado', '!=', 'cancelado')
                    ->min('fecha_inicio'),
            ];

            return response()->json($stats);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * MÉTODOS PRIVADOS DE UTILIDAD
     */

    /**
     * Formatear respuesta del evento con URL completa del logo
     */
    private function formatEventoResponse($evento)
    {
        $eventoArray = $evento->toArray();

        // Formatear logo
        if (!empty($eventoArray['logo'])) {
            $eventoArray['logo_url'] = $this->getLogoUrl($eventoArray['logo']);
            $eventoArray['logo_filename'] = $eventoArray['logo'];
        } else {
            $eventoArray['logo_url'] = null;
            $eventoArray['logo_filename'] = null;
        }

        // Formatear monto
        if (isset($eventoArray['monto'])) {
            $eventoArray['monto_formateado'] = '$' . number_format($eventoArray['monto'], 2);
        }

        // Información adicional del estado
        $eventoArray['color_estado'] = $this->getColorPorEstado($eventoArray['estado']);
        $eventoArray['estado_texto'] = $this->getTextoEstado($eventoArray['estado']);

        // Calcular duración si hay fecha fin
        if (!empty($eventoArray['fecha_fin']) && !empty($eventoArray['fecha_inicio'])) {
            $inicio = \Carbon\Carbon::parse($eventoArray['fecha_inicio']);
            $fin = \Carbon\Carbon::parse($eventoArray['fecha_fin']);
            $eventoArray['duracion_dias'] = $inicio->diffInDays($fin);
        }

        return $eventoArray;
    }

    /**
     * Procesar y guardar logo
     */
    private function procesarLogo($file, $titulo)
    {
        $nombreLimpio = $this->limpiarNombre($titulo);
        $extension = $file->getClientOriginalExtension();
        $nombreLogo = $nombreLimpio . '_' . time() . '.' . $extension;

        $rutaCarpeta = public_path('timeline_logos');

        if (!file_exists($rutaCarpeta)) {
            mkdir($rutaCarpeta, 0755, true);
        }

        $file->move($rutaCarpeta, $nombreLogo);

        return $nombreLogo;
    }

    /**
     * Eliminar logo del servidor
     */
    private function eliminarLogo($nombreArchivo)
    {
        if (!$nombreArchivo) {
            return;
        }

        $rutaLogo = public_path('timeline_logos/' . $nombreArchivo);

        if (file_exists($rutaLogo)) {
            unlink($rutaLogo);
        }
    }

    /**
     * Limpiar nombre de archivo
     */
    private function limpiarNombre($nombre)
    {
        $nombre = Str::slug($nombre);
        $nombre = substr($nombre, 0, 50);
        return $nombre ?: 'evento';
    }

    /**
     * Obtener URL completa del logo
     */
    private function getLogoUrl($nombreArchivo)
    {
        if (!$nombreArchivo) {
            return null;
        }

        // Si ya es una URL completa, devolverla tal cual
        if (str_starts_with($nombreArchivo, 'http')) {
            return $nombreArchivo;
        }

        // Usar asset() para construir URL completa
        return asset('timeline_logos/' . $nombreArchivo);
    }

    /**
     * Obtener color según estado
     */
    private function getColorPorEstado($estado)
    {
        return match ($estado) {
            'pendiente' => '#FFA500',
            'en_proceso' => '#3B82F6',
            'completado' => '#10B981',
            'cancelado' => '#EF4444',
            default => '#6B7280'
        };
    }

    /**
     * Obtener texto descriptivo del estado
     */
    private function getTextoEstado($estado)
    {
        return match ($estado) {
            'pendiente' => 'Pendiente',
            'en_proceso' => 'En Proceso',
            'completado' => 'Completado',
            'cancelado' => 'Cancelado',
            default => 'Desconocido'
        };
    }
}
