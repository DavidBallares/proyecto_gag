<?php
// Inicia la sesión de PHP. Aunque no es vital para esta página, es una práctica estándar.
session_start();
// Establece la codificación de caracteres a UTF-8 para asegurar la correcta visualización de acentos y caracteres especiales.
header('Content-Type: text/html; charset=UTF-8');
// Incluye el archivo de conexión a la base de datos, que debe definir la variable $pdo.
require_once 'conexion.php';

// --- CABECERAS HTTP PARA EVITAR CACHÉ ---
// Estas cabeceras instruyen al navegador a no guardar una copia en caché de esta página.
// Es crucial para páginas de seguridad para asegurar que siempre se muestre el estado más reciente.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

// --- INICIALIZACIÓN DE VARIABLES ---
$mensaje = ''; // Almacenará los mensajes de feedback (éxito/error) para el usuario.
$token = isset($_GET['token']) ? trim($_GET['token']) : ''; // Obtiene y limpia el token de la URL.

// --- LÓGICA DE LA PÁGINA ---

// Bloque para manejar la solicitud GET: cuando el usuario llega a la página por primera vez a través del enlace del email.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($token)) {
    try {
        // Prepara una consulta para verificar si el token existe en la base de datos.
        $check = $pdo->prepare("SELECT `id_usuario`, `token_recuperacion`, `token_expiracion`, `token_usado` FROM `usuarios` WHERE `token_recuperacion` = :token");
        $check->bindParam(':token', $token, PDO::PARAM_STR);
        $check->execute();

        // Si se encuentra una fila, el token es potencialmente válido.
        if ($check->rowCount() > 0) {
            $user = $check->fetch(PDO::FETCH_ASSOC);
            // Crea objetos DateTime para comparar fácilmente la fecha de expiración con la actual.
            $expiracion = new DateTime($user['token_expiracion']);
            $now = new DateTime();

            // Comprueba si el token ya ha sido utilizado.
            if ($user['token_usado'] == 1) {
                $mensaje = "Este enlace ya fue utilizado. Solicita uno nuevo.";
            // Comprueba si la fecha de expiración es anterior a la fecha y hora actual.
            } elseif ($expiracion < $now) {
                $mensaje = "El enlace de recuperación ha expirado. Solicita uno nuevo.";
                // Medida de seguridad: invalida el token expirado en la base de datos para prevenir su uso futuro.
                $update = $pdo->prepare("UPDATE `usuarios` SET `token_recuperacion` = NULL, `token_expiracion` = NULL, `token_usado` = 1 WHERE `token_recuperacion` = :token");
                $update->bindParam(':token', $token, PDO::PARAM_STR);
                $update->execute();
            }
            // Si ninguna de las condiciones anteriores se cumple, el token es válido y se mostrará el formulario.
        } else {
            // Si no se encuentra ninguna fila con ese token.
            $mensaje = "Token inválido o no encontrado.";
        }
    } catch (PDOException $e) {
        $mensaje = "Error de base de datos: " . $e->getMessage();
    }
// Bloque para manejar la solicitud POST: cuando el usuario envía el formulario con la nueva contraseña.
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($token)) {
    // Validaciones de la nueva contraseña.
    if (!isset($_POST['new_password']) || empty(trim($_POST['new_password']))) {
        $mensaje = "Por favor, ingrese una nueva contraseña.";
    } elseif (strlen(trim($_POST['new_password'])) < 8) {
        $mensaje = "La contraseña debe tener al menos 8 caracteres.";
    } else {
        // Si la contraseña es válida, se hashea de forma segura antes de guardarla.
        $nueva_contrasena = password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT);

        try {
            // Vuelve a verificar que el token sea válido y no haya sido usado justo antes de actualizar.
            // Esto previene condiciones de carrera o que el token expire entre la carga de la página y el envío del formulario.
            $check = $pdo->prepare("SELECT `id_usuario` FROM `usuarios` WHERE `token_recuperacion` = :token AND `token_usado` = 0");
            $check->bindParam(':token', $token, PDO::PARAM_STR);
            $check->execute();

            if ($check->rowCount() > 0) {
                // Si el token es válido, actualiza la contraseña e invalida el token.
                $update = $pdo->prepare("UPDATE `usuarios` SET `contrasena` = :contrasena, `token_recuperacion` = NULL, `token_expiracion` = NULL, `token_usado` = 1 WHERE `token_recuperacion` = :token");
                $update->bindParam(':contrasena', $nueva_contrasena, PDO::PARAM_STR);
                $update->bindParam(':token', $token, PDO::PARAM_STR);

                if ($update->execute()) {
                    // Si la actualización es exitosa, muestra un mensaje y redirige al login después de 3 segundos.
                    $mensaje = "Contraseña actualizada exitosamente. Redirigiendo al login...";
                    header("Refresh: 3; url=../login.html");
                } else {
                    $mensaje = "Error al actualizar la contraseña.";
                }
            } else {
                $mensaje = "Token inválido, expirado o ya usado.";
            }
        } catch (PDOException $e) {
            $mensaje = "Error de base de datos: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Metaetiquetas para forzar al navegador a no usar la caché -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Cambiar Contraseña</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <style>
        /* Estilos CSS para dar formato a la página de cambio de contraseña */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { width: 50%; margin: 50px auto; background: #fff; padding: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); border-radius: 8px; }
        h1 { text-align: center; color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #555; }
        .form-group input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .form-group input[type="submit"] { background-color: #5cb85c; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .form-group input[type="submit"]:hover { background-color: #4cae4c; }
        .mensaje { padding: 10px; margin-bottom: 20px; border-radius: 4px; text-align: center; }
        .mensaje.exito { background-color: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; }
        .mensaje.error { background-color: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #337ab7; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Cambiar Contraseña</h1>

        <?php if (!empty($mensaje)): ?>
            <!-- Este bloque muestra el mensaje de feedback. La clase CSS cambia (error/exito) según el contenido del mensaje. -->
            <div class="mensaje <?php echo (stripos($mensaje, 'exitosamente') !== false) ? 'exito' : 'error'; ?>">
                <?php echo htmlspecialchars($mensaje); ?>
            </div>
            <!-- Si el error indica que se necesita un nuevo enlace, se muestra un link para solicitarlo. -->
            <?php if (stripos($mensaje, 'Solicita uno nuevo') !== false): ?>
                <p><a href="recovery.php">Solicita un nuevo enlace</a></p>
            <?php endif; ?>
        <?php elseif (!empty($token)): ?>
            <!-- El formulario para cambiar la contraseña solo se muestra si no hay mensajes de error y el token existe. -->
            <!-- El formulario envía los datos a la misma página (POST), incluyendo el token en la URL para que no se pierda. -->
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . '?token=' . urlencode($token); ?>" method="POST">
                <div class="form-group">
                    <label for="new_password">Nueva Contraseña:</label>
                    <input type="password" name="new_password" id="new_password" required placeholder="Ingrese nueva contraseña">
                    <p style="font-size: 0.9em; color: #666;">Mínimo 8 caracteres.</p>
                </div>
                <div class="form-group">
                    <input type="submit" value="Actualizar Contraseña">
                </div>
            </form>
        <?php endif; ?>

        <!-- Un enlace para volver a la página de login en cualquier caso. -->
        <a href="../pages/auth/login.html" class="back-link">Volver al login</a>
    </div>
</body>
</html>