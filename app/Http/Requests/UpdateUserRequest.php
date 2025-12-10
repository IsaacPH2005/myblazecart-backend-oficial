<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

/**
 * Form Request para actualizar usuarios
 * Valida datos del usuario principal, datos generales y roles
 * Usa 'sometimes' para permitir actualizaciones parciales
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Implementar lógica de autorización según necesidades
        // return $this->user()->can('update', User::find($this->route('id')));
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Obtener ID del usuario desde la ruta
        $userId = $this->route('id');

        return [
            /*
            |--------------------------------------------------------------------------
            | Datos del Usuario Principal
            |--------------------------------------------------------------------------
            */
            'email' => [
                'sometimes',
                'email:rfc,dns',
                'unique:users,email,' . $userId,
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
            | Datos Generales (Todos opcionales con 'sometimes')
            |--------------------------------------------------------------------------
            */
            'general_data.nombre' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],
            'general_data.apellido' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],
            'general_data.documento_identidad' => [
                'sometimes',
                'string',
                'max:20',
                'unique:general_data,documento_identidad,' . $userId . ',user_id',
                'regex:/^[0-9]+$/'
            ],
            'general_data.celular' => [
                'sometimes',
                'string',
                'max:20',
                'regex:/^[\+]?[0-9\s\-\(\)]+$/'
            ],
            'general_data.direccion' => [
                'sometimes',
                'string',
                'max:255',
                'min:10'
            ],
            'general_data.ciudad' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],
            'general_data.departamento' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],

            /*
            |--------------------------------------------------------------------------
            | Datos Generales Opcionales
            |--------------------------------------------------------------------------
            */
            'general_data.nacimiento' => [
                'sometimes',
                'nullable',
                'date',
                'before:today',
                'after:1900-01-01'
            ],
            'general_data.genero' => [
                'sometimes',
                'nullable',
                'in:masculino,femenino,otro'
            ],
            'general_data.codigo_postal' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9A-Za-z\-\s]+$/'
            ],
            'general_data.contacto_emergencia_nombre' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/'
            ],
            'general_data.contacto_emergencia_telefono' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                'regex:/^[\+]?[0-9\s\-\(\)]+$/',
                'required_with:general_data.contacto_emergencia_nombre'
            ],
            'general_data.notas' => [
                'sometimes',
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
            'email.email' => 'Debe proporcionar un correo electrónico válido.',
            'email.unique' => 'Este correo electrónico ya está registrado por otro usuario.',
            
            // Roles
            'roles.array' => 'Los roles deben ser una lista válida.',
            'roles.min' => 'Debe asignar al menos un rol.',
            'roles.*.exists' => 'El rol seleccionado no existe.',
            'roles.*.distinct' => 'No puede asignar el mismo rol múltiples veces.',
            
            // Validaciones de regex para datos generales
            'general_data.nombre.regex' => 'El nombre solo puede contener letras y espacios.',
            'general_data.apellido.regex' => 'El apellido solo puede contener letras y espacios.',
            'general_data.documento_identidad.regex' => 'El documento de identidad solo puede contener números.',
            'general_data.documento_identidad.unique' => 'Este documento de identidad ya está registrado por otro usuario.',
            'general_data.celular.regex' => 'El número de celular tiene un formato inválido.',
            'general_data.ciudad.regex' => 'La ciudad solo puede contener letras y espacios.',
            'general_data.departamento.regex' => 'El departamento solo puede contener letras y espacios.',
            'general_data.contacto_emergencia_nombre.regex' => 'El nombre del contacto solo puede contener letras y espacios.',
            'general_data.contacto_emergencia_telefono.regex' => 'El teléfono de emergencia tiene un formato inválido.',
            
            // Validaciones de fechas
            'general_data.nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'general_data.nacimiento.after' => 'La fecha de nacimiento debe ser posterior al año 1900.',
            
            // Validaciones de longitud
            'general_data.direccion.min' => 'La dirección debe tener al menos :min caracteres.',
            'general_data.notas.max' => 'Las notas no pueden exceder :max caracteres.',
            
            // Contacto de emergencia
            'general_data.contacto_emergencia_telefono.required_with' => 'El teléfono de emergencia es obligatorio cuando se proporciona un nombre de contacto.',
            
            // Validaciones de género
            'general_data.genero.in' => 'El género debe ser: masculino, femenino u otro.',
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
            'message' => 'Error de validación en la actualización',
            'errors' => $validator->errors(),
            'error_count' => $validator->errors()->count(),
            'user_id' => $this->route('id')
        ], 422));
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Limpiar y formatear documento de identidad
        if ($this->has('general_data.documento_identidad')) {
            $documento = preg_replace('/\D/', '', $this->input('general_data.documento_identidad'));
            $this->merge([
                'general_data' => array_merge($this->input('general_data', []), [
                    'documento_identidad' => $documento
                ])
            ]);
        }

        // Limpiar y formatear número de celular
        if ($this->has('general_data.celular')) {
            $celular = preg_replace('/[^\d\+\-\(\)\s]/', '', $this->input('general_data.celular'));
            $this->merge([
                'general_data' => array_merge($this->input('general_data', []), [
                    'celular' => $celular
                ])
            ]);
        }

        // Limpiar teléfono de contacto de emergencia
        if ($this->has('general_data.contacto_emergencia_telefono')) {
            $telefono = preg_replace('/[^\d\+\-\(\)\s]/', '', $this->input('general_data.contacto_emergencia_telefono'));
            $this->merge([
                'general_data' => array_merge($this->input('general_data', []), [
                    'contacto_emergencia_telefono' => $telefono
                ])
            ]);
        }

        // Si la contraseña está vacía, removerla para no actualizarla
        if ($this->has('password') && empty($this->input('password'))) {
            $this->request->remove('password');
            $this->request->remove('password_confirmation');
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validación personalizada: no permitir remover el último admin
            if ($this->has('roles')) {
                $userId = $this->route('id');
                $user = \App\Models\User::find($userId);
                
                if ($user && $user->hasRole('admin')) {
                    $adminCount = \App\Models\User::role('admin')->count();
                    $newRoles = $this->input('roles', []);
                    
                    // Si es el último admin y no se incluye 'admin' en los nuevos roles
                    if ($adminCount <= 1 && !in_array('admin', $newRoles)) {
                        $validator->errors()->add('roles', 'No se puede remover el rol de administrador del último usuario admin.');
                    }
                }
            }
        });
    }
}