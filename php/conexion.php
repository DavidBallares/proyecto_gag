<?php
// conexion.php
$host = 'localhost'; 
$db   = 'gag';
$user = 'root'; 
$pass = '';   
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {

throw new \PDOException("Error de conexión PDO en conexion.php: " . $e->getMessage(), (int)$e->getCode());
}
?>