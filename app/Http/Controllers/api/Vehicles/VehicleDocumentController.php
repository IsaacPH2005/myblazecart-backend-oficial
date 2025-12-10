<?php

namespace App\Http\Controllers\api\Vehicles;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\VehicleDocument;
use App\Models\Vehicle;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class VehicleDocumentController extends Controller
{
    /**
     * Listar todos los documentos de vehículos
     * Devuelve todos los documentos con la URL completa para previsualización en el frontend
     */
    public function index()
    {
        try {
            // Obtener todos los documentos con su relación de vehículo
            $documents = VehicleDocument::with('vehicle')->get();

            // Procesar cada documento para generar URLs accesibles
            foreach ($documents as $document) {
                if ($document->archivo) {
                    // Generar URL completa para previsualización en frontend
                    $document->url_previsualizacion = asset($document->archivo);

                    // Verificar si el archivo existe físicamente
                    $document->archivo_existe = file_exists(public_path($document->archivo));
                }
            }

            return response()->json([
                'message' => 'Documentos de vehículos obtenidos exitosamente',
                'datos' => $documents
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los documentos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Mostrar un documento específico por su ID
     */
    public function show(String $id)
    {
        try {
            // Obtener documento específico por su ID
            $document = VehicleDocument::with('vehicle')->findOrFail($id);

            if ($document->archivo) {
                // Generar URL completa para previsualización en frontend
                $document->url_previsualizacion = asset($document->archivo);

                // Verificar si el archivo existe físicamente
                $document->archivo_existe = file_exists(public_path($document->archivo));
            }

            return response()->json([
                'message' => 'Documento obtenido exitosamente',
                'datos' => $document
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Documento no encontrado',
                'error' => 'No se encontró un documento con el ID proporcionado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar documentos de un vehículo específico
     */
    public function showByVehicle(String $vehicleId)
    {
        try {
            // Obtener documentos del vehículo específico
            $documents = VehicleDocument::with('vehicle')
                ->where('vehicle_id', $vehicleId)
                ->get();

            // Verificar si se encontraron documentos
            if ($documents->isEmpty()) {
                return response()->json([
                    'message' => 'No se encontraron documentos para este vehículo',
                    'error' => 'No existen documentos asociados al vehículo con ID proporcionado'
                ], 404);
            }

            // Procesar cada documento para generar URLs accesibles
            $documents->transform(function ($doc) {
                if ($doc->archivo) {
                    // Generar URL completa para previsualización en frontend
                    $doc->url_previsualizacion = asset($doc->archivo);

                    // Verificar si el archivo existe físicamente
                    $doc->archivo_existe = file_exists(public_path($doc->archivo));
                }
                return $doc;
            });

            return response()->json([
                'message' => 'Documentos del vehículo obtenidos exitosamente',
                'documentos' => $documents
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los documentos del vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Subir un documento para un vehículo
     * Maneja cualquier tipo de archivo (imágenes, PDF, documentos, etc.)
     */
    public function store(Request $request)
    {
        // Validar datos de entrada
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'tipo' => 'required|in:registro_vehicular,seguro,inspeccion,permiso_circulacion,certificado_emisiones,otros',
            'nombre' => 'required|string|max:255',
            'archivo' => 'required|file|max:10240', // 10MB max, cualquier tipo de archivo
            'fecha_vencimiento' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Obtener información del vehículo
            $vehicle = Vehicle::findOrFail($request->vehicle_id);

            // Manejo del archivo subido
            $archivo = $request->file('archivo');

            // Obtener información del archivo
            $extension = $archivo->getClientOriginalExtension();
            $nombreOriginal = $archivo->getClientOriginalName();
            $mimeType = $archivo->getMimeType();
            $tamano = $archivo->getSize();

            // Generar nombre seguro para el archivo
            $marca = $vehicle->marca ?? 'sin_marca';
            $modelo = $vehicle->modelo ?? 'sin_modelo';
            $nombreLimpio = $this->limpiarNombre($marca . '_' . $modelo);
            $tipoDoc = $request->tipo;
            $nombreArchivo = "{$tipoDoc}_" . time() . ".{$extension}";

            // Crear la carpeta específica del vehículo
            $rutaVehiculo = "vehicle_documents/{$nombreLimpio}";
            $rutaCarpetaCompleta = public_path($rutaVehiculo);

            if (!file_exists($rutaCarpetaCompleta)) {
                mkdir($rutaCarpetaCompleta, 0755, true);
            }

            // Mover el archivo a la carpeta del vehículo
            $archivo->move($rutaCarpetaCompleta, $nombreArchivo);

            // Guardar la ruta relativa completa
            $rutaArchivoCompleta = "{$rutaVehiculo}/{$nombreArchivo}";

            // Crear el registro del documento
            $document = VehicleDocument::create([
                'vehicle_id' => $request->vehicle_id,
                'tipo' => $request->tipo,
                'nombre' => $request->nombre,
                'archivo' => $rutaArchivoCompleta,
                'fecha_vencimiento' => $request->fecha_vencimiento,
                'observaciones' => $request->observaciones,
                'nombre_original' => $nombreOriginal,
                'mime_type' => $mimeType,
                'tamano' => $tamano,
                'aprobado' => $request->input('aprobado', false), // Por defecto false si no viene
            ]);

            DB::commit();

            // Agregar URL para previsualización
            $document->url_previsualizacion = asset($document->archivo);

            return response()->json([
                'message' => 'Documento subido exitosamente',
                'datos' => $document->load('vehicle'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();

            // Eliminar archivo si se subió pero falló la creación del registro
            if (isset($rutaArchivoCompleta) && file_exists(public_path($rutaArchivoCompleta))) {
                unlink(public_path($rutaArchivoCompleta));
            }

            return response()->json([
                'message' => 'Error al subir el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un documento existente
     * Permite actualizar cualquier campo y reemplazar el archivo
     */
    public function update(Request $request, $id)
    {
        // Validar datos de entrada
        $request->validate([
            'tipo' => 'sometimes|in:registro_vehicular,seguro,inspeccion,permiso_circulacion,certificado_emisiones,otros',
            'nombre' => 'sometimes|string|max:255',
            'archivo' => 'nullable|file|max:10240', // Cualquier tipo de archivo
            'fecha_vencimiento' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Buscar el documento a actualizar
            $document = VehicleDocument::with('vehicle')->findOrFail($id);
            $archivoAnterior = $document->archivo;
            $nombreArchivo = $archivoAnterior;

            // Si se sube un nuevo archivo
            if ($request->hasFile('archivo')) {
                $archivo = $request->file('archivo');

                // Obtener información del nuevo archivo
                $extension = $archivo->getClientOriginalExtension();
                $nombreOriginal = $archivo->getClientOriginalName();
                $mimeType = $archivo->getMimeType();
                $tamano = $archivo->getSize();

                // Generar nombre seguro para el nuevo archivo
                $vehicle = $document->vehicle;
                $marca = $vehicle->marca ?? 'sin_marca';
                $modelo = $vehicle->modelo ?? 'sin_modelo';
                $nombreLimpio = $this->limpiarNombre($marca . '_' . $modelo);
                $tipoDoc = $request->get('tipo', $document->tipo);
                $nombreArchivo = "{$tipoDoc}_" . time() . ".{$extension}";

                // Crear carpeta específica del vehículo si no existe
                $rutaVehiculo = "vehicle_documents/{$nombreLimpio}";
                $rutaCarpetaCompleta = public_path($rutaVehiculo);

                if (!file_exists($rutaCarpetaCompleta)) {
                    mkdir($rutaCarpetaCompleta, 0755, true);
                }

                // Mover nuevo archivo
                $archivo->move($rutaCarpetaCompleta, $nombreArchivo);

                // Ruta completa del nuevo archivo
                $nombreArchivo = "{$rutaVehiculo}/{$nombreArchivo}";

                // Eliminar archivo anterior si existe
                if ($archivoAnterior && file_exists(public_path($archivoAnterior))) {
                    unlink(public_path($archivoAnterior));
                }
            }

            // Preparar datos para actualizar
            $datosActualizar = $request->only(['tipo', 'nombre', 'fecha_vencimiento', 'observaciones', 'aprobado']);

            // Si se subió un nuevo archivo, actualizar información del archivo
            if ($request->hasFile('archivo')) {
                $datosActualizar['archivo'] = $nombreArchivo;
                $datosActualizar['nombre_original'] = $nombreOriginal;
                $datosActualizar['mime_type'] = $mimeType;
                $datosActualizar['tamano'] = $tamano;
            }

            // Actualizar el documento
            $document->update($datosActualizar);

            DB::commit();

            // Agregar URL para previsualización
            $document->url_previsualizacion = asset($document->archivo);

            return response()->json([
                'message' => 'Documento actualizado exitosamente',
                'datos' => $document->load('vehicle'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Documento no encontrado',
                'error' => 'No se encontró un documento con el ID proporcionado'
            ], 404);
        } catch (\Exception $e) {
            DB::rollback();

            // Eliminar nuevo archivo si falló la actualización
            if (isset($nombreArchivo) && $nombreArchivo !== $archivoAnterior && file_exists(public_path($nombreArchivo))) {
                unlink(public_path($nombreArchivo));
            }

            return response()->json([
                'message' => 'Error al actualizar el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un documento
     * Elimina el archivo físico y el registro de la base de datos
     */
    public function destroy($id)
    {
        try {
            // Buscar el documento a eliminar
            $document = VehicleDocument::findOrFail($id);

            // Eliminar archivo físico si existe
            if ($document->archivo && file_exists(public_path($document->archivo))) {
                unlink(public_path($document->archivo));

                // Intentar eliminar la carpeta del vehículo si está vacía
                $rutaVehiculo = dirname(public_path($document->archivo));
                if (is_dir($rutaVehiculo) && count(scandir($rutaVehiculo)) == 2) {
                    rmdir($rutaVehiculo);
                }
            }

            // Eliminar registro de la base de datos
            $document->delete();

            return response()->json([
                'message' => 'Documento eliminado exitosamente'
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Documento no encontrado',
                'error' => 'No se encontró un documento con el ID proporcionado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Descargar un documento
     * Permite descargar el archivo con su nombre original
     */
    public function download($id)
    {
        try {
            // Buscar el documento por ID
            $document = VehicleDocument::findOrFail($id);

            // Obtener la ruta completa del archivo
            $filePath = public_path($document->archivo);

            // Verificar si el archivo existe
            if (!File::exists($filePath)) {
                return response()->json([
                    'message' => 'Archivo no encontrado',
                    'error' => 'El archivo no existe en el servidor'
                ], 404);
            }

            // Obtener la extensión del archivo
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            // Mapear extensiones a tipos MIME
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'zip' => 'application/zip',
                'rar' => 'application/x-rar-compressed',
            ];

            // Determinar el tipo MIME
            $mimeType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : File::mimeType($filePath);

            // Obtener el nombre original del archivo para la descarga
            $fileName = $document->nombre_original ?? ($document->nombre . '.' . $extension);

            // Definir headers para la descarga
            $headers = [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];

            // Retornar el archivo como respuesta descargable
            return response()->file($filePath, $headers);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Documento no encontrado',
                'error' => 'No se encontró un documento con el ID proporcionado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al descargar el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método auxiliar para limpiar nombres de archivos y carpetas
     * Convierte a formato seguro para URL: minúsculas, sin espacios ni caracteres especiales
     */
    private function limpiarNombre($nombre)
    {
        // Reemplazar caracteres especiales y acentos
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);

        // Reemplazar cualquier caracter que no sea alfanumérico con guión bajo
        $nombre = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre);

        // Reemplazar múltiples guiones bajos con uno solo
        $nombre = preg_replace('/_+/', '_', $nombre);

        // Eliminar guiones bajos al inicio y final
        $nombre = trim($nombre, '_');

        // Convertir a minúsculas
        return strtolower($nombre);
    }
}
