<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request para crear usuarios
 * Valida datos del usuario principal, datos generales y roles
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Implementar lógica de autorización según necesidades
        // return $this->user()->can('create', User::class);
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Datos del Usuario Principal
            |--------------------------------------------------------------------------
            */
            'email' => [
                'required',
                'email:rfc,dns',
                'unique:users,email',
                'max:255'
            ],
            'password' => [
                'required',
                'confirmed',
                'min:6',
                'max:255'
            ],

            /*
            |--------------------------------------------------------------------------
            | Roles del Usuario
            |--------------------------------------------------------------------------
            */
            'roles' => [
                'sometimes',
                'array',
                'min:1'
            ],
            'roles.*' => [
                'string',
                'exists:roles,name,guard_name,sanctum',
                'distinct'
            ],

            /*
            |--------------------------------------------------------------------------
            | Datos Generales (Obligatorios)
            |--------------------------------------------------------------------------
            */
            'general_data.nombre' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/' // Solo letras y espacios
            ],
            'general_data.apellido' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],
            'general_data.documento_identidad' => [
                'required',
                'string',
                'max:20',
                'unique:general_data,documento_identidad',
                'regex:/^[0-9]+$/' // Solo números
            ],
            'general_data.celular' => [
                'required',
                'string',
                'max:20',
                'regex:/^[\+]?[0-9\s\-\(\)]+$/' // Formato de teléfono flexible
            ],
            'general_data.direccion' => [
                'required',
                'string',
                'max:255',
                'min:10'
            ],
            'general_data.ciudad' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],
            'general_data.departamento' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],

            /*
            |--------------------------------------------------------------------------
            | Datos Generales (Opcionales)
            |--------------------------------------------------------------------------
            */
            'general_data.nacimiento' => [
                'nullable',
                'date',
                'before:today',
                'after:1900-01-01'
            ],
            'general_data.genero' => [
                'nullable',
                'in:masculino,femenino,otro'
            ],
            'general_data.codigo_postal' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9A-Za-z\-\s]+$/'
            ],
            'general_data.contacto_emergencia_nombre' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],
            'general_data.contacto_emergencia_telefono' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[\+]?[0-9\s\-\(\)]+$/',
                'required_with:general_data.contacto_emergencia_nombre' // Si hay nombre, debe haber teléfono
            ],
            'general_data.notas' => [
                'nullable',
                'string',
                'max:1000'
            ],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            // Usuario principal
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'roles' => 'roles',
            'roles.*' => 'rol',
            
            // Datos generales
            'general_data.nombre' => 'nombre',
            'general_data.apellido' => 'apellido',
            'general_data.documento_identidad' => 'documento de identidad',
            'general_data.celular' => 'número de celular',
            'general_data.nacimiento' => 'fecha de nacimiento',
            'general_data.genero' => 'género',
            'general_data.direccion' => 'dirección',
            'general_data.ciudad' => 'ciudad',
            'general_data.departamento' => 'departamento',
            'general_data.codigo_postal' => 'código postal',
            'general_data.contacto_emergencia_nombre' => 'nombre del contacto de emergencia',
            'general_data.contacto_emergencia_telefono' => 'teléfono del contacto de emergencia',
            'general_data.notas' => 'notas',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Email
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Debe proporcionar un correo electrónico válido.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            
            // Password
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'password.max' => 'La contraseña no puede exceder :max caracteres.',
            
            // Roles
            'roles.array' => 'Los roles deben ser una lista válida.',
            'roles.*.exists' => 'El rol seleccionado no existe.',
            'roles.*.distinct' => 'No puede asignar el mismo rol múltiples veces.',
            
            // Validaciones de regex
            'general_data.nombre.regex' => 'El nombre solo puede contener letras y espacios.',
            'general_data.apellido.regex' => 'El apellido solo puede contener letras y espacios.',
            'general_data.documento_identidad.regex' => 'El documento de identidad solo puede contener números.',
            'general_data.documento_identidad.unique' => 'Este documento de identidad ya está registrado.',
            'general_data.celular.regex' => 'El número de celular tiene un formato inválido.',
            'general_data.ciudad.regex' => 'La ciudad solo puede contener letras y espacios.',
            'general_data.departamento.regex' => 'El departamento solo puede contener letras y espacios.',
            
            // Validaciones de fechas
            'general_data.nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'general_data.nacimiento.after' => 'La fecha de nacimiento debe ser posterior al año 1900.',
            
            // Contacto de emergencia
            'general_data.contacto_emergencia_telefono.required_with' => 'El teléfono de emergencia es obligatorio cuando se proporciona un nombre de contacto.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Error de validación',
            'errors' => $validator->errors(),
            'error_count' => $validator->errors()->count()
        ], 422));
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Limpiar y formatear datos antes de la validación
        if ($this->has('general_data.documento_identidad')) {
            $this->merge([
                'general_data' => array_merge($this->input('general_data', []), [
                    'documento_identidad' => preg_replace('/\D/', '', $this->input('general_data.documento_identidad'))
                ])
            ]);
        }

        // Formatear celular
        if ($this->has('general_data.celular')) {
            $celular = preg_replace('/[^\d\+\-\(\)\s]/', '', $this->input('general_data.celular'));
            $this->merge([
                'general_data' => array_merge($this->input('general_data', []), [
                    'celular' => $celular
                ])
            ]);
        }
    }
}