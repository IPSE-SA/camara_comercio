<?php

namespace App\Http\Controllers;

use App\Models\TipoRegimen;
use App\Models\Pais;
use App\Models\Provincia;
use App\Models\Canton;
use App\Models\DatoTributarioSocio;
use App\Models\Parroquia;
use App\Models\Socio;
use App\Models\TipoPersoneria;
use App\Models\SolicitudSocio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Models\LogActivity;
 
use Illuminate\Support\Str; 

class SocioController extends Controller
{
    public function __construct()
    {
        // Elimina o ajusta cualquier middleware aquí si es necesario 
        $this->middleware('auth')->except(['get_socios', 'formulario_ingreso', 'estado_solicitud']); 
    }
    //
    public function maestro_socios()
    {
        $regimenes = TipoRegimen::pluck('nombre', 'id');
        $paises = Pais::orderBy('nombre', 'asc')->pluck('nombre', 'id');
        $provincias = Provincia::where('id_pais', 57)->orderBy('nombre', 'asc')->pluck('nombre', 'id'); // Provincias de Ecuador
        $cantones = Canton::where('id_pais', 57)->where('id_provincia', 2)->orderBy('nombre', 'asc')->pluck('nombre', 'id'); // Provincias de Ecuador
        $parroquias = Parroquia::where('id_pais', 57)->where('id_provincia', 2)->where('id_canton', 2)->orderBy('nombre', 'asc')->pluck('nombre', 'id'); // Provincias de Ecuador

        $tiposPersoneria = TipoPersoneria::pluck('descripcion', 'id'); // Obtenemos los tipos de personeria

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


        return view('administrador.socios.maestro_socios', compact('regimenes', 'paises', 'provincias', 'cantones', 'parroquias', 'tiposPersoneria'));
    }

    public function obtener_listado_socios(Request $request)
    {
        try {
            $columns = [
                0 => 'socios.id',
                1 => 'razon_social',
            ];

            // Construcción de la consulta inicial
            $query = Socio::with([
                'tipo_identificacion',
                'tipo_persona',
                'tipo_personeria',
                'datos_tributarios.provincia',
                'datos_tributarios.pais',
                'datos_tributarios.canton',
                'datos_tributarios.parroquia',
            ])->where('estado', 1);
            // Búsqueda
            if ($search = $request->input('search.value')) {
                $query->where(function ($q) use ($search) {
                    $q->where('razon_social', 'LIKE', "%{$search}%")
                        ->orWhere('tipo_persona.descripcion', 'LIKE', "%{$search}%")
                        ->orWhere('tipo_identificacion.descripcion', 'LIKE', "%{$search}%");
                });
            }

            // Conteo para recordsFiltered
            $totalFiltered = $query->count();

            // Orden
            $orderColumnIndex = $request->input('order.0.column', 0); // Por defecto, columna 0
            $orderDir = $request->input('order.0.dir', 'asc'); // Por defecto, orden ascendente

            if (isset($columns[$orderColumnIndex])) {
                $orderColumn = $columns[$orderColumnIndex];
                $query->orderBy($orderColumn, $orderDir);
            }

            // Paginación
            $start = $request->input('start', 0);
            $length = $request->input('length', 10);
            $query->skip($start)->take($length);

            // Obtener los datos
            $socios = $query->get();
            // Mapear los datos para la respuesta
            $data = $socios->map(function ($socio) {
                $logSociosIns = LogActivity::with('user')->where('record_id', $socio->id)->where('table_name', 'socios')->where('action', 'insert')->get();
                $logSociosMod = LogActivity::with('user')
                    ->where(function ($query) use ($socio) {
                        $query->where('record_id', $socio->id)
                            ->where('table_name', 'socios')
                            ->orWhere(function ($query) use ($socio) {
                                $query->where('table_name', 'datos_tributarios_socio');
                            });
                    })
                    ->where('action', 'update')
                    ->get();
                $logSociosIns = $logSociosIns->map(function ($log) {
                    return [
                        'created_at' => $log->created_at,
                        'user_id' => $log->user_id,
                        'user' => [
                            'name' => $log->user->name,
                            'email' => $log->user->email,
                            'username' => $log->user->username
                        ]
                    ];
                });

                $logSociosMod = $logSociosMod->map(function ($log) {
                    return [
                        'created_at' => $log->created_at,
                        'user_id' => $log->user_id,
                        'user' => [
                            'name' => $log->user->name,
                            'email' => $log->user->email,
                            'username' => $log->user->username
                        ]
                    ];
                });

                $logSocios = [
                    'insert' => $logSociosIns[0] ?? null,
                    'update' => $logSociosMod ?? null
                ];

                // Convertir el modelo Camara a un array
                $tipo_persona = $socio->tipo_persona->descripcion ?? '';
                $tipo_personeria = $socio->tipo_personeria->descripcion ?? '';
                $socio_arr = $socio->toArray();
                return array_merge($socio_arr, [
                    'logs' => $logSocios,
                    'fecha_ingreso' => Carbon::parse($socio->fecha_ingreso)->format('d/m/Y'),
                    'fecha_registro_mercantil' => Carbon::parse($socio->fecha_registro_mercantil)->format('d/m/Y'),
                    'fecha_vencimiento_nombramiento' => Carbon::parse($socio->fecha_vencimiento_nombramiento)->format('d/m/Y'),
                    'tipo_regimen' => $socio->datos_tributarios->tipo_regimen->id ?? '',
                    'fecha_registro_sri' => Carbon::parse($socio->datos_tributarios->fecha_registro_sri ?? '')->format('d/m/Y') ?? '',
                    'fecha_actualizacion_ruc' => Carbon::parse($socio->datos_tributarios->fecha_actualizacion_ruc ?? '')->format('d/m/Y') ?? '',
                    'fecha_constitucion' => Carbon::parse($socio->datos_tributarios->fecha_constitucion ?? '')->format('d/m/Y') ?? '',
                    'agente_retencion' => $socio->datos_tributarios->agente_retencion ?? '',
                    'contribuyente_especial' => $socio->datos_tributarios->contribuyente_especial ?? '',
                    'fecha_nacimiento' => Carbon::parse($socio->datos_tributarios->fecha_nacimiento ?? '')->format('d/m/Y') ?? '',
                    'id_provincia' => $socio->datos_tributarios->provincia->id ?? '',
                    'id_canton' => $socio->datos_tributarios->canton->id ?? '',
                    'id_parroquia' => $socio->datos_tributarios->parroquia->id ?? '',
                    'id_pais' => $socio->datos_tributarios->pais->id ?? '',
                    'calle' => $socio->datos_tributarios->calle ?? '',
                    'manzana' => $socio->datos_tributarios->manzana ?? '',
                    'numero' => $socio->datos_tributarios->numero ?? '',
                    'interseccion' => $socio->datos_tributarios->interseccion ?? '',
                    'referencia' => $socio->datos_tributarios->referencia ?? '',
                    'razon_social' => $socio->razon_social,
                    'tipo_personeria' => $tipo_personeria,
                    'identificacion' => $socio->identificacion,
                    'estado_sri' => $socio->datos_tributarios->estado_sri ?? '',
                    'btn' => '<div class="d-flex justify-content-center align-items-center flex-wrap gap-2"><button class="btn btn-primary mb-1 edit-modal flex-grow-1 flex-shrink-1" style="min-width: 100px;" data-id="' . $socio->id . '">Modificar</button>' .
                        '<button class="btn btn-warning mb-1 delete-socio flex-grow-1 flex-shrink-1" style="min-width: 100px;" data-id="' . $socio->id . '">Eliminar</button></div>'
                ]);
            });

            // Preparar la respuesta
            $response = [
                "draw" => intval($request->input('draw')),
                "recordsTotal" => Socio::where('estado', 1)->count(),
                "recordsFiltered" => $totalFiltered,
                "data" => $data,
            ];

            return response()->json($response);
        } catch (\Throwable $th) {
            Log::error($th);

            return response()->json(
                [
                    'error' => $th->getMessage(),
                    'message' => 'Error al obtener los socios',
                ],
                500,
            );
        }
    }

    /*public function obtener_listado_socios(Request $request)
    {
        try {
            $columns = [
                0 => 'socios.id',
                1 => 'razon_social',
            ];

            // Construcción de la consulta inicial
            $query = Socio::with([
                'tipo_identificacion',
                'tipo_persona',
                'tipo_personeria',
                'datos_tributarios.provincia',
                'datos_tributarios.pais',
                'datos_tributarios.canton',
                'datos_tributarios.parroquia',
            ])->where('estado', 1);

            // Búsqueda
            if ($search = $request->input('search.value')) {
                $query->where(function ($q) use ($search) {
                    $q->where('razon_social', 'LIKE', "%{$search}%")
                        ->orWhere('tipo_persona.descripcion', 'LIKE', "%{$search}%")
                        ->orWhere('tipo_identificacion.descripcion', 'LIKE', "%{$search}%");
                });
            }

            // Conteo para recordsFiltered
            $totalFiltered = $query->count();

            // Orden
            $orderColumnIndex = $request->input('order.0.column', 0); // Por defecto, columna 0
            $orderDir = $request->input('order.0.dir', 'asc'); // Por defecto, orden ascendente

            if (isset($columns[$orderColumnIndex])) {
                $orderColumn = $columns[$orderColumnIndex];
                $query->orderBy($orderColumn, $orderDir);
            }

            // Paginación
            $start = $request->input('start', 0);
            $length = $request->input('length', 10);
            $query->skip($start)->take($length);

            // Obtener los datos
            $socios = $query->get();

            // Mapear los datos para la respuesta
            $data = $socios->map(function ($socio) {
                // Determinar si los datos tributarios deben ser nulos
                $datosTributarios = null;

                if ($socio->id_tipo_personeria !== 1 && $socio->datos_tributarios) {
                    $datosTributarios = [
                        'tipo_regimen' => $socio->datos_tributarios->tipo_regimen->id ?? null,
                        'fecha_registro_sri' => optional($socio->datos_tributarios->fecha_registro_sri)->format('d/m/Y'),
                        'fecha_actualizacion_ruc' => optional($socio->datos_tributarios->fecha_actualizacion_ruc)->format('d/m/Y'),
                        'fecha_constitucion' => optional($socio->datos_tributarios->fecha_constitucion)->format('d/m/Y'),
                        'agente_retencion' => $socio->datos_tributarios->agente_retencion ?? false,
                        'contribuyente_especial' => $socio->datos_tributarios->contribuyente_especial ?? false,
                        'id_provincia' => $socio->datos_tributarios->provincia->id ?? null,
                        'id_canton' => $socio->datos_tributarios->canton->id ?? null,
                        'id_parroquia' => $socio->datos_tributarios->parroquia->id ?? null,
                        'id_pais' => $socio->datos_tributarios->pais->id ?? null,
                        'calle' => $socio->datos_tributarios->calle ?? '',
                        'manzana' => $socio->datos_tributarios->manzana ?? '',
                        'numero' => $socio->datos_tributarios->numero ?? '',
                        'interseccion' => $socio->datos_tributarios->interseccion ?? '',
                        'referencia' => $socio->datos_tributarios->referencia ?? '',
                    ];
                }

                // Convertir los logs de actividades
                $logSociosIns = LogActivity::with('user')->where('record_id', $socio->id)->where('table_name', 'socios')->where('action', 'insert')->get();
                $logSociosMod = LogActivity::with('user')
                    ->where(function ($query) use ($socio) {
                        $query->where('record_id', $socio->id)
                            ->where('table_name', 'socios')
                            ->orWhere(function ($query) use ($socio) {
                                $query->where('table_name', 'datos_tributarios_socio');
                            });
                    })
                    ->where('action', 'update')
                    ->get();

                $logSociosIns = $logSociosIns->map(function ($log) {
                    return [
                        'created_at' => $log->created_at,
                        'user_id' => $log->user_id,
                        'user' => [
                            'name' => $log->user->name,
                            'email' => $log->user->email,
                            'username' => $log->user->username
                        ]
                    ];
                });

                $logSociosMod = $logSociosMod->map(function ($log) {
                    return [
                        'created_at' => $log->created_at,
                        'user_id' => $log->user_id,
                        'user' => [
                            'name' => $log->user->name,
                            'email' => $log->user->email,
                            'username' => $log->user->username
                        ]
                    ];
                });

                $logSocios = [
                    'insert' => $logSociosIns[0] ?? null,
                    'update' => $logSociosMod ?? null
                ];

                // Respuesta final
                return array_merge($socio->toArray(), [
                    'logs' => $logSocios,
                    'fecha_ingreso' => Carbon::parse($socio->fecha_ingreso)->format('d/m/Y'),
                    'datos_tributarios' => $datosTributarios,
                    'tipo_personeria' => $socio->tipo_personeria->descripcion ?? '', // Corregido
                    'btn' => '<div class="d-flex justify-content-center align-items-center flex-wrap gap-2"><button class="btn btn-primary mb-1 edit-modal flex-grow-1 flex-shrink-1" style="min-width: 100px;" data-id="' . $socio->id . '">Modificar</button>' .
                        '<button class="btn btn-warning mb-1 delete-socio flex-grow-1 flex-shrink-1" style="min-width: 100px;" data-id="' . $socio->id . '">Eliminar</button></div>'
                ]);
            });

            // Preparar la respuesta
            $response = [
                "draw" => intval($request->input('draw')),
                "recordsTotal" => Socio::where('estado', 1)->count(),
                "recordsFiltered" => $totalFiltered,
                "data" => $data,
            ];

            return response()->json($response);
        } catch (\Throwable $th) {
            Log::error($th);

            return response()->json(
                [
                    'error' => $th->getMessage(),
                    'message' => 'Error al obtener los socios',
                ],
                500,
            );
        }
    }*/

    public function registrar_socio(Request $request)
    {
        $storedFilePath = null;
        //return ("mensaje");
        try { 
            //return($request->input('tipo_personeria'));
            if($request->input('tipo_personeria') != 1){ 

                $validator = Validator::make($request->all(), [
                    'fecha_ingreso' => 'required|date_format:d/m/Y',
                    'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                    'tipo_personeria' => 'required|integer',
                    'identificacion' => 'required|string',
                    'razon_social' => 'required|string|max:255',
                    'cedula_representante' => 'required_if:tipo_personeria,3|nullable|string|max:13',
                    'nombre_representante' => 'required_if:tipo_personeria,3|nullable|string|max:255',
                    'apellido_representante' => 'required_if:tipo_personeria,3|nullable|string|max:255',
                    'telefono_representante' => 'required_if:tipo_personeria,3|nullable|string|max:15',
                    'correo_representante' => 'required_if:tipo_personeria,3|nullable|email|max:255',
                    'fecha_registro_mercantil' => 'required_if:tipo_personeria,3|nullable|date_format:d/m/Y',
                    'vencimiento_nombramiento' => 'required_if:tipo_personeria,3|nullable|date_format:d/m/Y',
                    'correo' => 'required_if:tipo_personeria,2|nullable|email|max:255',
                    'telefono' => 'required_if:tipo_personeria,2|nullable|string|max:15',
                    'tipo_regimen' => 'required|integer',
                    'fecha_registro_sri' => 'required|date_format:d/m/Y',
                    'fecha_actualizacion_ruc' => 'required|date_format:d/m/Y',
                    'fecha_constitucion' => 'required|date_format:d/m/Y',
                    'agente_retencion' => 'required|integer',
                    'contribuyente_especial' => 'required|integer',
                    'fecha_nacimiento' => 'required_if:tipo_personeria,2|nullable|date_format:d/m/Y',
                    'pais' => 'required|integer',
                    'provincia' => 'required|integer',
                    'canton' => 'required|integer',
                    'parroquia' => 'required|integer',
                    'calle' => 'required|string|max:255',
                    'manzana' => 'required|string|max:255',
                    'numero' => 'required|string|max:255',
                    'interseccion' => 'required|string|max:255',
                    'referencia' => 'required|string|max:255',
                ]);

            }else{

                $validator = Validator::make($request->all(), [
                    'fecha_ingreso' => 'required|date_format:d/m/Y',
                    'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                    'tipo_personeria' => 'required|integer',
                    'identificacion_sin_ruc' => 'required|string',
                    'razon_social_nombre' => 'required|string|max:255' 
                ]);
            }
            
            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first()], 422);
            }
            


            $data = $validator->validated();


            DB::beginTransaction();
            
            $identi = "";
            $razon_social = "";
            if($data['tipo_personeria'] != 1){
                $identi = $data['identificacion'];
                $razon_social = $data['razon_social'];
            }else{
                $identi = $data['identificacion_sin_ruc'];
                $razon_social = $data['razon_social_nombre'];
            }
            $socioExiste = Socio::where('identificacion', $identi)->orWhere('razon_social', $razon_social)->first();
            if ($socioExiste) {
                return response()->json(['message' => 'El socio con estos datos ya existe en el sistema.'], 422);
            }
            $fecha_ingreso = Carbon::createFromFormat('d/m/Y', $data['fecha_ingreso'])->format('Y-m-d');
            $rutaFoto = '';

            /*
                if ($request->hasFile('foto')) {
                    $archivoFoto = $request->file('foto');
                    $nombreArchivo = $data['identificacion'] . '.' . $archivoFoto->getClientOriginalExtension();

                    $storedFilePath = "fotos_socios/" . $data['identificacion'] . "/" . $nombreArchivo;

                    $rutaFoto = $storedFilePath;
                    $archivoFoto->storeAs("fotos_socios/" . $data['identificacion'], $nombreArchivo, 'public');
                }
            */
            if ($request->hasFile('foto')) {
                $archivoFoto = $request->file('foto');
                $nombreArchivo = $identi . '.' . $archivoFoto->getClientOriginalExtension();

                $storedFilePath = "fotos_socios/" . $identi . "/" . $nombreArchivo;

                $rutaFoto = $storedFilePath;
                $archivoFoto->storeAs("fotos_socios/" . $identi, $nombreArchivo, 'public');
            }

            $numero_consecutivo = Socio::count() + 1;
            if (!isset($data['fecha_registro_mercantil'])) {
                $data['fecha_registro_mercantil'] = null;
            } else {
                $data['fecha_registro_mercantil'] = Carbon::createFromFormat('d/m/Y', $data['fecha_registro_mercantil'])->format('Y-m-d');
            }

            if (!isset($data['vencimiento_nombramiento'])) {
                $data['vencimiento_nombramiento'] = null;
            } else {
                $data['vencimiento_nombramiento'] = Carbon::createFromFormat('d/m/Y', $data['vencimiento_nombramiento'])->format('Y-m-d');
            }

            if ($data['tipo_personeria'] == 2) {
                $data['cedula_representante'] = null;
                $data['nombre_representante'] = null;
                $data['apellido_representante'] = null;
                $data['telefono_representante'] = null;
                $data['correo_representante'] = null;
                $data['fecha_registro_mercantil'] = null;
                $data['vencimiento_nombramiento'] = null;
                $data['fecha_nacimiento'] = Carbon::createFromFormat('d/m/Y', $data['fecha_nacimiento'])->format('Y-m-d');
            } else if ($data['tipo_personeria'] == 3) {
                $data['fecha_nacimiento'] = null;
            }

            

            if($data['tipo_personeria'] != 1){

                // Función para validar formato de fecha
                function validarFecha($fecha, $formato = 'd/m/Y') {
                    return $fecha && Carbon::hasFormat($fecha, $formato);
                }

                // Creación del socio
                $socio = Socio::create([
                    'logo' => $rutaFoto,
                    'numero_consecutivo' => $numero_consecutivo,
                    'fecha_ingreso' => $fecha_ingreso, 
                    'id_tipo_persona' => 1,
                    'id_tipo_personeria' => $data['tipo_personeria'],
                    'id_tipo_identificacion' => 1,
                    'identificacion' => $data['identificacion'],
                    'razon_social' => $data['razon_social'] ?? null,
                    'correo' => $data['correo'] ?? null,
                    'telefono' => $data['telefono'] ?? null,
                    'cedula_representante_legal' => $data['cedula_representante'] ?? null,
                    'nombres_representante_legal' => $data['nombre_representante'] ?? null,
                    'apellidos_representante_legal' => $data['apellido_representante'] ?? null,
                    'telefono_representante_legal' => $data['telefono_representante'] ?? null,
                    'correo_representante_legal' => $data['correo_representante'] ?? null,
                    'fecha_registro_mercantil' => validarFecha($data['fecha_registro_mercantil']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['fecha_registro_mercantil'])->format('Y-m-d') 
                        : null,
                    'fecha_vencimiento_nombramiento' => validarFecha($data['vencimiento_nombramiento']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['vencimiento_nombramiento'])->format('Y-m-d') 
                        : null,
                    'fecha_desafiliacion' => null,
                    'motivo_desafiliacion' => null,
                    'estado' => 1,
                ]);

                if (!$socio) {
                    throw new \Exception("Error al crear el socio");
                }

                // Creación de datos tributarios
                $datos_tributarios = DatoTributarioSocio::create([
                    'id_socio' => $socio->id,
                    'estado_sri' => 1,
                    'id_tipo_regimen' => $data['tipo_regimen'],
                    'fecha_registro_sri' => validarFecha($data['fecha_registro_sri']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['fecha_registro_sri'])->format('Y-m-d') 
                        : null,
                    'fecha_actualizacion_ruc' => validarFecha($data['fecha_actualizacion_ruc']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['fecha_actualizacion_ruc'])->format('Y-m-d') 
                        : null,
                    'fecha_constitucion' => validarFecha($data['fecha_constitucion']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['fecha_constitucion'])->format('Y-m-d') 
                        : null,
                    'fecha_nacimiento' => validarFecha($data['fecha_nacimiento']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['fecha_nacimiento'])->format('Y-m-d') 
                        : null,
                    'agente_retencion' => $data['agente_retencion'],
                    'contribuyente_especial' => $data['contribuyente_especial'],
                    'id_pais' => $data['pais'],
                    'id_provincia' => $data['provincia'],
                    'id_canton' => $data['canton'],
                    'id_parroquia' => $data['parroquia'],
                    'calle' => $data['calle'],
                    'manzana' => $data['manzana'],
                    'numero' => $data['numero'],
                    'interseccion' => $data['interseccion'] ?? null,
                    'referencia' => $data['referencia'] ?? null,
                ]);


            }else{

                $socio = Socio::create([
                    'logo' => $rutaFoto,
                    'numero_consecutivo' => $numero_consecutivo,
                    'fecha_ingreso' => $fecha_ingreso, 
                    'id_tipo_persona' => 1,
                    'id_tipo_personeria' => $data['tipo_personeria'],
                    'id_tipo_identificacion' => 1,
                    'identificacion' => $data['identificacion_sin_ruc'],
                    'razon_social' => $data['razon_social_nombre'] ?? null,
                    'correo' => $data['correo'] ?? null,
                    'telefono' => $data['telefono'] ?? null,
                    'cedula_representante_legal' => $data['cedula_representante'] ?? null,
                    'nombres_representante_legal' => $data['nombre_representante'] ?? null,
                    'apellidos_representante_legal' => $data['apellido_representante'] ?? null,
                    'telefono_representante_legal' => $data['telefono_representante'] ?? null,
                    'correo_representante_legal' => $data['correo_representante'] ?? null,
                    'fecha_registro_mercantil' => $data['fecha_registro_mercantil'] ?? null,
                    'fecha_vencimiento_nombramiento' => $data['vencimiento_nombramiento'] ?? null,
                    'fecha_desafiliacion' => null,
                    'motivo_desafiliacion' => null,
                    'estado' => 1,
                ]);

            }
            

            DB::commit();
            return response()->json([
                'message' => 'Socio registrado correctamente',
            ], 200);
        } catch (\Throwable $th) {
            Log::error($th);
            DB::rollBack();
            if ($storedFilePath && Storage::disk('public')->exists($storedFilePath)) {
                Storage::disk('public')->delete($storedFilePath);
            }
            return response()->json(
                [
                    'error' => $th->getMessage(),
                    'message' => 'Error al registrar el socio',
                ],
                500,
            );
        }
    }

    public function modificar_socio(Request $request)
    {
        $storedFilePath = null;
        $contents = null; // Inicialización de la variable $contents
        //return("Tipo Personeria: ".$request->input('tipo_personeria'));
        try {
            if($request->input('tipo_personeria') != 1){ 

                $validator = Validator::make($request->all(), [
                    'fecha_ingreso' => 'required|date_format:d/m/Y',
                    'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                    'tipo_personeria' => 'required|integer',
                    'identificacion' => 'required|string',
                    'razon_social' => 'required|string|max:255',
                    'cedula_representante' => 'required_if:tipo_personeria,3|string|max:13',
                    'nombre_representante' => 'required_if:tipo_personeria,3|string|max:255',
                    'apellido_representante' => 'required_if:tipo_personeria,3|string|max:255',
                    'telefono_representante' => 'required_if:tipo_personeria,3|string|max:15',
                    'correo_representante' => 'required_if:tipo_personeria,3|email|max:255',
                    'fecha_registro_mercantil' => 'required_if:tipo_personeria,3|date_format:d/m/Y',
                    'vencimiento_nombramiento' => 'required_if:tipo_personeria,3|date_format:d/m/Y',
                    'correo' => 'required_if:tipo_personeria,2|nullable|email|max:255',
                    'telefono' => 'required_if:tipo_personeria,2|nullable|string|max:15',
                    'tipo_regimen' => 'required|integer',
                    'fecha_registro_sri' => 'required|date_format:d/m/Y',
                    'fecha_actualizacion_ruc' => 'required|date_format:d/m/Y',
                    'fecha_constitucion' => 'required|date_format:d/m/Y',
                    'agente_retencion' => 'required|integer',
                    'contribuyente_especial' => 'required|integer',
                    'fecha_nacimiento' => 'required_if:tipo_personeria,2|nullable|date_format:d/m/Y',
                    'pais' => 'required|integer',
                    'provincia' => 'required|integer',
                    'canton' => 'required|integer',
                    'parroquia' => 'required|integer',
                    'calle' => 'required|string|max:255',
                    'manzana' => 'required|string|max:255',
                    'numero' => 'required|string|max:255',
                    'interseccion' => 'required|string|max:255',
                    'referencia' => 'required|string|max:255',
                ]);

            }else{

                $validator = Validator::make($request->all(), [
                    'fecha_ingreso' => 'required|date_format:d/m/Y',
                    'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                    'tipo_personeria' => 'required|integer',
                    'identificacion_sin_ruc' => 'required|string',
                    'razon_social_nombre' => 'required|string|max:255' 
                ]);
            }
            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first()], 422);
            }
            $data = $validator->validated();
            DB::beginTransaction();
            $socioId = $request->socio_id;
            $socioId = intval($socioId);

            $socio = Socio::find($socioId);
            if (!$socio) {
                return response()->json(['message' => 'Socio no encontrado.'], 404);
            }

            $identi = "";
            $razon_social = "";
            if($request->input('tipo_personeria') != 1){
                $identi = $data['identificacion'];
                $razon_social = $data['razon_social'];
            }else{
                $identi = $data['identificacion_sin_ruc'];
                $razon_social = $data['razon_social_nombre'];
            } 

            $socioExiste = Socio::where(function ($query) use ($data, $socioId, $identi, $razon_social) {
                $query->where('identificacion', $identi)
                    ->orWhere('razon_social', $razon_social);
            })->where('id', '!=', $socioId)->count();
            if ($socioExiste > 0) {
                return response()->json(['message' => 'Existe un socio con estos datos en el sistema.'], 422);
            }
            $fecha_ingreso = Carbon::createFromFormat('d/m/Y', $data['fecha_ingreso'])->format('Y-m-d');
            $rutaFoto = null;
            $contents = Storage::disk('public')->get($socio->logo);
   

            if ($request->hasFile('foto')) {
                $archivoFoto = $request->file('foto');
                $nombreArchivo = $identi . '.' . $archivoFoto->getClientOriginalExtension();
    
                $storedFilePath = "fotos_socios/" . $identi . "/" . $nombreArchivo;
                $rutaFoto = $storedFilePath;
    
                // Guardar la foto en el disco público
                $archivoFoto->storeAs("fotos_socios/" . $identi, $nombreArchivo, 'public');
            } else {
                // Intentar leer el logo existente
                if ($socio->logo && Storage::disk('public')->exists($socio->logo)) {
                    $contents = Storage::disk('public')->get($socio->logo);
                }
            }

            if (!isset($data['fecha_registro_mercantil'])) {
                $data['fecha_registro_mercantil'] = null;
            } else {
                $data['fecha_registro_mercantil'] = Carbon::createFromFormat('d/m/Y', $data['fecha_registro_mercantil'])->format('Y-m-d');
            }

            if (!isset($data['vencimiento_nombramiento'])) {
                $data['vencimiento_nombramiento'] = null;
            } else {
                $data['vencimiento_nombramiento'] = Carbon::createFromFormat('d/m/Y', $data['vencimiento_nombramiento'])->format('Y-m-d');
            }

            if ($data['tipo_personeria'] == 2) {
                $data['cedula_representante'] = null;
                $data['nombre_representante'] = null;
                $data['apellido_representante'] = null;
                $data['telefono_representante'] = null;
                $data['correo_representante'] = null;
                $data['fecha_registro_mercantil'] = null;
                $data['vencimiento_nombramiento'] = null;
                $data['fecha_nacimiento'] = Carbon::createFromFormat('d/m/Y', $data['fecha_nacimiento'])->format('Y-m-d');
            } else if ($data['tipo_personeria'] == 3) {
                $data['fecha_nacimiento'] = null;
            }
            $data['foto'] = $rutaFoto ?? $socio->foto;

            if (!isset($rutaFoto)) {
                $rutaFoto = $socio->foto;
            }

            

            if($data['tipo_personeria'] != 1){

                // Función para validar formato de fecha
                function validarFecha($fecha, $formato = 'd/m/Y') {
                    return $fecha && Carbon::hasFormat($fecha, $formato);
                }

                // Actualización del socio
                $socio->update([
                    'logo' => $rutaFoto ?? 'no hay foto',
                    'fecha_ingreso' => $fecha_ingreso,
                    'id_tipo_persona' => 1,
                    'id_tipo_personeria' => $data['tipo_personeria'],
                    'id_tipo_identificacion' => 1,
                    'identificacion' => $data['identificacion'],
                    'razon_social' => $data['razon_social'] ?? null,
                    'correo' => $data['correo'] ?? null,
                    'telefono' => $data['telefono'] ?? null,
                    'cedula_representante_legal' => $data['cedula_representante'] ?? null,
                    'nombres_representante_legal' => $data['nombre_representante'] ?? null,
                    'apellidos_representante_legal' => $data['apellido_representante'] ?? null,
                    'telefono_representante_legal' => $data['telefono_representante'] ?? null,
                    'correo_representante_legal' => $data['correo_representante'] ?? null,
                    'fecha_registro_mercantil' => validarFecha($data['fecha_registro_mercantil']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['fecha_registro_mercantil'])->format('Y-m-d') 
                        : null,
                    'fecha_vencimiento_nombramiento' => validarFecha($data['vencimiento_nombramiento']) 
                        ? Carbon::createFromFormat('d/m/Y', $data['vencimiento_nombramiento'])->format('Y-m-d') 
                        : null,
                    'fecha_desafiliacion' => null,
                    'motivo_desafiliacion' => null,
                    'estado' => 1,
                ]);

                // Actualización o creación de los datos tributarios
                $datosTributarios = DatoTributarioSocio::updateOrCreate(
                    ['id_socio' => $socioId], // Condición de búsqueda (en este caso, el id_socio)
                    [
                        'id_socio' => $socio->id,
                        'estado_sri' => 1,
                        'id_tipo_regimen' => $data['tipo_regimen'],
                        'fecha_registro_sri' => validarFecha($data['fecha_registro_sri']) 
                            ? Carbon::createFromFormat('d/m/Y', $data['fecha_registro_sri'])->format('Y-m-d') 
                            : null,
                        'fecha_actualizacion_ruc' => validarFecha($data['fecha_actualizacion_ruc']) 
                            ? Carbon::createFromFormat('d/m/Y', $data['fecha_actualizacion_ruc'])->format('Y-m-d') 
                            : null,
                        'fecha_constitucion' => validarFecha($data['fecha_constitucion']) 
                            ? Carbon::createFromFormat('d/m/Y', $data['fecha_constitucion'])->format('Y-m-d') 
                            : null,
                        'fecha_nacimiento' => validarFecha($data['fecha_nacimiento']) 
                            ? Carbon::createFromFormat('d/m/Y', $data['fecha_nacimiento'])->format('Y-m-d') 
                            : null,
                        'agente_retencion' => $data['agente_retencion'],
                        'contribuyente_especial' => $data['contribuyente_especial'],
                        'id_pais' => $data['pais'] ?? null,
                        'id_provincia' => $data['provincia'] ?? null,
                        'id_canton' => $data['canton'] ?? null,
                        'id_parroquia' => $data['parroquia'] ?? null,
                        'calle' => $data['calle'],
                        'manzana' => $data['manzana'],
                        'numero' => $data['numero'],
                        'interseccion' => $data['interseccion'] ?? null,
                        'referencia' => $data['referencia'] ?? null,
                    ]
                );

            }else{

                $socio->update([
                    'logo' => $rutaFoto ?? 'no hay foto',
                    'fecha_ingreso' => $fecha_ingreso,
                    'id_tipo_persona' => 1,
                    'id_tipo_personeria' => $data['tipo_personeria'],
                    'id_tipo_identificacion' => 1,
                    'identificacion' => $data['identificacion_sin_ruc'],
                    'razon_social' => $data['razon_social_nombre'] ?? null,
                    'correo' => $data['correo'] ?? null,
                    'telefono' => $data['telefono'] ?? null,
                    'cedula_representante_legal' => $data['cedula_representante'] ?? null,
                    'nombres_representante_legal' => $data['nombre_representante'] ?? null,
                    'apellidos_representante_legal' => $data['apellido_representante'] ?? null,
                    'telefono_representante_legal' => $data['telefono_representante'] ?? null,
                    'correo_representante_legal' => $data['correo_representante'] ?? null,
                    'fecha_registro_mercantil' => $data['fecha_registro_mercantil'] ?? null,
                    'fecha_vencimiento_nombramiento' => $data['vencimiento_nombramiento'] ?? null,
                    'fecha_desafiliacion' => null,
                    'motivo_desafiliacion' => null,
                    'estado' => 1,
                ]); 

            } 

            DB::commit();
            return response()->json([
                'message' => 'Socio actualizado correctamente',
            ], 200);
        } catch (\Throwable $th) {
            Log::error($th);
            DB::rollBack();
            /* if ($contents && Storage::disk('public')->exists($storedFilePath)) {
                Storage::disk('public')->put($storedFilePath, $contents);
            } */
            if ($contents && $storedFilePath && Storage::disk('public')->exists($storedFilePath)) {
                Storage::disk('public')->put($storedFilePath, $contents);
            }
            return response()->json(
                [
                    'error' => $th->getMessage(),
                    'message' => 'Error al modificar el socio',
                ],
                500,
            );
        }
    }


    public function eliminar_socio(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'socio_id' => 'required|integer',
                'motivo' => 'required|string|max:255',
            ]);
            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first()], 422);
            }
            $data = $validator->validated();
            DB::beginTransaction();

            $socioId = $data['socio_id'];
            $motivo = $data['motivo'];
            $socio = Socio::find($socioId);

            if (!$socio) {
                return response()->json(['message' => 'Socio no encontrado.'], 404);
            }

            $socio->estado = 0;
            $socio->fecha_desafiliacion = Carbon::now();
            $socio->motivo_desafiliacion = $motivo;
            $socio->save();
            DB::commit();

            return response()->json(['message' => 'Socio eliminado correctamente.'], 200);
        } catch (\Throwable $th) {
            Log::error($th);
            DB::rollBack();
            return response()->json(
                [
                    'error' => $th->getMessage(),
                    'message' => 'Error al eliminar el socio',
                ],
                500
            );
        }
    }

    public function get_socios()
    {
        // Obtiene todos los socios
        $socios = Socio::all();

        // Retorna los socios como JSON
        return response()->json([
            'success' => true,
            'data' => $socios
        ], 200);
    }

    public function formulario_ingreso(Request $request)
    {
        // Validar los campos
        $request->validate([
            'file1' => 'required|string', // Base64 del primer archivo
            'file2' => 'nullable|string', // Base64 del segundo archivo (opcional)
            'name1' => 'required|string', // Nombre del primer archivo
            'name2' => 'nullable|string', // Nombre del segundo archivo (opcional)
            'type1' => 'required|in:pdf,docx', // Tipo del primer archivo
            'type2' => 'nullable|in:pdf,docx', // Tipo del segundo archivo (opcional)
            'tipo_personeria' => 'required|in:1,2,3', // Debe ser 1, 2 o 3
            'tipo_identificacion' => 'required|in:1,2', // Debe ser 1 o 2
            'cedula_ruc' => [
                'required',
                'regex:/^\d{10}|\d{13}$/', // Solo acepta 10 o 13 dígitos
            ],
            'razon_social' => 'required|string',
            'correo' => 'nullable|email', // Campo no obligatorio, puede ser null o un correo válido
            'telefono' => 'nullable|string|regex:/^\+?[0-9]{7,15}$/', // Campo no obligatorio, puede ser null o un número válido
            'direccion' => 'nullable|string',
        ]);

        $data = $request->all();

        // Verificar si ya existe un registro con la misma cédula o RUC
        $existingSocio = SolicitudSocio::where('cedula_ruc', $data['cedula_ruc'])->first();

        if ($existingSocio) {
            return response()->json([
                'codigo' => '400',
                'message' => 'Ya existe una Solicitud con la misma Cédula o RUC.',
            ], 400);
        }


        // Validar longitud de cédula o RUC y tipo de identificación según tipo de personería
        if (
            // Validar para tipo_personeria 1
            ($data['tipo_personeria'] == '1' && 
                (!in_array($data['tipo_identificacion'], ['2', '3', '4']) || strlen($data['cedula_ruc']) != 10)) ||

            // Validar para tipo_personeria 2 o 3
            (in_array($data['tipo_personeria'], ['2', '3']) && 
                ($data['tipo_identificacion'] != '1' || strlen($data['cedula_ruc']) != 13))
        ) {
            return response()->json([
                'message' => 'Error: El tipo de identificación o la longitud de la cédula o RUC no corresponde con el tipo de personería.',
            ], 400);
        }

        // Crear la carpeta con el nombre de la cédula o RUC
        $folder = $data['cedula_ruc'];
        $folderPath = 'solicitudes/' . $folder;

        if (!Storage::disk('public')->exists($folderPath)) {
            Storage::disk('public')->makeDirectory($folderPath);
        }


        //$route_file = 'documentos/' . $cedula . '/' . date('Ymdhmi') . '.' . $extension; 
        // Guardar el archivo en el disco público
        //Storage::disk('public')->put($route_file, \File::get($file));


        // Procesar el primer archivo
        $fileContent1 = base64_decode($data['file1']);
        $fileName1 = $data['name1'] . '.' . $data['type1'];
        $filePath1 = $folderPath . '/' . $fileName1;
        Storage::disk('public')->put($filePath1, $fileContent1);

        // Si el tipo de personería no es 1, procesamos el segundo archivo
        $filePath2 = null;
        if ($data['tipo_personeria'] != '1') {
            $fileContent2 = base64_decode($data['file2']);
            $fileName2 = $data['name2'] . '.' . $data['type2'];
            $filePath2 = $folderPath . '/' . $fileName2;
            Storage::disk('public')->put($filePath2, $fileContent2);
        }

        // Guardar en la base de datos la Solicitud para nuevo Socio
        $socio = SolicitudSocio::create([
            'id_tipo_personeria' => $data['tipo_personeria'],
            'id_tipo_identificacion' => $data['tipo_identificacion'],
            'cedula_ruc' => $data['cedula_ruc'], 
            'razon_social' => $data['razon_social'],
            'correo' => $data['correo'],
            'telefono' => $data['telefono'],
            'direccion' => $data['direccion'],
            'id_estado_formulario' => 1, 
            'ruta_archivo1' => $filePath1, // Ruta del primer archivo
            'ruta_archivo2' => $filePath2, // Ruta del segundo archivo (puede ser null si tipo_personeria es 1)
        ]);

        /*return response()->json([
            'message' => 'Archivos subidos exitosamente',
            'file1_path' => $filePath1,
            'file2_path' => $filePath2,
        ], 201);*/
        return response()->json([
            'codigo' => '200', 
            'message' => 'Archivos subidos exitosamente' 
        ], 201);
    }

    public function estado_solicitud(Request $request)
    {
        /*$request->validate([
            'cedula_ruc' => 'required|string',  
        ]);*/
        $data = $request->all(); 

        // Buscar la solicitud y unir con la tabla 'estados_formulario_socio' para obtener la descripción
        $socio = DB::table('solicitudes_socios')
            ->join('estados_formulario_socio', 'solicitudes_socios.id_estado_formulario', '=', 'estados_formulario_socio.id')
            ->where('cedula_ruc', $data['cedula_ruc'])
            ->select('solicitudes_socios.*', 'estados_formulario_socio.descripcion as estado_descripcion')
            ->first(); // Usamos first() para obtener un solo registro

        if (!$socio) {
            return response()->json([
                'codigo' => '400',
                'message' => 'No existe una Solicitud con la información ingresada.',
            ], 400);
        }

        // Si se encuentra la solicitud, retornar los datos con la descripción del estado
        return response()->json([
            'codigo' => '200',
            'message' => 'Solicitud encontrada.',
            'estado' => $socio->estado_descripcion, // Aquí usamos la descripción del estado 
        ], 200);
    }
}
