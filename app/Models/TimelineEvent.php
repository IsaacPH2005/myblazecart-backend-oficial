<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TimelineEvent extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'investment_id',
        'titulo',
        'descripcion',
        'logo',
        'icono',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'color',
        'orden',
        'monto',
        'tipo_evento',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'monto' => 'decimal:2',
    ];

    /**
     * Relación polimórfica - puede ser User o Business
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relación con Investment
     */
    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    /**
     * Scope para ordenar eventos
     */
    public function scopeOrdenado($query)
    {
        return $query->orderBy('orden')->orderBy('fecha_inicio', 'desc');
    }

    /**
     * Scope para filtrar por propietario
     */
    public function scopeForOwner($query, $ownerType, $ownerId)
    {
        return $query->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId);
    }

    /**
     * Obtener color según estado
     */
    public function getColorEstado()
    {
        return match ($this->estado) {
            'pendiente' => '#FFA500',
            'en_proceso' => '#3B82F6',
            'completado' => '#10B981',
            'cancelado' => '#EF4444',
            default => $this->color ?? '#6B7280'
        };
    }
}
