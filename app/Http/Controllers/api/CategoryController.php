<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Imports\CategoriesImport;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class CategoryController extends Controller
{
    protected $excel;

    public function __construct(Excel $excel)
    {
        $this->excel = $excel;
    }
    /**
     * Listar todas las categorías
     */
    public function index()
    {
        $categories = Category::select('id', 'nombre', 'descripcion', 'estado', 'codigo', 'clasificacion', 'subcategoria', 'agrupacion')  // Eliminado caja_operativa_id
            ->get();
        return response()->json([
            'mensaje' => 'Categorías cargadas correctamente',
            'data' => $categories
        ]);
    }

    /**
     * Listar solo categorías activas
     */
    public function activas()
    {
        $categories = Category::where('estado', true)
            ->select('id', 'nombre', 'descripcion', 'estado', 'codigo', 'clasificacion', 'subcategoria', 'agrupacion')
            ->get();

        return response()->json([
            'mensaje' => 'Categorías activas cargadas',
            'data' => $categories
        ]);
    }

    /**
     * Mostrar una categoría
     */
    public function show($id)
    {
        $category = Category::find($id);  // Eliminado with('cajaOperativa')

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        return response()->json([
            'mensaje' => 'Categoría encontrada.',
            'data' => $category
        ]);
    }

    /**
     * Crear una categoría
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255|unique:categories,nombre',
            'descripcion' => 'nullable|string|max:1000',
            'codigo' => 'required|string|max:255',
            'clasificacion' => 'required|string|max:255',
            'subcategoria' => 'required|string|max:255',
            'agrupacion' => 'required|string|max:255',
        ]);

        $category = Category::create([
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            'codigo' => $request->codigo,
            'clasificacion' => $request->clasificacion,
            'subcategoria' => $request->subcategoria,
            'agrupacion' => $request->agrupacion,
            'estado' => true,
        ]);

        return response()->json([
            'mensaje' => 'Categoría creada exitosamente.',
            'data' => $category
        ], 201);
    }

    /**
     * Actualizar una categoría
     */
    public function update(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        $request->validate([
            'nombre' => 'required|string|max:255|unique:categories,nombre,' . $id,
            'codigo' => 'required|string|max:255',
            'clasificacion' => 'required|string|max:255',
            'subcategoria' => 'required|string|max:255',
            'agrupacion' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:1000',
        ]);

        $category->update([
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            // Eliminado: 'caja_operativa_id' => $request->caja_operativa_id,
        ]);

        return response()->json([
            'mensaje' => 'Categoría actualizada exitosamente.',
            'data' => $category
        ]);
    }

    /**
     * Desactivar una categoría
     */
    public function destroy($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        $category->update(['estado' => false]);

        return response()->json([
            'mensaje' => 'Categoría desactivada exitosamente.'
        ]);
    }

    /**
     * Reactivar una categoría
     */
    public function activate($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        $category->update(['estado' => true]);

        return response()->json([
            'mensaje' => 'Categoría reactivada exitosamente.'
        ]);
    }

    public function categoryActives()
    {
        $categories = Category::where('estado', true)
            ->select('id', 'nombre', 'descripcion', 'estado', 'codigo', 'clasificacion', 'subcategoria', 'agrupacion')
            ->orderByRaw('LOWER(nombre) asc')
            ->get();

        return response()->json([
            "mensaje" => "Categorias Cargadas",
            "datos" => $categories
        ]);
    }


    /**
     * Importar categorías desde Excel
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        try {
            Excel::import(new CategoriesImport(), $request->file('file'));

            return response()->json([
                'mensaje' => 'Categorías importadas correctamente.',
            ]);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            // Errores de validación
            $failures = $e->failures();

            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                    'values' => $failure->values()
                ];
            }

            Log::error('Errores de validación al importar categorías', ['errors' => $errors]);

            return response()->json([
                'mensaje' => 'Error de validación en el archivo.',
                'errores' => $errors
            ], 422);
        } catch (\Exception $e) {
            // Otros errores
            Log::error('Error al importar categorías: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return response()->json([
                'mensaje' => 'Error al importar las categorías: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Descargar plantilla para importar categorías
     */
    public function descargarPlantilla()
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Categorías');

            // Encabezados en el orden correcto: Codigo, Nombre(Español-Ingles), Clasificacion, Subcategoria, Agrupacion, Descripcion
            $headers = ['codigo', 'nombre', 'clasificacion', 'subcategoria', 'agrupacion', 'descripcion'];
            $sheet->fromArray($headers, null, 'A1');

            // Estilo para encabezados
            $headerStyle = $sheet->getStyle('A1:F1');
            $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
            $headerStyle->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('4472C4');
            $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Datos de ejemplo con nombre en formato Español-English
            $ejemplos = [
                ['ELEC-001', 'Electrónica-Electronics', 'Productos', 'Tecnología', 'Grupo A', 'Productos electrónicos y tecnología'],
                ['ROPA-001', 'Ropa-Clothing', 'Productos', 'Vestimenta', 'Grupo B', 'Ropa y accesorios de moda'],
                ['ALIM-001', 'Alimentos-Food', 'Consumibles', 'Comestibles', 'Grupo C', 'Productos alimenticios y bebidas'],
            ];

            $sheet->fromArray($ejemplos, null, 'A2');

            // Aplicar bordes a toda la tabla
            $totalRows = count($ejemplos) + 1;
            $sheet->getStyle("A1:F{$totalRows}")->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            // Autoajustar columnas con ancho mínimo
            foreach (range('A', 'F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Asegurar un ancho mínimo para cada columna
            $sheet->getColumnDimension('A')->setWidth(max(12, $sheet->getColumnDimension('A')->getWidth()));  // codigo
            $sheet->getColumnDimension('B')->setWidth(max(25, $sheet->getColumnDimension('B')->getWidth()));  // nombre (español-inglés)
            $sheet->getColumnDimension('C')->setWidth(max(15, $sheet->getColumnDimension('C')->getWidth()));  // clasificacion
            $sheet->getColumnDimension('D')->setWidth(max(15, $sheet->getColumnDimension('D')->getWidth()));  // subcategoria
            $sheet->getColumnDimension('E')->setWidth(max(12, $sheet->getColumnDimension('E')->getWidth()));  // agrupacion
            $sheet->getColumnDimension('F')->setWidth(max(35, $sheet->getColumnDimension('F')->getWidth()));  // descripcion

            // Centrar el contenido de las celdas (excepto descripción)
            $sheet->getStyle('A2:E' . $totalRows)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Ajustar texto en descripción
            $sheet->getStyle('F2:F' . $totalRows)->getAlignment()
                ->setWrapText(true);

            // Guardar y descargar
            $writer = new Xlsx($spreadsheet);
            $fileName = 'plantilla_categorias.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), $fileName);
            $writer->save($tempFile);

            return response()->download($tempFile, $fileName)
                ->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al generar plantilla: ' . $e->getMessage()], 500);
        }
    }
}
