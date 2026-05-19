<?php
// 1. Cargar librerías de Composer
require_once __DIR__ . '/vendor/autoload.php';
use chillerlan\QRCode\{QRCode, QROptions};

// 2. Obtener variables de entorno (Render las inyectará de forma segura)
$host = getenv('TIDB_HOST') ?: '127.0.0.1';
$port = getenv('TIDB_PORT') ?: '4000';
$db   = getenv('TIDB_DB') ?: 'test';
$user = getenv('TIDB_USER') ?: 'root';
$pass = getenv('TIDB_PASS') ?: '';

// Detectar la URL base para el código QR dinámico
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

// 3. Conexión segura a TiDB Cloud (Obligatorio usar SSL)
// Cambia la configuración del DSN y opciones para que se vea así:
try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Apuntamos directamente al archivo PEM que subiremos al servidor
        PDO::MYSQL_ATTR_SSL_CA       => __DIR__ . '/isrgrootx1.pem'
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// --- LÓGICA DE PROCESAMIENTO ---

$registro = null;
$qrImage = null;

// ACCIÓN A: Ver un registro específico (cuando escanean el QR o seleccionan uno)
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM registros_qr WHERE id = ?");
    $stmt->execute([$id]);
    $registro = $stmt->fetch();
    
    if ($registro) {
        // Generar el código QR dinámico que apunta a este mismo ID
        $urlQr = $baseUrl . "?id=" . $registro['id'];
        $qrImage = (new QRCode)->render($urlQr);
    }
}

// ACCIÓN B: Guardar nuevo o Modificar existente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = $_POST['titulo'] ?? '';
    $contenido = $_POST['contenido'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if (!empty($titulo) && !empty($contenido)) {
        if ($id) {
            // Actualizar existente
            $stmt = $pdo->prepare("UPDATE registros_qr SET titulo = ?, contenido = ? WHERE id = ?");
            $stmt->execute([$titulo, $contenido, $id]);
            header("Location: index.php?id=" . $id);
            exit;
        } else {
            // Crear nuevo
            $stmt = $pdo->prepare("INSERT INTO registros_qr (titulo, contenido) VALUES (?, ?)");
            $stmt->execute([$titulo, $contenido]);
            $nuevoId = $pdo->lastInsertId();
            header("Location: index.php?id=" . $nuevoId);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema QR Dinámico con TiDB</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/water.css@2/out/water.css">
</head>
<body>
    <h1>Generador de QR Dinámico</h1>
    <a href="index.php">➕ Crear Nuevo Registro</a>
    <hr>

    <?php if (isset($_GET['id']) && !$registro): ?>
        <p style="color: red;">⚠️ El registro no existe.</p>
    <?php endif; ?>

    <!-- FORMULARIO DE CREACIÓN / EDICIÓN -->
    <h2><?php echo $registro ? '📝 Editar Registro #' . $registro['id'] : '📥 Crear Nuevo Registro'; ?></h2>
    <form action="index.php" method="POST">
        <?php if ($registro): ?>
            <input type="hidden" name="id" value="<?php echo $registro['id']; ?>">
        <?php endif; ?>
        
        <label>Título:</label>
        <input type="text" name="titulo" required value="<?php echo $registro ? htmlspecialchars($registro['titulo']) : ''; ?>">
        
        <label>Contenido / Texto:</label>
        <textarea name="contenido" rows="5" required><?php echo $registro ? htmlspecialchars($registro['contenido']) : ''; ?></textarea>
        
        <button type="submit"><?php echo $registro ? 'Actualizar Datos' : 'Guardar y Generar QR'; ?></button>
    </form>

    <!-- VISUALIZACIÓN DEL QR GENERADO -->
    <?php if ($registro && $qrImage): ?>
        <hr>
        <div style="text-align: center; margin-top: 20px;">
            <h2>Código QR Generado</h2>
            <img src="<?php echo $qrImage; ?>" alt="Código QR" style="border: 1px solid #ccc; padding: 10px; background: white;">
            <p><strong>Enlace del QR:</strong> <a href="<?php echo $urlQr; ?>" target="_blank"><?php echo $urlQr; ?></a></p>
            <p><em>Escanea este código. Si modificas el texto de arriba y guardas, el QR seguirá siendo el mismo pero mostrará los nuevos cambios.</em></p>
        </div>
    <?php endif; ?>
</body>
</html>