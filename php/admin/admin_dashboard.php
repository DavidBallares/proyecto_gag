<?php
// Inicia la sesión de PHP. Es un paso crucial que debe ir al principio de todo para poder
// usar variables de sesión como $_SESSION['id_usuario'] y $_SESSION['rol'].
session_start();

// --- Bloque de Seguridad: Autenticación y Autorización ---
// Este bloque es fundamental para proteger esta página y asegurar que solo el administrador pueda acceder.

// Evita el caché del navegador, forzando al navegador a solicitar siempre la versión más reciente de la página.
// Es importante para páginas de administración donde los datos cambian constantemente, evitando que se muestre información obsoleta.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT"); // Establece una fecha de expiración en el pasado para invalidar la caché.

// Verifica si existe la variable de sesión 'id_usuario'. Si no existe, significa que el usuario no ha iniciado sesión,
// por lo que se le redirige a la página de login.
if (!isset($_SESSION['id_usuario'])) {
    header("Location: ../../pages/auth/login.html"); 
    exit(); // Detiene la ejecución del script para evitar que se cargue el resto del contenido.
}

// Incluye el archivo que establece la conexión a la base de datos y define la variable $pdo.
// Es importante que la ruta a este archivo sea correcta desde la ubicación del script actual.
require_once '../conexion.php'; 

// Verifica si la conexión a la base de datos se estableció correctamente.
if (!isset($pdo)) {
    die("Error crítico: No se pudo establecer la conexión a la base de datos.");
}

// Verifica si el rol del usuario logueado es de Administrador (id_rol = 1).
// Esto se hace consultando la base de datos para obtener el rol actual del usuario,
// lo que añade una capa extra de seguridad en caso de que la variable de sesión de rol se manipule.
$stmt_rol_check = $pdo->prepare("SELECT id_rol FROM usuarios WHERE id_usuario = :id_usuario");
$stmt_rol_check->bindParam(':id_usuario', $_SESSION['id_usuario']);
$stmt_rol_check->execute();
$rol_del_usuario_logueado = $stmt_rol_check->fetchColumn();

// Si el rol obtenido no es 1, se le redirige fuera del área de administración.
if ($rol_del_usuario_logueado != 1) {
    header("Location: ../index.php"); // Redirige a la página de inicio para usuarios normales.
    exit();
}
// --- Fin del Bloque de Seguridad ---


// --- Bloque de Carga de Datos para el Dashboard ---
// En esta sección se obtienen los datos que se mostrarán en el panel, como las estadísticas clave.

// Inicialización de variables para las estadísticas.
$total_usuarios_no_admin = 0;
$total_cultivos = 0;
$total_animales = 0;
$total_tickets_abiertos = 0;
$stats_error_message = ''; // Variable para almacenar mensajes de error si las consultas fallan.

// Se utiliza un bloque try-catch para manejar errores de base de datos de forma segura.
try {
    // Se ejecutan consultas SQL simples para contar registros en diferentes tablas.
    // `->fetchColumn()` es una forma eficiente de obtener el resultado de una consulta `COUNT(*)`.
    $total_usuarios_no_admin = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE id_rol = 2")->fetchColumn();
    $total_cultivos = $pdo->query("SELECT COUNT(*) FROM cultivos")->fetchColumn();
    $total_animales = $pdo->query("SELECT COUNT(*) FROM animales")->fetchColumn();
    $total_tickets_abiertos = $pdo->query("SELECT COUNT(*) FROM tickets_soporte WHERE estado_ticket = 'Abierto'")->fetchColumn();
} catch (PDOException $e) {
    // Si alguna de las consultas falla, se captura el error y se guarda un mensaje para mostrarlo al admin.
    $stats_error_message = "Error al cargar estadísticas: " . $e->getMessage();
}

// Define el municipio por defecto para la consulta del clima. Esto podría hacerse configurable en el futuro.
$nombre_municipio_para_clima = "Ibagué";
// Obtiene el nombre del administrador desde la sesión para un saludo personalizado.
// Usa el operador de fusión de null (??) para tener un valor de respaldo si 'usuario_nombre' no está definido.
$admin_nombre = $_SESSION['usuario_nombre'] ?? $_SESSION['usuario'] ?? 'Administrador'; 
// --- Fin del Bloque de Carga de Datos ---
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - GAG</title>
    <style>
        /* --- ESTILOS CSS --- */
        /* Aquí se definen todos los estilos visuales para la página del dashboard,
           incluyendo el layout, la cabecera, el menú, las tarjetas de estadísticas y acciones,
           y las reglas de responsividad para adaptar la vista a diferentes dispositivos. */
        
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f8f1; font-size: 16px; color: #333; }
        .header { display: flex; align-items: center; justify-content: space-between; padding: 10px 20px; background-color: #e0e0e0; border-bottom: 2px solid #ccc; position: relative; }
        .logo img { height: 70px; }
        .menu { display: flex; align-items: center; }
        .menu a { margin: 0 5px; text-decoration: none; color: black; padding: 8px 12px; border: 1px solid #ccc; border-radius: 5px; transition: background-color 0.3s, color 0.3s; white-space: nowrap; font-size: 0.9em; }
        .menu a.active, .menu a:hover { background-color: #88c057; color: white !important; border-color: #70a845; }
        .menu a.exit { background-color: #ff4d4d; color: white !important; border: 1px solid #cc0000; }
        .menu a.exit:hover { background-color: #cc0000; }
        .menu-toggle { display: none; background: none; border: none; font-size: 1.8rem; color: #333; cursor: pointer; padding: 5px; }
        
        .page-container { max-width: 1200px; margin: 20px auto; padding: 20px; }
        .page-title { text-align: center; color: #4caf50; margin-bottom: 30px; font-size: 2em; }
        
        .dashboard-section { width: 100%; margin-bottom: 35px; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); }
        .dashboard-section h3.section-title { color: #333; font-size: 1.5em; margin-top: 0; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #88c057; }
        
        .cards-container { display: flex; flex-wrap: wrap; gap: 20px; justify-content: flex-start; }
        
        .card-action-link { background: linear-gradient(to bottom, #88c057, #6da944); color: white !important; border-radius: 8px; padding: 20px; width: 100%; max-width: 220px; min-height: 100px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; box-shadow: 0 4px 8px rgba(0,0,0,0.1); text-decoration: none; font-weight: bold; font-size: 1.05em; transition: transform 0.2s ease-out, box-shadow 0.2s ease-out; cursor: pointer; box-sizing: border-box; }
        .card-action-link:hover { transform: translateY(-5px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
        
        .stat-card { background: linear-gradient(to right, #6AB44A, #4A8C30); color: white; border-radius: 8px; padding: 20px; width: 100%; max-width: 220px; min-height: 130px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; box-shadow: 0 4px 8px rgba(0,0,0,0.1); transition: transform 0.3s ease; box-sizing: border-box; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .card-text { font-size: 0.95em; font-weight: 500; margin-bottom: 8px; opacity: 0.9; }
        .stat-card .card-number { font-size: 2.4em; font-weight: bold; line-height: 1.1; }
        
        .weather-display-card { padding: 15px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); color: #333; margin: 0; width: 100%; max-width: 220px; min-height: 150px; display: flex; flex-direction: column; align-items: center; text-align: center; box-sizing: border-box; }
        .weather-display-card h4 { margin-top:0;margin-bottom:10px;color:#0056b3;font-size:1.1em;width:100%; }
        .weather-display-card p { margin:4px 0;font-size:0.85em; }
        .weather-display-card #clima-icono img { width:50px;height:50px; }
        .weather-display-card #clima-descripcion { text-transform:capitalize;font-weight:bold;margin-bottom:8px; }
        
        .error-message { color: #d8000c; background-color: #ffdddd; border:1px solid #ffcccc; padding:10px; border-radius:5px; text-align:center; margin-bottom:15px; }
        
        @media (max-width: 991.98px) { .menu-toggle { display: block; } .menu { display: none; flex-direction: column; align-items: stretch; position: absolute; top: 100%; left: 0; width: 100%; background-color: #e9e9e9; padding: 0; box-shadow: 0 4px 8px rgba(0,0,0,.1); z-index: 1000; border-top: 1px solid #ccc; } .menu.active { display: flex; } .menu a { margin:0; padding:15px 20px; width:100%; text-align:left; border:none; border-bottom:1px solid #d0d0d0; border-radius:0; color:#333; } .menu a:last-child { border-bottom: none; } .menu a.active, .menu a:hover { background-color: #88c057; color: white !important; } .menu a.exit, .menu a.exit:hover { background-color: #ff4d4d; color: white !important; } .card-action-link, .stat-card, .weather-display-card { max-width: calc(50% - 10px); } }
        @media (max-width: 767px) { .logo img { height: 60px; } .page-title { font-size: 1.6em; } .dashboard-section h3.section-title { font-size: 1.3em; } .card-action-link, .stat-card, .weather-display-card { max-width: 100%; min-height: 100px; } .stat-card .card-number { font-size: 2em; } }
        @media (max-width: 480px) { .logo img { height: 50px; } .menu-toggle { font-size: 1.6rem; } .page-title { font-size: 1.4em; } .dashboard-section h3.section-title { font-size: 1.2em; } .card-action-link { font-size: 1em; } .stat-card .card-text { font-size: 0.9em; } .stat-card .card-number { font-size: 1.8em; } }
    </style>
</head>
<body>
    <!-- --- ESTRUCTURA HTML DE LA PÁGINA --- -->
    <div class="header">
        <div class="logo">
            <img src="../../img/logo.png" alt="Logo GAG" />
        </div>
        <button class="menu-toggle" id="menuToggleBtn" aria-label="Abrir menú" aria-expanded="false">☰</button>
        <nav class="menu" id="mainMenu">
            <a href="admin_dashboard.php" class="active">Inicio Admin</a> 
            <a href="view_users.php">Ver Usuarios</a>
            <a href="view_all_crops.php">Ver Cultivos</a>
            <a href="admin_manage_trat_pred.php">Tratamientos Pred.</a>
            <a href="view_all_animals.php">Ver Animales</a> 
            <a href="manage_tickets.php">Gestionar Tickets</a>
            <a href="../cerrar_sesion.php" class="exit">Cerrar Sesión</a>
        </nav>
    </div>

    <div class="page-container">
        <h2 class="page-title">Panel de Administración GAG - ¡Bienvenido, <?php echo htmlspecialchars($admin_nombre); ?>!</h2>
        
        <!-- Muestra un mensaje de error si las estadísticas no se pudieron cargar. -->
        <?php if (!empty($stats_error_message)): ?>
            <p class="error-message"><?php echo htmlspecialchars($stats_error_message); ?></p>
        <?php endif; ?>

        <!-- Sección de Estadísticas Rápidas y Clima -->
        <!-- Muestra los datos obtenidos del bloque de carga de datos PHP. -->
        <section class="dashboard-section">
            <h3 class="section-title">Estadísticas Rápidas y Clima</h3>
            <div class="cards-container">
                <div class="stat-card">
                    <div class="card-text">Usuarios Registrados</div>
                    <div class="card-number"><?php echo htmlspecialchars($total_usuarios_no_admin); ?></div>
                </div>
                <div class="stat-card">
                    <div class="card-text">Cultivos Totales</div>
                    <div class="card-number"><?php echo htmlspecialchars($total_cultivos); ?></div>
                </div>
                <div class="stat-card">
                    <div class="card-text">Animales Totales</div>
                    <div class="card-number"><?php echo htmlspecialchars($total_animales); ?></div>
                </div>
                 <div class="stat-card">
                    <div class="card-text">Tickets Abiertos</div>
                    <div class="card-number"><?php echo htmlspecialchars($total_tickets_abiertos); ?></div>
                </div>
                 <!-- Tarjeta del Clima: Esta tarjeta se llenará dinámicamente con JavaScript. -->
                 <div class="weather-display-card">
                    <h4>Clima en <span id="clima-ciudad">Cargando...</span></h4>
                    <div id="clima-icono"></div>
                    <p id="clima-descripcion"></p>
                    <p><strong>Temp:</strong> <span id="clima-temp">--</span> °C</p>
                    <p><strong>Humedad:</strong> <span id="clima-humedad">--</span> %</p>
                    <p id="clima-lluvia-pop"></p>
                </div>
            </div>
        </section>

        <!-- Secciones de Acciones Rápidas -->
        <!-- Son tarjetas que funcionan como enlaces directos a las principales áreas de gestión del panel. -->
        <section class="dashboard-section">
            <h3 class="section-title">Gestión General</h3>
            <div class="cards-container">
                <a href="view_users.php" class="card-action-link">Ver Usuarios</a>
                <a href="manage_tickets.php" class="card-action-link">Gestionar Tickets Soporte</a>
            </div>
        </section>

        <section class="dashboard-section">
            <h3 class="section-title">Gestión Agrícola</h3>
            <div class="cards-container">
                <a href="view_all_crops.php" class="card-action-link">Ver Todos los Cultivos</a>
                <a href="admin_manage_trat_pred.php" class="card-action-link">Gestionar Tratamientos Predeterminados</a>
            </div>
        </section>

        <section class="dashboard-section">
            <h3 class="section-title">Gestión Ganadera</h3>
            <div class="cards-container">
                <a href="view_all_animals.php" class="card-action-link">Ver Todos los Animales</a>
            </div>
        </section>
        
        <section class="dashboard-section">
            <h3 class="section-title">Reportes</h3>
            <div class="cards-container">
                 <a href="generar_reporte_excel.php" class="card-action-link" target="_blank">
                    Generar Reporte General (Excel)
                </a>
            </div>
        </section>
    </div> 

    <script>
    // Se ejecuta cuando todo el contenido HTML de la página ha sido cargado.
    document.addEventListener('DOMContentLoaded', function() {
        // --- Lógica del Menú Hamburguesa ---
        const menuToggleBtn = document.getElementById('menuToggleBtn');
        const mainMenu = document.getElementById('mainMenu');
        if (menuToggleBtn && mainMenu) {
            menuToggleBtn.addEventListener('click', () => {
                // Alterna la clase 'active' para mostrar u ocultar el menú en móviles.
                mainMenu.classList.toggle('active');
                // Actualiza el atributo 'aria-expanded' para accesibilidad.
                menuToggleBtn.setAttribute('aria-expanded', mainMenu.classList.contains('active'));
            });
        }

        // --- Bloque de Lógica del Clima (JavaScript) ---
        // Este script obtiene y muestra la información del clima de forma asíncrona.

        // Se obtienen los elementos del DOM donde se mostrará la información.
        const climaCiudadEl = document.getElementById('clima-ciudad');
        const climaIconoEl = document.getElementById('clima-icono');
        const climaDescripcionEl = document.getElementById('clima-descripcion');
        const climaTempEl = document.getElementById('clima-temp');
        const climaHumedadEl = document.getElementById('clima-humedad');
        const climaLluviaPopEl = document.getElementById('clima-lluvia-pop');

        // Se define la ciudad para la cual se pedirá el clima, usando el valor definido en PHP.
        let ciudadParaClima = "<?php echo htmlspecialchars(addslashes($nombre_municipio_para_clima . ',CO')); ?>"; 

        // Función que realiza la llamada a la API para obtener los datos del clima.
        function cargarClima() {
            // Se hace una petición a un script PHP local (api_clima.php).
            // Esto es una buena práctica para no exponer la API Key de OpenWeatherMap en el código del cliente.
            const urlApiLocal = `../api_clima.php?ciudad=${encodeURIComponent(ciudadParaClima)}`; 

            // Se usa `fetch` para realizar la petición AJAX.
            fetch(urlApiLocal)
                .then(response => {
                    // Se comprueba si la respuesta del servidor es exitosa (código 200-299).
                    if (!response.ok) {
                        // Si no es exitosa, se intenta leer el cuerpo del error y se lanza una excepción.
                        return response.json().then(errData => {
                            let errorMsg = `Error HTTP: ${response.status}`;
                            if (errData && errData.error) { errorMsg = errData.error; }
                            else if (errData && errData.message) { errorMsg = `API Clima: ${errData.message}`; }
                            throw new Error(errorMsg);
                        }).catch(() => { 
                            throw new Error(`Error HTTP: ${response.status} al contactar API clima local.`);
                        });
                    }
                    return response.json(); // Se convierte la respuesta exitosa a formato JSON.
                })
                .then(data => {
                    // Una vez que se obtienen los datos, se actualizan los elementos del DOM.
                    // Se verifica que la API de OpenWeatherMap no haya devuelto un error propio.
                    if (data.cod && data.cod.toString() !== "200") { 
                        if(climaCiudadEl) climaCiudadEl.textContent = ciudadParaClima.split(',')[0];
                        if(climaDescripcionEl) climaDescripcionEl.textContent = `Error: ${data.message || 'No se pudo obtener el clima.'}`;
                        return;
                    }
                    // Se actualizan los campos con los datos recibidos.
                    if(climaCiudadEl) climaCiudadEl.textContent = data.name;
                    if(climaIconoEl) climaIconoEl.innerHTML = `<img src="https://openweathermap.org/img/wn/${data.weather[0].icon}.png" alt="${data.weather[0].description}">`;
                    if(climaDescripcionEl) climaDescripcionEl.textContent = data.weather[0].description;
                    if(climaTempEl) climaTempEl.textContent = data.main.temp.toFixed(1);
                    if(climaHumedadEl) climaHumedadEl.textContent = data.main.humidity;
                })
                .catch(error => {
                    // Manejo de errores si la petición `fetch` falla por completo (ej. problemas de red).
                    console.error('Error al cargar datos del clima:', error);
                    if(climaCiudadEl) climaCiudadEl.textContent = ciudadParaClima.split(',')[0];
                    if(climaDescripcionEl) climaDescripcionEl.textContent = error.message.includes("API Clima:") || error.message.includes("Error HTTP:") ? error.message : "No se pudo cargar el clima.";
                });
        }
        
        // Se llama a la función para cargar el clima solo si la tarjeta del clima existe en la página.
        if(typeof cargarClima === 'function' && document.getElementById('clima-ciudad')){ 
            cargarClima();
        }
        // --- Fin del Bloque de Lógica del Clima ---
    });
    </script>
</body>
</html>