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
    <title><?php echo $registro ? htmlspecialchars($registro['titulo']) : 'Sistema QR'; ?></title>
    <!-- Usamos un framework CSS minimalista y limpio -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/water.css@2/out/water.css">
    <style>
        .vista-publica {
            background: var(--background-alt);
            border-left: 5px solid #0076ff;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .contenido-texto {
            white-space: pre-wrap; /* Respeta los saltos de línea del usuario */
            font-size: 1.1rem;
            line-height: 1.6;
        }
        .meta-fecha {
            font-size: 0.85rem;
            color: #888;
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .badge-qr {
            display: inline-block;
            background: #e1f5fe;
            color: #0288d1;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

    <!-- CASO 1: VISTA PÚBLICA (El usuario escaneó el QR desde el celular) -->
    <!-- Detectamos si se pasó un ID por la URL y si no hay intenciones de editar en POST -->
    <?php if (isset($_GET['id']) && $registro && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        
        <div class="vista-publica">
            <span class="badge-qr">📱 Información Escaneada</span>
            <h1><?php echo htmlspecialchars($registro['titulo']); ?></h1>
            
            <div class="contenido-texto">
                <?php echo htmlspecialchars($registro['contenido']); ?>
            </div>
            
            <div class="meta-fecha">
                📅 <strong>Última actualización:</strong> <?php echo date('d/m/Y H:i', strtotime($registro['actualizado_el'])); ?>
            </div>
        </div>

        <p style="text-align: center; margin-top: 40px;">
            <a href="index.php?id=<?php echo $registro['id']; ?>&admin=1" style="font-size: 0.85rem; color: #888;">🔧 Administrar este registro</a>
        </p>

    <!-- CASO 2: VISTA DE ADMINISTRACIÓN (Formulario para crear o editar) -->
    <!-- Se muestra si no hay ID en la URL, o si explícitamente se pide el modo administrador (?admin=1) -->
    <?php else: ?>

        <h1>Panel de Control - QR Dinámico</h1>
        <p><a href="index.php">➕ Crear Nuevo Registro Completo</a></p>
        <hr>

        <?php if (isset($_GET['id']) && !$registro): ?>
            <p style="color: red;">⚠️ El registro solicitado no existe.</p>
        <?php endif; ?>

        <h2><?php echo $registro ? '📝 Editar Registro #' . $registro['id'] : '📥 Crear Nuevo Registro'; ?></h2>
        
        <form action="index.php" method="POST">
            <?php if ($registro): ?>
                <input type="hidden" name="id" value="<?php echo $registro['id']; ?>">
            <?php endif; ?>
            
            <label>Título del documento:</label>
            <input type="text" name="titulo" required value="<?php echo $registro ? htmlspecialchars($registro['titulo']) : ''; ?>" placeholder="Ej: Menú del día, Ficha Técnica, Instrucciones...">
            
            <label>Contenido / Texto a mostrar:</label>
            <textarea name="contenido" rows="8" required placeholder="Escribe aquí toda la información que verá el usuario al escanear el QR..." ><?php echo $registro ? htmlspecialchars($registro['contenido']) : ''; ?></textarea>
            
            <button type="submit"><?php echo $registro ? 'Guardar Cambios Actualizados' : 'Guardar y Generar QR'; ?></button>
        </form>

        <!-- PANEL DEL QR (Solo visible en modo edición/administración) -->
        <?php if ($registro && $qrImage): ?>
            <hr>
            <div style="text-align: center; margin-top: 20px; background: var(--background-alt); padding: 20px; border-radius: 8px;">
                <h2>Tu Código QR Dinámico</h2>
                <img src="<?php echo $qrImage; ?>" alt="Código QR" style="border: 4px solid white; padding: 10px; background: white; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <p><strong>Enlace directo:</strong> <a href="<?php echo $baseUrl . "?id=" . $registro['id']; ?>" target="_blank"><?php echo $baseUrl . "?id=" . $registro['id']; ?></a></p>
                <p style="font-size: 0.9rem; max-width: 500px; margin: 0 auto; color: #666;">
                    Imprime este QR o colócalo donde quieras. Cuando la gente lo escanee, verá la información limpia. Si cambias el texto desde este panel, el código QR de arriba seguirá funcionando perfectamente mostrando el nuevo contenido.
                </p>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</body>
</html>