<?php

namespace App\Imports;

use App\Models\Category;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Facades\Log;

class CategoriesImport implements ToModel, WithHeadingRow, WithValidation
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        return new Category([
            'codigo'        => (string) $row['codigo'],  // Convertir a string
            'nombre'        => (string) $row['nombre'],
            'clasificacion' => (string) $row['clasificacion'],
            'subcategoria'  => (string) $row['subcategoria'],
            'agrupacion'    => (string) $row['agrupacion'],
            'descripcion'   => (string) $row['descripcion'],
            'estado'        => true, // Por defecto activas
        ]);
    }

    /**
     * Reglas de validación para cada fila.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'codigo'        => 'required',  // Acepta string o numeric
            'nombre'        => 'required|string|max:255',
            'clasificacion' => 'required|string|max:255',
            'subcategoria'  => 'required|string|max:255',
            'agrupacion'    => 'required|string|max:255',
            'descripcion'   => 'required|string|max:1000',
        ];
    }

    /**
     * Mensajes de error personalizados.
     *
     * @return array
     */
    public function customValidationMessages(): array
    {
        return [
            // Código
            'codigo.required' => 'El código es obligatorio.',
            'codigo.string'   => 'El código debe ser una cadena de texto.',
            'codigo.max'      => 'El código no puede superar los 255 caracteres.',

            // Nombre (Español-Inglés)
            'nombre.required' => 'El nombre (Español-Inglés) es obligatorio.',
            'nombre.string'   => 'El nombre debe ser una cadena de texto.',
            'nombre.max'      => 'El nombre no puede superar los 255 caracteres.',

            // Clasificación
            'clasificacion.required' => 'La clasificación es obligatoria.',
            'clasificacion.string'   => 'La clasificación debe ser una cadena de texto.',
            'clasificacion.max'      => 'La clasificación no puede superar los 255 caracteres.',

            // Subcategoría
            'subcategoria.required' => 'La subcategoría es obligatoria.',
            'subcategoria.string'   => 'La subcategoría debe ser una cadena de texto.',
            'subcategoria.max'      => 'La subcategoría no puede superar los 255 caracteres.',

            // Agrupación
            'agrupacion.required' => 'La agrupación es obligatoria.',
            'agrupacion.string'   => 'La agrupación debe ser una cadena de texto.',
            'agrupacion.max'      => 'La agrupación no puede superar los 255 caracteres.',

            // Descripción
            'descripcion.required' => 'La descripción es obligatoria.',
            'descripcion.string'   => 'La descripción debe ser una cadena de texto.',
            'descripcion.max'      => 'La descripción no puede superar los 1000 caracteres.',
        ];
    }
}
