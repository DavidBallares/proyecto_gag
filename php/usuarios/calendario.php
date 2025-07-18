<?php
// Inicia la sesión de PHP para poder acceder a variables de sesión como $_SESSION['id_usuario'].
session_start();

// --- CABECERAS HTTP, VERIFICACIÓN DE SESIÓN Y CONEXIÓN ---
// (Se asume que aquí irían tus cabeceras para evitar caché)
// Verifica si el usuario está autenticado. Si no, lo redirige al login.
if (!isset($_SESSION['id_usuario'])) { header("Location: ../../pages/auth/login.html"); exit(); }

// Incluye el archivo de conexión a la base de datos, que debe definir la variable $pdo.
include '../conexion.php'; 

// --- INICIALIZACIÓN DE VARIABLES ---
$id_usuario_actual = $_SESSION['id_usuario']; // ID del usuario logueado.
$eventos_calendario = []; // Array que almacenará todos los eventos para el calendario.
$mensaje_error = ''; // Variable para guardar mensajes de error.
$id_estado_en_progreso = 1; // ID que representa el estado "En Progreso" para filtrar los cultivos.

// --- LÓGICA PRINCIPAL DE BASE DE DATOS ---
// Verifica si la conexión a la BD está disponible.
if (!isset($pdo)) {
    $mensaje_error = "Error crítico: La conexión a la base de datos no está disponible.";
} else {
    // Se usa un bloque try-catch para manejar errores de base de datos de forma segura.
    try {
        // --- 1. OBTENER CULTIVOS ACTIVOS DEL USUARIO ---
        // Se seleccionan solo los cultivos que están "En Progreso" para el usuario actual.
        $sql_cultivos = "SELECT 
                            c.id_cultivo, tc.nombre_cultivo, c.fecha_inicio, 
                            c.fecha_fin AS fecha_cosecha_estimada, m.nombre AS nombre_municipio
                         FROM cultivos c
                         JOIN tipos_cultivo tc ON c.id_tipo_cultivo = tc.id_tipo_cultivo
                         JOIN municipio m ON c.id_municipio = m.id_municipio
                         WHERE c.id_usuario = :id_usuario_actual AND c.id_estado_cultivo = :id_estado_en_progreso";
        
        $stmt_cultivos = $pdo->prepare($sql_cultivos);
        $stmt_cultivos->bindParam(':id_usuario_actual', $id_usuario_actual, PDO::PARAM_STR); 
        $stmt_cultivos->bindParam(':id_estado_en_progreso', $id_estado_en_progreso, PDO::PARAM_INT); 
        $stmt_cultivos->execute();
        $cultivos = $stmt_cultivos->fetchAll(PDO::FETCH_ASSOC);

        // --- 2. ITERAR SOBRE CADA CULTIVO PARA OBTENER SUS TRATAMIENTOS ---
        foreach ($cultivos as $cultivo) {
            // Se crea un nombre descriptivo para el cultivo que se mostrará en el calendario.
            $nombreCultivoDisplay = htmlspecialchars($cultivo['nombre_cultivo']) . " (" . htmlspecialchars($cultivo['nombre_municipio']) . ")";

            // --- 3. OBTENER TRATAMIENTOS PARA EL CULTIVO ACTUAL ---
            // Se seleccionan todos los tratamientos asociados al cultivo actual que tengan una fecha estimada.
            $sql_tratamientos = "SELECT id_tratamiento, tipo_tratamiento, producto_usado, etapas, dosis, observaciones, 
                                        fecha_aplicacion_estimada, estado_tratamiento
                                 FROM tratamiento_cultivo
                                 WHERE id_cultivo = :id_cultivo AND fecha_aplicacion_estimada IS NOT NULL";
            $stmt_tratamientos = $pdo->prepare($sql_tratamientos);
            $stmt_tratamientos->bindParam(':id_cultivo', $cultivo['id_cultivo']);
            $stmt_tratamientos->execute();
            $tratamientos = $stmt_tratamientos->fetchAll(PDO::FETCH_ASSOC);

            // --- 4. PROCESAR CADA TRATAMIENTO Y CONVERTIRLO EN UN EVENTO DE CALENDARIO ---
            foreach ($tratamientos as $trat) {
                $classNameEvent = 'evento-tratamiento'; // Clase CSS por defecto para un tratamiento.

                // Lógica para asignar una clase CSS específica según el tipo o estado del tratamiento.
                // Esto permite dar colores diferentes a los eventos en el calendario.
                if (stripos($trat['tipo_tratamiento'], 'cosecha') !== false || stripos($trat['tipo_tratamiento'], 'fin ciclo') !== false) {
                    $classNameEvent = 'evento-cosecha-final'; // Clase especial (roja) para eventos de cosecha.
                } elseif ($trat['estado_tratamiento'] == 'Completado') {
                    $classNameEvent = 'evento-completado'; // Clase para tareas ya realizadas (verde con tachado).
                }

                // Se añade el tratamiento procesado al array de eventos del calendario.
                $eventos_calendario[] = [
                    'id' => $trat['id_tratamiento'], 
                    'title' => $trat['tipo_tratamiento'] . " - " . $nombreCultivoDisplay,
                    'start' => $trat['fecha_aplicacion_estimada'], // Fecha en que aparecerá el evento.
                    'description' => ($trat['producto_usado'] ? $trat['producto_usado'] . " (" . htmlspecialchars($trat['dosis']) . ")" : 'N/A') . " para " . $nombreCultivoDisplay . ". Etapa: " . htmlspecialchars($trat['etapas']) . ". Obs: " . htmlspecialchars($trat['observaciones']),
                    'className' => $classNameEvent, // La clase CSS determinada anteriormente.
                    'cultivo_id' => $cultivo['id_cultivo'],
                    'estado_tratamiento' => $trat['estado_tratamiento'] 
                ];
            }
            
            // --- 5. AÑADIR LA FECHA DE COSECHA PRINCIPAL DEL CULTIVO COMO UN EVENTO ---
            // Esto asegura que la fecha de fin general del cultivo también aparezca en el calendario.
            if (!empty($cultivo['fecha_cosecha_estimada'])) {
                // Lógica para evitar duplicados: comprueba si ya existe un evento de "cosecha" en esa misma fecha.
                $ya_existe_evento_cosecha_principal = false;
                foreach($eventos_calendario as $ev) {
                    if ($ev['cultivo_id'] == $cultivo['id_cultivo'] && 
                        $ev['start'] == $cultivo['fecha_cosecha_estimada'] && 
                        (stripos($ev['title'], 'cosecha') !== false || stripos($ev['title'], 'fin ciclo') !== false) ) {
                        $ya_existe_evento_cosecha_principal = true; 
                        break;
                    }
                }
                // Si no existe un evento de cosecha duplicado, se añade la cosecha principal.
                if (!$ya_existe_evento_cosecha_principal) {
                     $eventos_calendario[] = [
                        // No tiene un 'id' numérico porque no es un registro de la tabla 'tratamiento_cultivo'.
                        'title' => "Cosecha Principal Est. - " . $nombreCultivoDisplay,
                        'start' => $cultivo['fecha_cosecha_estimada'],
                        'description' => "Fecha estimada para la cosecha principal de " . $nombreCultivoDisplay,
                        'className' => 'evento-cosecha-final', // Se le asigna la clase roja.
                        'cultivo_id' => $cultivo['id_cultivo'],
                        'estado_tratamiento' => 'Pendiente'
                    ];
                }
            }

        } 

    } catch (PDOException $e) {
        $mensaje_error = "Error al cargar datos para el calendario: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Calendario de Actividades - GAG</title>
    <style>
        /* --- ESTILOS GENERALES Y DE NAVEGACIÓN --- */
        body{font-family:Arial,sans-serif;margin:0;padding:0;background-color:#f9f9f9;font-size:16px}
        .header{display:flex;align-items:center;justify-content:space-between;padding:10px 20px;background-color:#e0e0e0;border-bottom:2px solid #ccc;position:relative}
        .logo img{height:70px;} .menu{display:flex;align-items:center}
        .menu a{margin:0 5px;text-decoration:none;color:#000;padding:8px 12px;border:1px solid #ccc;border-radius:5px;white-space:nowrap;font-size:.9em}
        .menu a.active,.menu a:hover{background-color:#88c057;color:#fff!important;border-color:#70a845}
        .menu a.exit{background-color:#ff4d4d;color:#fff!important;border:1px solid #c00}
        .menu a.exit:hover{background-color:#c00;color:#fff!important}
        .menu-toggle{display:none;background:0 0;border:none;font-size:1.8rem;color:#333;cursor:pointer;padding:5px}
        
        /* --- ESTILOS DEL CALENDARIO --- */
        .page-container{max-width:1100px;margin:20px auto;padding:15px}
        .page-container > h2.page-title{text-align:center;color:#4caf50;margin-bottom:25px;font-size:1.8em}
        .calendario-wrapper{padding:20px;background-color:#fff;border-radius:8px;box-shadow:0 4px 10px rgba(0,0,0,.1)}
        .calendario-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:15px;border-bottom:1px solid #e0e0e0}
        .calendario-header button{background-color:#f0f0f0;color:#333;border:1px solid #ccc;padding:8px 15px;cursor:pointer;border-radius:5px;font-size:.9em}
        .calendario-header button:hover{background-color:#e0e0e0;}
        .calendario-header h3{margin:0;font-size:1.5em;color:#4caf50;font-weight:700;text-align:center;flex-grow:1}
        .calendario-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background-color:#ddd;border:1px solid #ddd;border-radius:5px;overflow:hidden}
        .dia-semana,.dia-calendario{background-color:#fff;padding:8px 5px;text-align:center;height:110px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;font-size:.85em;position:relative;border:none;box-sizing:border-box}
        .dia-semana{font-weight:700;background-color:#f5f5f5;color:#555;height:auto;min-height:auto;padding:10px 5px;font-size:.8em;text-transform:uppercase}
        .dia-numero{font-weight:700;margin-bottom:4px;font-size:1em;color:#444;width:24px;height:24px;line-height:24px;display:flex;align-items:center;justify-content:center;border-radius:50%;flex-shrink:0}
        .dia-calendario.otro-mes .dia-numero{color:#b0b0b0;opacity:.6}
        .dia-calendario.hoy .dia-numero{background-color:#4caf50;color:#fff}
        .eventos-del-dia{width:100%;flex-grow:1;overflow-y:auto;display:flex;flex-direction:column;align-items:center;gap:2px;margin-top:4px}
        .evento-calendario{font-size:.75em;padding:3px 5px;border-radius:3px;width:95%;white-space:normal;word-wrap:break-word;border-left:3px solid;text-align:left;cursor:pointer;box-shadow:0 1px 1px rgba(0,0,0,.05);line-height:1.3;margin-bottom:2px;}
        .evento-calendario:hover { opacity:0.8; }

        /* Colores de los eventos */
        .evento-tratamiento{border-left-color:#3498db;background-color:#eaf5fc;color:#2980b9} /* Azul para tratamientos generales */
        .evento-riego{border-left-color:#1abc9c;background-color:#e8f8f5;color:#16a085} /* Verde-azulado para riego */
        .evento-cosecha-final {border-left-color: #e74c3c; background-color: #fdedec; color: #c0392b; font-weight: bold;} /* Rojo para cosechas y fin de ciclo */
        .evento-cosecha-estimada { border-left-color: #e74c3c; background-color: #fdedec; color: #c0392b; font-weight: bold;} /* Rojo, como respaldo */
        .evento-completado { border-left-color: #27ae60; background-color: #e6f7e9; color: #22863a; text-decoration: line-through; opacity: 0.7; } /* Verde para completados */

        /* Leyenda y mensajes */
        .leyenda{margin-top:25px;padding-top:15px;border-top:1px solid #e0e0e0}
        .leyenda h4{margin-top:0;margin-bottom:12px;color:#333;font-size:1.05em;font-weight:700}
        .leyenda-item{display:flex;align-items:center;margin-bottom:8px;font-size:.9em;color:#555}
        .leyenda-color{width:16px;height:16px;margin-right:10px;border-radius:3px;border:1px solid rgba(0,0,0,.1)}
        .error-message-calendar{background-color:#ffdddd;border:1px solid #ffcccc;color:#d8000c;padding:10px;border-radius:5px;text-align:center;margin-bottom:20px}
        .no-eventos { text-align:center; margin-top:20px; color: #777; font-style: italic;}

        /* --- ESTILOS DEL MODAL (POPUP) --- */
        .modal { display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); }
        .modal-content { background-color: #fff; margin: 10% auto; padding: 25px; border: 1px solid #bbb; width: 80%; max-width: 550px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative; }
        .modal-close { color: #aaa; float: right; font-size: 28px; font-weight: bold; position: absolute; top: 10px; right: 20px; }
        .modal-close:hover, .modal-close:focus { color: black; text-decoration: none; cursor: pointer; }
        .modal h4 { margin-top: 0; color: #4caf50; font-size: 1.4em; margin-bottom: 15px;}
        .modal p { margin-bottom: 10px; font-size: 0.95em; line-height: 1.6; }
        .modal strong { color: #333; }
        .modal .form-group { margin-top:15px; }
        .modal .form-group label { font-size: 0.9em; }
        .modal textarea { width:100%; min-height: 80px; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing: border-box; font-size:0.95em;}
        .modal .modal-actions { margin-top:20px; text-align:right; }
        .modal .btn-modal { padding:10px 18px; border:none; border-radius:5px; cursor:pointer; font-size:0.9em; font-weight:bold; margin-left:10px;}
        .btn-modal-completar { background-color: #28a745; color:white; }
        .btn-modal-completar:hover { background-color: #218838; }
        .btn-modal-cerrar-popup { background-color: #ccc; color:#333; }
        .btn-modal-cerrar-popup:hover { background-color: #bbb; }

        /* --- ESTILOS RESPONSIVOS --- */
        @media (max-width:991.98px){.menu-toggle{display:block}.menu{display:none;flex-direction:column;align-items:stretch;position:absolute;top:100%;left:0;width:100%;background-color:#e9e9e9;padding:0;box-shadow:0 4px 8px rgba(0,0,0,.1);z-index:1000;border-top:1px solid #ccc}.menu.active{display:flex}.menu a{margin:0;padding:15px 20px;width:100%;text-align:left;border:none;border-bottom:1px solid #d0d0d0;border-radius:0;color:#333}.menu a:last-child{border-bottom:none}.menu a.active,.menu a:hover{background-color:#88c057;color:#fff!important;border-color:transparent}.menu a.exit,.menu a.exit:hover{background-color:#ff4d4d;color:#fff!important}.page-container > h2.page-title{font-size:1.6em;margin-bottom:20px}.calendario-wrapper{padding:15px}.calendario-header h3{font-size:1.4em}.calendario-header button{padding:7px 12px;font-size:.85em}.dia-semana{font-size:.75em;padding:9px 3px}.dia-calendario{height:90px;padding:7px 3px}.dia-numero{font-size:.95em}.evento-calendario{font-size:.7em} .modal-content{width:90%; margin: 15% auto;} }
        @media (max-width:767.98px){.logo img{height:60px}.menu-toggle{font-size:1.6rem}.page-container > h2.page-title{font-size:1.5em}.calendario-header h3{font-size:1.3em}.dia-semana{font-size:.7em}.dia-calendario{height:80px}.dia-numero{font-size:.9em}}
        @media (max-width:480px){.logo img{height:50px}.page-container > h2.page-title{font-size:1.3em}.calendario-header{flex-direction:column;gap:10px}.calendario-header h3{order:-1}.dia-semana{font-size:.6em}.dia-calendario{height:75px}.dia-numero{font-size:.8em}.evento-calendario{font-size:.65em} .modal-content{padding:20px;}}
    </style>
</head>
<body>
    <!-- Menú de navegación principal -->
    <div class="header">
        <div class="logo"><img src="../../img/logo.png" alt="Logo GAG" /></div>
        <button class="menu-toggle" id="menuToggleBtn" aria-label="Abrir menú" aria-expanded="false">☰</button>
        <nav class="menu" id="mainMenu">
            <a href="../index.php">Inicio</a>
            <a href="cultivos/miscultivos.php">Mis Cultivos</a>
            <a href="animales/mis_animales.php">Mis Animales</a>
            <a href="calendario.php" class="active">Calendario</a>
            <a href="configuracion.php">Configuración</a>
            <a href="ayuda.php">Ayuda</a> <a href="cerrar_sesion.php" class="exit">Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Contenedor principal de la página del calendario -->
    <div class="page-container">
        <h2 class="page-title">Mi Calendario de Actividades</h2>
        <?php if (!empty($mensaje_error)): ?><p class="error-message-calendar"><?php echo htmlspecialchars($mensaje_error); ?></p><?php endif; ?>

        <div class="calendario-wrapper">
            <!-- Controles para navegar entre meses -->
            <div class="calendario-header">
                <button id="mes-anterior">< Anterior</button>
                <h3 id="mes-anio-actual"></h3>
                <button id="mes-siguiente">Siguiente ></button>
            </div>
            <!-- Grid estático con los nombres de los días de la semana -->
            <div class="calendario-grid">
                <div class="dia-semana">Dom</div><div class="dia-semana">Lun</div><div class="dia-semana">Mar</div><div class="dia-semana">Mié</div><div class="dia-semana">Jue</div><div class="dia-semana">Vie</div><div class="dia-semana">Sáb</div>
            </div>
            <!-- Grid dinámico que será llenado por JavaScript con los días y eventos -->
            <div class="calendario-grid" id="dias-calendario-grid"></div>

            <!-- Leyenda que explica el significado de los colores de los eventos -->
            <div class="leyenda">
                <h4>Leyenda:</h4>
                <div class="leyenda-item"><span class="leyenda-color evento-tratamiento"></span> Tratamiento Pendiente</div>
                <div class="leyenda-item"><span class="leyenda-color evento-riego"></span> Riego Estimado</div>
                <div class="leyenda-item"><span class="leyenda-color evento-cosecha-final" style="background-color: #fdedec; border-left: 3px solid #e74c3c;"></span> Cosecha/Fin Estimada</div>
                <div class="leyenda-item"><span class="leyenda-color evento-completado" style="background-color: #e6f7e9; border-left: 3px solid #27ae60;"></span> Tarea Completada</div>
            </div>
            <?php if (isset($pdo) && empty($eventos_calendario) && empty($mensaje_error)): ?>
                <p class="no-eventos">No tienes actividades programadas para tus cultivos en progreso.</p>
            <?php endif; ?>
        </div> 
    </div> 

    <!-- Modal (popup) para mostrar detalles del evento. Inicialmente está oculto. -->
    <div id="eventoModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" id="modalCloseBtn">×</span>
            <h4 id="modalTitulo">Detalle de la Tarea</h4>
            <p><strong>Cultivo:</strong> <span id="modalCultivo"></span></p>
            <p><strong>Tipo de Tarea:</strong> <span id="modalTipoTarea"></span></p>
            <p><strong>Descripción/Producto:</strong> <span id="modalDescripcion"></span></p>
            <p><strong>Fecha Estimada:</strong> <span id="modalFechaEstimada"></span></p>
            <p><strong>Estado Actual:</strong> <span id="modalEstadoActual"></span></p>
            <!-- Formulario para marcar una tarea como completada, oculto por defecto. -->
            <div id="modalFormCompletar" style="display:none;">
                <div class="form-group"><label for="modalFechaRealizacion">Fecha de Realización:</label><input type="date" id="modalFechaRealizacion" name="fecha_realizacion"></div>
                <div class="form-group"><label for="modalObservaciones">Observaciones Adicionales:</label><textarea id="modalObservaciones" name="observaciones_realizacion" rows="3"></textarea></div>
            </div>
            <div class="modal-actions">
                <button id="btnMarcarCompletado" class="btn-modal btn-modal-completar" style="display:none;">Marcar como Completado</button>
                <button id="btnCerrarPopup" class="btn-modal btn-modal-cerrar-popup">Cerrar</button>
            </div>
            <input type="hidden" id="modalIdTratamiento">
        </div>
    </div>

    <script>
        // --- JAVASCRIPT PARA EL CALENDARIO ---

        // Transfiere el array de eventos de PHP a una variable de JavaScript.
        // json_encode convierte el array PHP en una cadena de texto en formato JSON, que JS puede interpretar.
        const eventosDesdePHP = <?php echo json_encode($eventos_calendario); ?>;

        // Se ejecuta cuando el contenido HTML de la página está completamente cargado.
        document.addEventListener('DOMContentLoaded', function() {
            // --- OBTENCIÓN DE ELEMENTOS DEL DOM ---
            const calendarioGrid = document.getElementById('dias-calendario-grid');
            const mesAnioActualLabel = document.getElementById('mes-anio-actual');
            const btnMesAnterior = document.getElementById('mes-anterior');
            const btnMesSiguiente = document.getElementById('mes-siguiente');
            let fechaActualVisualizada = new Date(); // Fecha que controla el mes y año que se muestra.

            // Elementos del modal
            const modal = document.getElementById('eventoModal');
            const modalCloseBtn = document.getElementById('modalCloseBtn');
            const modalTitulo = document.getElementById('modalTitulo');
            const modalCultivo = document.getElementById('modalCultivo');
            const modalTipoTarea = document.getElementById('modalTipoTarea');
            const modalDescripcion = document.getElementById('modalDescripcion');
            const modalFechaEstimada = document.getElementById('modalFechaEstimada');
            const modalEstadoActual = document.getElementById('modalEstadoActual');
            const modalFormCompletar = document.getElementById('modalFormCompletar');
            const modalFechaRealizacionInput = document.getElementById('modalFechaRealizacion');
            const modalObservacionesInput = document.getElementById('modalObservaciones');
            const btnMarcarCompletado = document.getElementById('btnMarcarCompletado');
            const btnCerrarPopup = document.getElementById('btnCerrarPopup');
            const modalIdTratamientoInput = document.getElementById('modalIdTratamiento');

            // --- FUNCIONES PRINCIPALES ---

            // Función para formatear una fecha a 'YYYY-MM-DD'.
            function formatearFecha(dateObj) {
                const anio = dateObj.getFullYear();
                const mes = String(dateObj.getMonth() + 1).padStart(2, '0');
                const dia = String(dateObj.getDate()).padStart(2, '0');
                return `${anio}-${mes}-${dia}`;
            }

            // Función para renderizar el calendario para el mes y año actuales.
            function renderizarCalendario() {
                if (!calendarioGrid) return; // Si el grid no existe, no hacer nada.
                calendarioGrid.innerHTML = ''; // Limpia el calendario anterior.
                const anio = fechaActualVisualizada.getFullYear();
                const mes = fechaActualVisualizada.getMonth();
                if(mesAnioActualLabel) { mesAnioActualLabel.textContent = `${fechaActualVisualizada.toLocaleString('es-ES', { month: 'long' })} ${anio}`; }
                
                const primerDiaDelMes = new Date(anio, mes, 1).getDay(); // 0=Domingo, 1=Lunes...
                const diasEnMes = new Date(anio, mes + 1, 0).getDate(); // Truco para obtener el último día del mes.
                
                // Añade celdas vacías al inicio para alinear el día 1 con el día de la semana correcto.
                for (let i = 0; i < primerDiaDelMes; i++) { 
                    const celdaVacia = document.createElement('div'); 
                    celdaVacia.classList.add('dia-calendario', 'otro-mes'); 
                    calendarioGrid.appendChild(celdaVacia); 
                }
                const hoyStr = formatearFecha(new Date());

                // Crea una celda para cada día del mes.
                for (let dia = 1; dia <= diasEnMes; dia++) {
                    const celdaDia = document.createElement('div'); 
                    celdaDia.classList.add('dia-calendario');
                    const fechaCeldaStr = formatearFecha(new Date(anio, mes, dia));
                    const diaNumero = document.createElement('span'); 
                    diaNumero.classList.add('dia-numero'); 
                    diaNumero.textContent = dia; 
                    celdaDia.appendChild(diaNumero);
                    if (fechaCeldaStr === hoyStr) { celdaDia.classList.add('hoy'); }
                    
                    const eventosDelDiaContainer = document.createElement('div'); 
                    eventosDelDiaContainer.classList.add('eventos-del-dia');
                    
                    // Filtra y muestra los eventos correspondientes a esta fecha.
                    eventosDesdePHP.filter(e => e.start === fechaCeldaStr).forEach(evento => {
                        const divEvento = document.createElement('div'); 
                        divEvento.classList.add('evento-calendario');
                        
                        // Añade la clase CSS correcta que viene desde PHP.
                        if (evento.className) { divEvento.classList.add(evento.className); }
                        
                        if (evento.estado_tratamiento === 'Completado') {
                            divEvento.textContent = "✅ " + evento.title;
                        } else {
                            divEvento.textContent = evento.title;
                        }
                        divEvento.title = evento.description || evento.title;
                        
                        // Añade el evento de click para abrir el modal, si el evento tiene un ID numérico (es un tratamiento).
                        if(typeof evento.id === 'number') { 
                            divEvento.addEventListener('click', () => abrirModalConEvento(evento));
                        }
                        eventosDelDiaContainer.appendChild(divEvento);
                    });
                    celdaDia.appendChild(eventosDelDiaContainer); 
                    calendarioGrid.appendChild(celdaDia);
                }
            } 

            // Función para abrir y poblar el modal con la información del evento clicado.
            function abrirModalConEvento(evento) { 
                modalTitulo.textContent = "Detalle: " + evento.title.split(" - ")[0]; 
                modalCultivo.textContent = evento.title.substring(evento.title.indexOf("(") + 1, evento.title.lastIndexOf(")"));
                modalTipoTarea.textContent = evento.title.split(" - ")[0];
                modalDescripcion.textContent = evento.description.split(" para ")[0]; 
                modalFechaEstimada.textContent = new Date(evento.start + 'T00:00:00').toLocaleDateString('es-ES', { year: 'numeric', month: 'long', day: 'numeric' });
                modalEstadoActual.textContent = evento.estado_tratamiento || 'Pendiente';
                modalIdTratamientoInput.value = evento.id;

                // Muestra el formulario para completar la tarea si no está ya completada.
                if (evento.estado_tratamiento !== 'Completado' && evento.id) { 
                    modalFormCompletar.style.display = 'block';
                    btnMarcarCompletado.style.display = 'inline-block';
                    modalFechaRealizacionInput.value = formatearFecha(new Date()); 
                    modalObservacionesInput.value = '';
                } else {
                    modalFormCompletar.style.display = 'none';
                    btnMarcarCompletado.style.display = 'none';
                }
                modal.style.display = "block";
             }
            
            // --- EVENT LISTENERS ---
            if(modalCloseBtn) modalCloseBtn.onclick = function() { modal.style.display = "none"; }
            if(btnCerrarPopup) btnCerrarPopup.onclick = function() { modal.style.display = "none"; }
            window.onclick = function(event) { if (event.target == modal) { modal.style.display = "none"; } }

            if(btnMarcarCompletado) {
                btnMarcarCompletado.addEventListener('click', function() {
                    const idTratamiento = modalIdTratamientoInput.value;
                    const fechaRealizacion = modalFechaRealizacionInput.value;
                    const observaciones = modalObservacionesInput.value;

                    if (!fechaRealizacion) { alert("Por favor, selecciona la fecha de realización."); return; }

                    // Envía los datos al servidor para actualizar el tratamiento de forma asíncrona.
                    fetch('cultivos/marcar_tratamiento_completado.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', },
                        body: `id_tratamiento=${idTratamiento}&fecha_realizacion=${fechaRealizacion}&observaciones=${encodeURIComponent(observaciones)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            modal.style.display = "none";
                            // Actualiza el estado del evento en el array local y vuelve a renderizar el calendario.
                            const eventoIndex = eventosDesdePHP.findIndex(ev => ev.id == idTratamiento);
                            if (eventoIndex > -1) {
                                eventosDesdePHP[eventoIndex].estado_tratamiento = 'Completado';
                                eventosDesdePHP[eventoIndex].className = 'evento-completado';
                            }
                            renderizarCalendario(); 
                        } else {
                            alert("Error: " + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error al marcar como completado:', error);
                        alert("Error de conexión al intentar actualizar el tratamiento.");
                    });
                });
            }
            
            // Botones de navegación de mes.
            if (btnMesAnterior) { btnMesAnterior.addEventListener('click', () => { fechaActualVisualizada.setMonth(fechaActualVisualizada.getMonth() - 1); renderizarCalendario(); }); }
            if (btnMesSiguiente) { btnMesSiguiente.addEventListener('click', () => { fechaActualVisualizada.setMonth(fechaActualVisualizada.getMonth() + 1); renderizarCalendario(); }); }
            
            // Primera renderización del calendario al cargar la página.
            if (calendarioGrid) { renderizarCalendario(); }
            
            // Lógica del menú hamburguesa para móviles.
            const menuToggleBtn = document.getElementById('menuToggleBtn');
            const mainMenu = document.getElementById('mainMenu');
            if (menuToggleBtn && mainMenu) {
                menuToggleBtn.addEventListener('click', () => {
                    mainMenu.classList.toggle('active');
                    menuToggleBtn.setAttribute('aria-expanded', mainMenu.classList.contains('active'));
                });
            }
        });
    </script>
</body>
</html>