<?php
// app/Models/Investment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investment extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'user_id',
        'business_id',
        'monto_inversion',
        'descripcion',
        'notas',
        'active',
        'estado',
    ];

    protected $casts = [
        'monto_inversion' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * Relación con el usuario inversionista
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con el vehículo
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Relación con el negocio
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Eventos del timeline relacionados con esta inversión
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(TimelineEvent::class);
    }

    /**
     * Scope para inversiones activas
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope por estado
     */
    public function scopeByEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }

    /**
     * Al crear una inversión, crear eventos en los timelines
     */
    protected static function booted()
    {
        static::created(function ($investment) {
            // Crear evento en timeline del usuario
            if ($investment->user_id) {
                $investment->user->addTimelineEvent([
                    'investment_id' => $investment->id,
                    'titulo' => "Nueva Inversión - $" . number_format($investment->monto_inversion, 2),
                    'descripcion' => $investment->descripcion,
                    'fecha_inicio' => now(),
                    'estado' => $investment->estado,
                    'tipo_evento' => 'inversion',
                    'monto' => $investment->monto_inversion,
                    'icono' => '💰',
                    'color' => '#10B981',
                ]);
            }

            // Crear evento en timeline del negocio
            if ($investment->business_id) {
                $investment->business->addTimelineEvent([
                    'investment_id' => $investment->id,
                    'titulo' => "Inversión Recibida - $" . number_format($investment->monto_inversion, 2),
                    'descripcion' => $investment->descripcion,
                    'fecha_inicio' => now(),
                    'estado' => $investment->estado,
                    'tipo_evento' => 'inversion_recibida',
                    'monto' => $investment->monto_inversion,
                    'icono' => '💵',
                    'color' => '#3B82F6',
                ]);
            }
        });

        static::updated(function ($investment) {
            // Actualizar eventos relacionados cuando cambia el estado
            if ($investment->isDirty('estado')) {
                $investment->timelineEvents()->update([
                    'estado' => $investment->estado
                ]);
            }
        });
    }
}
