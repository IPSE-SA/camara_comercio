<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Establecimiento;
use App\Models\DatoTributario;
use App\Models\TipoRegimen;
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\Canton;
use App\Models\Parroquia;
use App\Models\ActividadEconomica;
use App\Models\Camara;

class EstablecimientoController extends Controller
{
    //
    public function maestro_establecimientos()
    {  
        $regimen = TipoRegimen::pluck('nombre', 'id'); 
        $paises = Pais::pluck('nombre', 'id');  
        $provincias = Provincia::where('id_pais', 57)->pluck('nombre', 'id'); // Provincias de Ecuador
        $cantones = Canton::where('id_pais', 57)->where('id_provincia', 2)->pluck('nombre', 'id'); // Provincias de Ecuador
        $parroquias = Parroquia::where('id_pais', 57)->where('id_provincia', 2)->where('id_canton', 2)->pluck('nombre', 'id'); // Provincias de Ecuador
        $actividadesEconomicas = ActividadEconomica::pluck('descripcion', 'id');  
        $camaras = Camara::pluck('razon_social', 'id'); 

        $provinciaDefault = Provincia::find(1); // Obtenemos la provincia con ID = 1
        if ($provinciaDefault) {
            $provincias->put($provinciaDefault->id, $provinciaDefault->nombre); // Añadimos al listado
        }

        $cantonDefault = Canton::find(1); // Obtenemos la provincia con ID = 1
        if ($cantonDefault) {
            $cantones->put($cantonDefault->id, $cantonDefault->nombre); // Añadimos al listado
        }

        $parroquiaDefault = Canton::find(1); // Obtenemos la provincia con ID = 1
        if ($parroquiaDefault) {
            $parroquias->put($parroquiaDefault->id, $parroquiaDefault->nombre); // Añadimos al listado
        }
        
        return view('administrador.maestro_establecimientos', compact('regimen', 'paises', 'provincias', 'cantones', 'parroquias', 'actividadesEconomicas', 'camaras') );
    }

    public function obtener_listado_establecimientos(Request $request)
    {
        $columns = [
            0 => 'establecimientos.id', 
            1 => 'acciones'
        ];

        $query = DB::table('establecimientos')  
            ->select(
                'establecimientos.id',
                'establecimientos.fecha_inicio_actividades',
                'establecimientos.nombre_comercial',
                DB::raw('CONCAT(establecimientos.calle, " ", establecimientos.manzana, " ", establecimientos.numero, " ", establecimientos.interseccion) AS direccion'),
                'establecimientos.correo' 
            )
            ->where('establecimientos.estado', 1)
            ->orderBy('establecimientos.nombre_comercial', 'asc');

        // Filtro de localidad 

        // Búsqueda
        if ($search = $request->input('search.value')) {
            $query->where(function($query) use ($search) {
                $query->where('establecimientos.nombre_comercial', 'LIKE', "%{$search}%") 
                    ->orWhere('establecimientos.calle', 'LIKE', "%{$search}%")
                    ->orWhere('establecimientos.manzana', 'LIKE', "%{$search}%")
                    ->orWhere('establecimientos.numero', 'LIKE', "%{$search}%")
                    ->orWhere('establecimientos.interseccion', 'LIKE', "%{$search}%") 
                    ->orWhere('establecimientos.correo', 'LIKE', "%{$search}%"); 
            });
        }

        // **Filtrar por id_camara si está presente en el request**
        if ($idCamara = $request->input('id_camara')) {
            $query->where('establecimientos.id_camara', $idCamara);
        }

        $totalFiltered = $query->count();

        // Orden
        $orderColumnIndex = $request->input('order.0.column', 0); // Por defecto, columna 0
        $orderDir = $request->input('order.0.dir', 'asc'); // Por defecto, orden ascendente

        if (isset($columns[$orderColumnIndex])) {
            $orderColumn = $columns[$orderColumnIndex];
            $query->orderBy($orderColumn, $orderDir);
        }

        // Paginación
        $start = $request->input('start') ?? 0;
        $length = $request->input('length') ?? 10;
        $query->skip($start)->take($length);

        $establecimientos = $query->get();

        $data = $establecimientos->map(function ($establecimiento) {
            $boton = "";  
            
             
            return [
                'fecha_inicio_actividades' => $establecimiento->fecha_inicio_actividades, 
                'nombre_comercial' => $establecimiento->nombre_comercial, 
                'direccion' => $establecimiento->direccion, 
                'correo' => $establecimiento->correo,  
                'btn' => '<button class="btn btn-primary mb-3 open-modal" data-id="' . $establecimiento->id . '">Modificar</button>' .
                '&nbsp;&nbsp;&nbsp;<button class="btn btn-warning mb-3 delete-establecimiento" data-id="' . $establecimiento->id . '">Eliminar</button>'.
                '&nbsp;&nbsp;&nbsp;' 
            ];
        });

        $json_data = [
            "draw" => intval($request->input('draw')),
            "recordsTotal" => DB::table('establecimientos')->count(),
            "recordsFiltered" => $totalFiltered,
            "data" => $data
        ];
        
        return response()->json($json_data);
    }

    public function registrar_establecimiento(Request $request)
    { 

        try {
            // Convertir fecha_ingreso al formato MySQL (YYYY-MM-DD)
            $fecha_inicio_actividades = \Carbon\Carbon::createFromFormat('d/m/Y', $request->input('fecha_inicio_actividades'))->format('Y-m-d');
            //$actividadesEconomicasSeleccionadas = $request->input('actividad_economica_seleccionados', []);
            $actividadesEconomicasSeleccionadas = $request->input('actividad_economica_seleccionados', ''); 
            // Convertir la cadena en un array (si no está vacío)
            $actividadesEconomicasSeleccionadasArray = $actividadesEconomicasSeleccionadas ? explode(',', $actividadesEconomicasSeleccionadas) : [];

              
            // Crear registro en la base de datos
            $establecimiento = Establecimiento::create([ 
                'nombre_comercial' => strtoupper($request->input('nombre_comercial')), 
                'id_camara' => strtoupper($request->input('camaraHidden')),
                'id_pais' => $request->input('pais'),
                'id_provincia' => $request->input('provincia'),
                'id_canton' => $request->input('canton'),
                'id_parroquia' => $request->input('parroquia'),
                'calle' => strtoupper($request->input('calle')),
                'manzana' => strtoupper($request->input('manzana')),
                'numero' => strtoupper($request->input('numero')),
                'interseccion' => strtoupper($request->input('interseccion')),
                'referencia' => strtoupper($request->input('referencia')),
                'correo' => strtoupper($request->input('correo')),
                'telefono1' => strtoupper($request->input('telefono1')),
                'telefono2' => strtoupper($request->input('telefono2')),
                'telefono3' => strtoupper($request->input('telefono3')),
                'fecha_inicio_actividades' => $fecha_inicio_actividades, // Usar fecha convertida
                'actividades_economicas' =>  json_encode($actividadesEconomicasSeleccionadasArray),
                'estado' => 1
            ]); 

            return response()->json(['success' => 'Establecimiento registrado correctamente'], 200);
        } catch (\Illuminate\Database\QueryException $e) { 
            return response()->json(['error' => 'Error al registrar el establecimiento: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al registrar el establecimiento: ' . $e->getMessage()], 500);
        }
    }

    public function eliminar_establecimiento($id)
    {
        //$colaborador = Colaborador::find($id);
        $establecimiento = Establecimiento::where('id', $id)->first();


        if (!$establecimiento) {
            return response()->json(['error' => 'Establecimiento no encontrado'], 404);
        }
    
        // Cambiar el valor del campo 'activo' a 0
        $establecimiento->estado = 0;
        $establecimiento->save();
    
        return response()->json(['success' => 'Establecimiento eliminado correctamente']);
    }
}
