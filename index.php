<?php
// 1. Cargar librerías de Composer
require_once __DIR__ . '/vendor/autoload.php';
use chillerlan\QRCode\QRCode;

// 2. Obtener variables de entorno de Render
$host = getenv('TIDB_HOST') ?: '127.0.0.1';
$port = getenv('TIDB_PORT') ?: '4000';
$db   = getenv('TIDB_DB') ?: 'test';
$user = getenv('TIDB_USER') ?: 'root';
$pass = getenv('TIDB_PASS') ?: '';

// Detectar la URL base para el código QR dinámico
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

// 3. Conexión segura a TiDB Cloud usando tu archivo PEM
try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_SSL_CA       => __DIR__ . '/tidb-truststore.pem'
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// --- LÓGICA DE PROCESAMIENTO ---

$registro = null;
$qrImage = null;
$tab = $_GET['tab'] ?? 'crear'; // Manejo de pestañas del panel: 'crear' o 'lista'

// Caso Especial: El usuario escaneó el QR desde afuera (VISTA PÚBLICA PURA)
// Si viene un ID pero NO viene el parámetro de administración, es un cliente externo.
$esVistaPublica = (isset($_GET['id']) && !isset($_GET['admin']));

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM registros_qr WHERE id = ?");
    $stmt->execute([$id]);
    $registro = $stmt->fetch();
    
    if ($registro) {
        // La URL del QR apunta a la vista pública pura (sin el parámetro admin)
        $urlQr = $baseUrl . "?id=" . $registro['id'];
        $qrImage = (new QRCode)->render($urlQr);
    }
}

// Procesar el Formulario (Guardar / Modificar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = $_POST['titulo'] ?? '';
    $contenido = $_POST['contenido'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;

    if (!empty($titulo) && !empty($contenido)) {
        if ($id) {
            // Actualizar existente
            $stmt = $pdo->prepare("UPDATE registros_qr SET titulo = ?, contenido = ? WHERE id = ?");
            $stmt->execute([$titulo, $contenido, $id]);
            header("Location: index.php?id=" . $id . "&admin=1&tab=crear");
            exit;
        } else {
            // Crear nuevo
            $stmt = $pdo->prepare("INSERT INTO registros_qr (titulo, contenido) VALUES (?, ?)");
            $stmt->execute([$titulo, $contenido]);
            $nuevoId = $pdo->lastInsertId();
            header("Location: index.php?id=" . $nuevoId . "&admin=1&tab=crear");
            exit;
        }
    }
}

// Si la pestaña actual es la 'lista', obtenemos todos los registros de TiDB
$todosLosRegistros = [];
if ($tab === 'lista' && !$esVistaPublica) {
    $stmt = $pdo->query("SELECT * FROM registros_qr ORDER BY actualizado_el DESC");
    $todosLosRegistros = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ($esVistaPublica && $registro) ? htmlspecialchars($registro['titulo']) : 'Panel Administrador QR'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/water.css@2/out/water.css">
    <style>
        /* Estilos de las pestañas */
        .nav-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid var(--border); padding-bottom: 10px; }
        .nav-tabs a { text-decoration: none; padding: 8px 16px; border-radius: 4px; background: var(--background-alt); font-weight: bold; }
        .nav-tabs a.active { background: #0076ff; color: white; }
        
        /* Estilos de la vista del cliente (QR) */
        .vista-cliente { background: var(--background-alt); border-left: 6px solid #2ecc71; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-top: 20px; }
        .contenido-texto { white-space: pre-wrap; font-size: 1.15rem; line-height: 1.6; }
        .fecha { font-size: 0.85rem; color: #777; margin-top: 25px; border-top: 1px solid var(--border); padding-top: 10px; }
        
        /* Tabla del listado */
        table { width: 100%; margin-top: 10px; }
        .qr-mini { background: white; padding: 5px; border-radius: 4px; width: 60px; height: 60px; display: block; }
    </style>
</head>
<body>

    <!-- ==================== CASO 1: VISTA PÚBLICA DEL CLIENTE ==================== -->
    <?php if ($esVistaPublica): ?>
        <?php if ($registro): ?>
            <div class="vista-cliente">
                <h1><?php echo htmlspecialchars($registro['titulo']); ?></h1>
                <div class="contenido-texto"><?php echo htmlspecialchars($registro['contenido']); ?></div>
                <div class="fecha">📅 Actualizado el: <?php echo date('d/m/Y H:i', strtotime($registro['actualizado_el'])); ?></div>
            </div>
        <?php else: ?>
            <p style="color: red; text-align: center; margin-top: 50px;">⚠️ El código QR escaneado no pertenece a un registro válido.</p>
        <?php endif; ?>


    <!-- ==================== CASO 2: PANEL DE ADMINISTRACIÓN ==================== -->
    <?php else: ?>
        <h1>⚙️ Panel de Gestión QR Dinámico</h1>
        
        <!-- Menú de Pestañas Navegables -->
        <div class="nav-tabs">
            <a href="index.php?tab=crear" class="<?php echo $tab === 'crear' ? 'active' : ''; ?>">📥 Crear / Editar QR</a>
            <a href="index.php?tab=lista" class="<?php echo $tab === 'lista' ? 'active' : ''; ?>">📋 Ver Todos los Códigos</a>
        </div>

        <!-- CONTENIDO PESTAÑA A: CREAR O EDITAR -->
        <?php if ($tab === 'crear'): ?>
            <h2><?php echo $registro ? '📝 Modificar Registro #' . $registro['id'] : '✨ Generar Nueva Información'; ?></h2>
            
            <form action="index.php?tab=crear" method="POST">
                <?php if ($registro): ?>
                    <input type="hidden" name="id" value="<?php echo $registro['id']; ?>">
                <?php endif; ?>
                
                <label>Título:</label>
                <input type="text" name="titulo" required value="<?php echo $registro ? htmlspecialchars($registro['titulo']) : ''; ?>" placeholder="Ej: Menú de Almuerzos, Manual de Usuario...">
                
                <label>Información / Texto:</label>
                <textarea name="contenido" rows="6" required placeholder="Ingresa aquí los datos que el usuario leerá al escanear el QR..." ><?php echo $registro ? htmlspecialchars($registro['contenido']) : ''; ?></textarea>
                
                <button type="submit"><?php echo $registro ? 'Guardar Cambios' : 'Generar Registro y QR'; ?></button>
                <?php if ($registro): ?>
                    <a href="index.php?tab=crear" style="margin-left: 10px; color: #888;">Cancelar Edición</a>
                <?php endif; ?>
            </form>

            <!-- Cuadro de visualización inmediata del QR que se acaba de crear/editar -->
            <?php if ($registro && $qrImage): ?>
                <div style="text-align: center; margin-top: 35px; background: var(--background-alt); padding: 20px; border-radius: 8px;">
                    <h3>¡Código QR Listo!</h3>
                    <img src="<?php echo $qrImage; ?>" alt="QR" style="border: 4px solid white; background: white; padding: 5px; border-radius: 4px;">
                    <p><strong>Enlace público asignado:</strong><br>
                       <!-- target="_blank" asegura que se abra en una ventana/pestaña aparte -->
                       <a href="<?php echo $urlQr; ?>" target="_blank"><?php echo $urlQr; ?> ↗️</a>
                    </p>
                    <p style="font-size: 0.85rem; color: #666; max-width: 500px; margin: 0 auto;">
                        Este QR abrirá la información en una ventana limpia para el usuario. Puedes modificar el texto arriba cuantas veces quieras y los cambios se verán inmediatamente sin cambiar el QR.
                    </p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- CONTENIDO PESTAÑA B: LISTADO GLOBAL DE CÓDIGOS -->
        <?php if ($tab === 'lista'): ?>
            <h2>Historial de Códigos Generados</h2>
            <?php if (empty($todosLosRegistros)): ?>
                <p>No has generado ningún código QR todavía. ¡Ve a la pestaña "Crear" para empezar!</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Título</th>
                            <th>QR (Escanear / Probar)</th>
                            <th>Última Modificación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todosLosRegistros as $reg): 
                            // Generamos el QR temporal para renderizarlo en la tabla
                            $enlacePublico = $baseUrl . "?id=" . $reg['id'];
                            $qrCelda = (new QRCode)->render($enlacePublico);
                        ?>
                            <tr>
                                <td><strong>#<?php echo $reg['id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($reg['titulo']); ?></td>
                                <td>
                                    <!-- Al hacer clic en el QR de la tabla también se abre en otra pestaña limpia -->
                                    <a href="<?php echo $enlacePublico; ?>" target="_blank" title="Ver vista del cliente">
                                        <img src="<?php echo $qrCelda; ?>" class="qr-mini" alt="QR">
                                    </a>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($reg['actualizado_el'])); ?></td>
                                <td>
                                    <!-- Botón para mandar el registro a la pestaña de edición -->
                                    <a href="index.php?id=<?php echo $reg['id']; ?>&admin=1&tab=crear" style="color: #0076ff; font-weight: bold; text-decoration: none;">✏️ Editar Contenido</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>

</body>
</html>