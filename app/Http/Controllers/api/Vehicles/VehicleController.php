<?php

namespace App\Http\Controllers\api\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use App\Models\Vehicle;
use App\Models\GeneralData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class VehicleController extends Controller
{
    /**
     * Listar todos los vehículos
     */
    public function index()
    {
        $vehicles = Vehicle::with('user.generalData', 'documents', 'negocio')->get();

        // Agregar la URL completa de la foto a cada vehículo
        $vehicles->transform(function ($vehicle) {
            if ($vehicle->foto) {
                $vehicle->foto_url = asset($vehicle->foto);
            }
            return $vehicle;
        });

        return response()->json([
            'message' => 'Lista de vehículos',
            'datos' => $vehicles
        ]);
    }


    /**
     * Crear un nuevo vehículo
     */
    public function store(Request $request)
    {
        // Validación de los datos de entrada
        $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'negocio_id' => 'nullable|exists:businesses,id',
            'numero_vin' => 'required|string|max:255|unique:vehicles,numero_vin',
            'codigo_unico' => 'nullable|string|max:255|unique:vehicles,codigo_unico',
            'marca' => 'nullable|string|max:255',
            'modelo' => 'nullable|string|max:255',
            'año' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'numero_placa' => 'required|string|max:255|unique:vehicles,numero_placa',
            'numero_dot' => 'nullable|string|max:255',
            'tipo_vehiculo' => 'nullable|in:truck,trailer,semi,box_truck',
            'tipo_propiedad' => 'nullable|in:owned,leased,lease_on,flip_candidate',
            'precio_compra' => 'nullable|numeric|min:0',
            'fecha_compra' => 'nullable|date',
            'valor_actual' => 'nullable|numeric|min:0',
            'millaje' => 'required|integer|min:0',
            'vencimiento_registro' => 'nullable|date',
            'vencimiento_inspeccion' => 'nullable|date',
            'color' => 'nullable|string|max:255',
            'combustible' => 'nullable|in:diesel,gasolina,hibrido,electrico',
            'transmision' => 'nullable|in:manual,automatica',
            'capacidad_carga' => 'nullable|integer|min:0',
            'observaciones' => 'nullable|string|max:255',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        // Obtener información del usuario con sus datos generales
        $user = User::with('generalData')->find($request->user_id);

        // Inicializar variable para el nombre de la foto
        $nombreFoto = null;

        // Manejo de la foto si se proporciona
        if ($request->hasFile('foto')) {
            $foto = $request->file('foto');

            // Crear nombre del archivo usando marca y modelo
            $marca = $request->marca ?? 'sin_marca';
            $modelo = $request->modelo ?? 'sin_modelo';
            $nombreLimpio = $this->limpiarNombre($marca . '_' . $modelo);
            $extension = $foto->getClientOriginalExtension();
            $nombreFoto = $nombreLimpio . '_' . time() . '.' . $extension;

            // Crear la carpeta si no existe
            $rutaCarpeta = public_path('vehicle_photos');
            if (!file_exists($rutaCarpeta)) {
                mkdir($rutaCarpeta, 0755, true);
            }

            // Mover el archivo a la carpeta vehicle_photos
            $foto->move($rutaCarpeta, $nombreFoto);
        }

        // Creación del vehículo
        $vehicle = Vehicle::create([
            'user_id' => $request->user_id,
            'negocio_id' => $request->negocio_id,
            'numero_vin' => $request->numero_vin,
            'codigo_unico' => $request->codigo_unico,
            'marca' => $request->marca,
            'modelo' => $request->modelo,
            'año' => $request->año,
            'numero_placa' => $request->numero_placa,
            'numero_dot' => $request->numero_dot,
            'tipo_vehiculo' => $request->tipo_vehiculo,
            'tipo_propiedad' => $request->tipo_propiedad,
            'precio_compra' => $request->precio_compra,
            'fecha_compra' => $request->fecha_compra,
            'valor_actual' => $request->valor_actual,
            'millaje' => $request->millaje,
            'vencimiento_registro' => $request->vencimiento_registro,
            'vencimiento_inspeccion' => $request->vencimiento_inspeccion,
            'color' => $request->color,
            'combustible' => $request->combustible,
            'transmision' => $request->transmision,
            'capacidad_carga' => $request->capacidad_carga,
            'observaciones' => $request->observaciones,
            'foto' => $nombreFoto // Guardar solo el nombre del archivo
        ])->load('user', 'documents');

        return response()->json([
            'message' => 'Vehículo creado exitosamente',
            'datos' => $vehicle,
            'ruta_foto' => $nombreFoto ? asset('vehicle_photos/' . $nombreFoto) : null
        ], 201);
    }

    /**
     * Mostrar un vehículo específico
     */
    public function show($id)
    {
        try {
            $vehicle = Vehicle::with('user.generalData', 'documents', 'negocio')->findOrFail($id);

            return response()->json([
                'message' => 'Vehículo encontrado',
                'datos' => $vehicle,
                'ruta_foto' => $vehicle->foto ? asset('vehicle_photos/' . $vehicle->foto) : null
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Vehículo no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Crear un usuario con un vehículo
     */
    public function createUserWithVehicle(Request $request)
    {
        // Validación completa de todos los datos
        $request->validate([
            // Datos del usuario
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string|min:8',

            // Datos generales (general_data)
            'nombre' => 'required|string|max:255',
            'apellido' => 'required|string|max:255',
            'documento_identidad' => 'required|string|unique:general_data,documento_identidad',
            'celular' => 'required|string|max:20',
            'nacimiento' => 'nullable|date_format:Y/m/d',
            'genero' => 'nullable|in:masculino,femenino,otro',
            'direccion' => 'required|string|max:255',
            'ciudad' => 'required|string|max:255',
            'departamento' => 'required|string|max:255',
            'codigo_postal' => 'nullable|string|max:20',
            'contacto_emergencia_nombre' => 'nullable|string|max:255',
            'contacto_emergencia_telefono' => 'nullable|string|max:20',
            'notas' => 'nullable|string',

            // Datos del vehículo
            'numero_vin' => 'required|string|max:255|unique:vehicles,numero_vin',
            'codigo_unico' => 'nullable|string|max:255|unique:vehicles,codigo_unico',
            'marca' => 'nullable|string|max:255',
            'modelo' => 'nullable|string|max:255',
            'año' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'numero_placa' => 'required|string|max:255|unique:vehicles,numero_placa',
            'numero_dot' => 'nullable|string|max:255',
            'tipo_vehiculo' => 'nullable|in:truck,trailer,semi,box_truck',
            'tipo_propiedad' => 'nullable|in:owned,leased,lease_on,flip_candidate',
            'precio_compra' => 'nullable|numeric|min:0',
            'fecha_compra' => 'nullable|date',
            'valor_actual' => 'nullable|numeric|min:0',
            'millaje' => 'required|integer|min:0',
            'vencimiento_registro' => 'nullable|date',
            'vencimiento_inspeccion' => 'nullable|date',
            'color' => 'nullable|string|max:255',
            'combustible' => 'nullable|in:diesel,gasolina,hibrido,electrico',
            'transmision' => 'nullable|in:manual,automatica',
            'capacidad_carga' => 'nullable|integer|min:0',
            'estado' => 'required|in:activo,mantenimiento,inactivo,en_venta,vendido',
            'observaciones' => 'nullable|string|max:255',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        try {
            // Usar transacción para asegurar que todo se cree correctamente
            DB::beginTransaction();

            // 1. Crear el usuario
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'estado' => true
            ]);

            // 2. Crear los datos generales
            $generalData = GeneralData::create([
                'user_id' => $user->id,
                'nombre' => $request->nombre,
                'apellido' => $request->apellido,
                'documento_identidad' => $request->documento_identidad,
                'celular' => $request->celular,
                'nacimiento' => $request->nacimiento,
                'genero' => $request->genero,
                'direccion' => $request->direccion,
                'ciudad' => $request->ciudad,
                'departamento' => $request->departamento,
                'codigo_postal' => $request->codigo_postal,
                'contacto_emergencia_nombre' => $request->contacto_emergencia_nombre,
                'contacto_emergencia_telefono' => $request->contacto_emergencia_telefono,
                'notas' => $request->notas
            ]);

            // 3. Manejo de la foto del vehículo
            $nombreFoto = null;
            if ($request->hasFile('foto')) {
                $foto = $request->file('foto');

                // Crear nombre del archivo usando marca y modelo
                $marca = $request->marca ?? 'sin_marca';
                $modelo = $request->modelo ?? 'sin_modelo';
                $nombreLimpio = $this->limpiarNombre($marca . '_' . $modelo);
                $extension = $foto->getClientOriginalExtension();
                $nombreFoto = 'foto_vehiculo.' . $extension;

                // Crear la carpeta específica del vehículo
                $rutaVehiculo = "vehicle_photos/{$nombreLimpio}";
                $rutaCarpetaCompleta = public_path($rutaVehiculo);
                if (!file_exists($rutaCarpetaCompleta)) {
                    mkdir($rutaCarpetaCompleta, 0755, true);
                }

                // Mover el archivo a la carpeta del vehículo
                $foto->move($rutaCarpetaCompleta, $nombreFoto);

                // Guardar la ruta relativa completa
                $nombreFoto = "{$rutaVehiculo}/{$nombreFoto}";
            }

            // 4. Crear el vehículo
            $vehicle = Vehicle::create([
                'user_id' => $user->id,
                'numero_vin' => $request->numero_vin,
                'codigo_unico' => $request->codigo_unico,
                'marca' => $request->marca,
                'modelo' => $request->modelo,
                'año' => $request->año,
                'numero_placa' => $request->numero_placa,
                'numero_dot' => $request->numero_dot,
                'tipo_vehiculo' => $request->tipo_vehiculo,
                'tipo_propiedad' => $request->tipo_propiedad,
                'precio_compra' => $request->precio_compra,
                'fecha_compra' => $request->fecha_compra,
                'valor_actual' => $request->valor_actual,
                'millaje' => $request->millaje,
                'vencimiento_registro' => $request->vencimiento_registro,
                'vencimiento_inspeccion' => $request->vencimiento_inspeccion,
                'color' => $request->color,
                'combustible' => $request->combustible,
                'transmision' => $request->transmision,
                'capacidad_carga' => $request->capacidad_carga,
                'estado' => $request->estado,
                'is_active' => $request->estado === 'activo',
                'observaciones' => $request->observaciones,
                'foto' => $nombreFoto
            ]);

            // 5. Asignar el rol 'carrier' al usuario
            $user->assignRole('carrier');

            // Confirmar la transacción
            DB::commit();

            // Cargar las relaciones para la respuesta
            $user->load(['generalData', 'vehicles']);

            return response()->json([
                'message' => 'Usuario y vehículo creados exitosamente',
                'datos' => [
                    'user' => $user,
                    'general_data' => $user->generalData,
                    'vehicle' => $vehicle,
                    'foto_url' => $nombreFoto ? asset($nombreFoto) : null
                ]
            ], 201);
        } catch (\Exception $e) {
            // Revertir la transacción en caso de error
            DB::rollback();

            // Eliminar la foto si se subió pero falló la creación
            if ($nombreFoto && file_exists(public_path($nombreFoto))) {
                unlink(public_path($nombreFoto));
            }

            return response()->json([
                'message' => 'Error al crear el usuario y vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un vehículo existente
     */
    public function update(Request $request, $id)
    {
        try {
            // Buscar el vehículo
            $vehicle = Vehicle::with('user.generalData')->findOrFail($id);

            // Validación de los datos de entrada
            $request->validate([
                'numero_vin' => 'sometimes|required|string|max:255|unique:vehicles,numero_vin,' . $vehicle->id,
                'codigo_unico' => 'sometimes|nullable|string|max:255|unique:vehicles,codigo_unico,' . $vehicle->id,
                'marca' => 'sometimes|nullable|string|max:255',
                'modelo' => 'sometimes|nullable|string|max:255',
                'año' => 'sometimes|nullable|integer|min:1900|max:' . (date('Y') + 1),
                'numero_placa' => 'sometimes|required|string|max:255|unique:vehicles,numero_placa,' . $vehicle->id,
                'numero_dot' => 'sometimes|nullable|string|max:255',
                'tipo_vehiculo' => 'sometimes|nullable|in:truck,trailer,semi,box_truck',
                'tipo_propiedad' => 'sometimes|nullable|in:owned,leased,lease_on,flip_candidate',
                'precio_compra' => 'sometimes|nullable|numeric|min:0',
                'fecha_compra' => 'sometimes|nullable|date',
                'valor_actual' => 'sometimes|nullable|numeric|min:0',
                'millaje' => 'sometimes|required|integer|min:0',
                'vencimiento_registro' => 'sometimes|nullable|date',
                'vencimiento_inspeccion' => 'sometimes|nullable|date',
                'color' => 'sometimes|nullable|string|max:255',
                'combustible' => 'sometimes|nullable|in:diesel,gasolina,hibrido,electrico',
                'transmision' => 'sometimes|nullable|in:manual,automatica',
                'capacidad_carga' => 'sometimes|nullable|integer|min:0',
                'estado' => 'sometimes|required|in:activo,mantenimiento,inactivo,en_venta,vendido',
                'observaciones' => 'sometimes|nullable|string|max:255',
                'negocio_id' => 'sometimes|required|exists:businesses,id', // ✅ Validar negocio_id
                'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
            ]);

            DB::beginTransaction();

            // Guardar la foto anterior para eliminarla si se actualiza
            $fotoAnterior = $vehicle->foto;
            $nombreFoto = $fotoAnterior;

            // Manejo de la nueva foto si se proporciona
            if ($request->hasFile('foto')) {
                $foto = $request->file('foto');

                // Crear nombre del archivo usando marca y modelo
                $marca = $request->marca ?? ($vehicle->marca ?? 'sin_marca');
                $modelo = $request->modelo ?? ($vehicle->modelo ?? 'sin_modelo');
                $nombreLimpio = $this->limpiarNombre($marca . '_' . $modelo);
                $extension = $foto->getClientOriginalExtension();
                $nombreFoto = $nombreLimpio . '_' . time() . '.' . $extension;

                // Crear la carpeta si no existe
                $rutaCarpeta = public_path('vehicle_photos');
                if (!file_exists($rutaCarpeta)) {
                    mkdir($rutaCarpeta, 0755, true);
                }

                // Mover el archivo a la carpeta vehicle_photos
                $foto->move($rutaCarpeta, $nombreFoto);

                // Eliminar la foto anterior si existía
                if ($fotoAnterior && file_exists(public_path('vehicle_photos/' . $fotoAnterior))) {
                    unlink(public_path('vehicle_photos/' . $fotoAnterior));
                }
            }

            // Campos que se pueden actualizar
            $datosActualizar = $request->only([
                'numero_vin',
                'codigo_unico',
                'marca',
                'modelo',
                'año',
                'numero_placa',
                'numero_dot',
                'tipo_vehiculo',
                'tipo_propiedad',
                'precio_compra',
                'fecha_compra',
                'valor_actual',
                'millaje',
                'vencimiento_registro',
                'vencimiento_inspeccion',
                'color',
                'combustible',
                'transmision',
                'capacidad_carga',
                'estado',
                'observaciones',
                'negocio_id', // ✅ Agregado aquí
            ]);

            // Actualizar is_active basado en el estado
            if ($request->has('estado')) {
                $datosActualizar['is_active'] = $request->estado === 'activo';
            }

            // Solo actualizar la foto si se subió una nueva
            if ($request->hasFile('foto')) {
                $datosActualizar['foto'] = $nombreFoto;
            }

            // Actualizar el vehículo
            $vehicle->update($datosActualizar);

            DB::commit();

            // Recargar las relaciones
            $vehicle->load('user.generalData', 'documents');

            return response()->json([
                'message' => 'Vehículo actualizado exitosamente',
                'datos' => $vehicle,
                'ruta_foto' => $vehicle->foto ? asset('vehicle_photos/' . $vehicle->foto) : null
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();

            // Eliminar la nueva foto si se subió pero falló la actualización
            if (isset($nombreFoto) && $nombreFoto !== $fotoAnterior && file_exists(public_path('vehicle_photos/' . $nombreFoto))) {
                unlink(public_path('vehicle_photos/' . $nombreFoto));
            }

            return response()->json([
                'message' => 'Error al actualizar el vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un vehículo (soft delete recomendado)
     */
    public function destroy($id)
    {
        try {
            // Buscar el vehículo
            $vehicle = Vehicle::with('user')->findOrFail($id);

            DB::beginTransaction();

            // Eliminar la foto del vehículo si existe
            if ($vehicle->foto && file_exists(public_path('vehicle_photos/' . $vehicle->foto))) {
                unlink(public_path('vehicle_photos/' . $vehicle->foto));
            }

            // Remover el rol 'carrier' del usuario si no tiene otros vehículos
            if ($vehicle->user) {
                $otherVehicles = Vehicle::where('user_id', $vehicle->user_id)->where('id', '!=', $vehicle->id)->count();
                if ($otherVehicles === 0) {
                    $vehicle->user->removeRole('carrier');
                }
            }

            // Eliminar el vehículo
            $vehicle->delete();

            DB::commit();

            return response()->json([
                'message' => 'Vehículo eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();

            return response()->json([
                'message' => 'Error al eliminar el vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener solo los vehículos activos (is_active = true)
     * junto con sus datos de usuario y datos generales
     */
    public function getActiveVehicles(Request $request)
    {
        try {
            $query = Vehicle::with(['user.generalData', 'documents'])
                ->where('is_active', true)
                ->whereHas('user', function ($q) {
                    $q->where('estado', true);
                });

            // Filtros opcionales adicionales
            if ($request->has('estado') && $request->estado !== '') {
                $query->where('estado', $request->estado);
            }

            if ($request->has('tipo_vehiculo') && $request->tipo_vehiculo !== '') {
                $query->where('tipo_vehiculo', $request->tipo_vehiculo);
            }

            // Búsqueda opcional por VIN, placa, marca o modelo
            if ($request->has('search') && $request->search !== '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('numero_vin', 'like', "%{$search}%")
                        ->orWhere('numero_placa', 'like', "%{$search}%")
                        ->orWhere('marca', 'like', "%{$search}%")
                        ->orWhere('modelo', 'like', "%{$search}%")
                        ->orWhere('codigo_unico', 'like', "%{$search}%");
                });
            }

            // Ordenar por marca y modelo
            $query->orderBy('marca', 'asc')
                ->orderBy('modelo', 'asc');

            $vehicles = $query->get();

            // Agregar URL de foto a cada vehículo
            $vehicles->transform(function ($vehicle) {
                $vehicle->foto_url = $vehicle->foto ? asset('vehicle_photos/' . $vehicle->foto) : null;
                return $vehicle;
            });

            return response()->json([
                'message' => 'Vehículos activos obtenidos exitosamente',
                'datos' => $vehicles,
                'total' => $vehicles->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los vehículos activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Versión más simple para obtener datos básicos de vehículos activos
     */
    public function getSimpleActiveVehicles()
    {
        try {
            $vehicles = Vehicle::with(['user.generalData'])
                ->where('is_active', true)
                ->whereHas('user', function ($q) {
                    $q->where('estado', true);
                })
                ->get()
                ->map(function ($vehicle) {
                    return [
                        'id' => $vehicle->id,
                        'numero_vin' => $vehicle->numero_vin,
                        'codigo_unico' => $vehicle->codigo_unico,
                        'numero_placa' => $vehicle->numero_placa,
                        'marca' => $vehicle->marca,
                        'modelo' => $vehicle->modelo,
                        'año' => $vehicle->año,
                        'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                        'estado' => $vehicle->estado,
                        'foto_url' => $vehicle->foto ? asset('vehicle_photos/' . $vehicle->foto) : null,
                        'user' => [
                            'id' => $vehicle->user->id,
                            'email' => $vehicle->user->email,
                            'general_data' => $vehicle->user->generalData ? [
                                'nombre' => $vehicle->user->generalData->nombre,
                                'apellido' => $vehicle->user->generalData->apellido,
                                'documento_identidad' => $vehicle->user->generalData->documento_identidad,
                                'celular' => $vehicle->user->generalData->celular,
                                'direccion' => $vehicle->user->generalData->direccion,
                                'ciudad' => $vehicle->user->generalData->ciudad,
                                'departamento' => $vehicle->user->generalData->departamento,
                            ] : null
                        ]
                    ];
                });

            return response()->json([
                'message' => 'Vehículos activos obtenidos exitosamente',
                'datos' => $vehicles,
                'total' => $vehicles->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los vehículos activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener los vehículos asignados al usuario autenticado
     */
    public function getAuthenticatedUserVehicles()
    {
        try {
            // Obtener el usuario autenticado
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'Usuario no autenticado',
                ], 401);
            }

            // Consulta para obtener los vehículos del usuario autenticado
            // que pertenezcan al negocio con nombre "Lease on"
            $vehicles = Vehicle::with(['user.generalData', 'documents', 'negocio'])
                ->where('user_id', $user->id)
                ->whereHas('negocio', function ($query) {
                    $query->where('nombre', 'Lease on');
                })
                ->orderBy('marca', 'asc')
                ->orderBy('modelo', 'asc')
                ->get();

            // Agregar URL de foto a cada vehículo
            $vehicles->transform(function ($vehicle) {
                $vehicle->foto_url = $vehicle->foto ? asset('vehicle_photos/' . $vehicle->foto) : null;
                return $vehicle;
            });

            return response()->json([
                'message' => 'Vehículos del usuario autenticado en el negocio "Lease on" obtenidos exitosamente',
                'datos' => $vehicles,
                'total' => $vehicles->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los vehículos del usuario autenticado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método auxiliar para limpiar el nombre del archivo
     */
    private function limpiarNombre($nombre)
    {
        // Remover acentos y caracteres especiales
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
        // Reemplazar espacios y caracteres no alfanuméricos con guiones bajos
        $nombre = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre);
        // Remover guiones bajos duplicados
        $nombre = preg_replace('/_+/', '_', $nombre);
        // Remover guiones bajos del inicio y final
        $nombre = trim($nombre, '_');

        return strtolower($nombre);
    }

    /**
     * Obtener todos los vehículos con estado 'activo'
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function obtenerVehiculosActivos()
    {
        try {
            // Obtener vehículos activos con sus relaciones principales
            $vehicles = Vehicle::with(['user', 'negocio'])
                ->where('estado', 'activo')
                ->where('is_active', true)
                ->orderBy('marca')
                ->orderBy('modelo')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $vehicles,
                'message' => 'Vehículos activos obtenidos correctamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener vehículos activos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activar un vehículo
     */
    public function activate($id)
    {
        try {
            $vehicle = Vehicle::findOrFail($id);
            $vehicle->estado = 'activo';
            $vehicle->is_active = true;
            $vehicle->save();

            return response()->json([
                'message' => 'Vehículo activado exitosamente',
                'datos' => $vehicle
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al activar el vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Desactivar un vehículo
     */
    public function desactivate($id)
    {
        try {
            $vehicle = Vehicle::findOrFail($id);
            $vehicle->estado = 'inactivo';
            $vehicle->is_active = false;
            $vehicle->save();

            return response()->json([
                'message' => 'Vehículo desactivado exitosamente',
                'datos' => $vehicle
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al desactivar el vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
