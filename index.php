<?php
session_start();

// ── CONFIGURACIÓN DE BASE DE DATOS ──────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'direccion_deportes');

// Carpeta donde se guardarán las imágenes subidas
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');

function getDB(): mysqli
{
  static $db = null;
  if ($db === null) {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
    if ($db->connect_error) {
      die(json_encode(['error' => 'Error de conexión: ' . $db->connect_error]));
    }
  }
  return $db;
}

// ── HELPERS ──────────────────────────────────────────────────
function isLoggedIn(): bool
{
  return isset($_SESSION['usuario']);
}
function isAdmin(): bool
{
  return isLoggedIn() && ($_SESSION['usuario']['rol'] ?? '') === 'admin';
}
function currentUser(): array
{
  return $_SESSION['usuario'] ?? [];
}
function jsonOut(array $data): void
{
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}
function redirect(string $url): void
{
  header("Location: $url");
  exit;
}

// ── SUBIR IMAGEN ─────────────────────────────────────────────
function subirImagen(string $field): string
{
  if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
  $file = $_FILES[$field];
  $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  if (!in_array($ext, $allowed)) return '';
  if ($file['size'] > 5 * 1024 * 1024) return '';
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
  $nombre = uniqid('img_', true) . '.' . $ext;
  move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $nombre);
  return UPLOAD_URL . $nombre;
}

// ── ACCIONES AJAX / POST ─────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── LOGIN ──
if ($action === 'login') {
  $correo = trim($_POST['correo'] ?? '');
  $pass   = $_POST['contrasena'] ?? '';
  $db = getDB();
  $stmt = $db->prepare("SELECT * FROM Usuario WHERE correo = ? LIMIT 1");
  $stmt->bind_param('s', $correo);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if ($row && password_verify($pass, $row['contraseña'])) {
    $_SESSION['usuario'] = $row;
    jsonOut(['ok' => true, 'rol' => $row['rol'], 'nombre' => $row['nombre']]);
  } else {
    jsonOut(['ok' => false, 'msg' => 'Correo o contraseña incorrectos']);
  }
}

// ── REGISTRO (siempre crea como 'usuario') ──
if ($action === 'registro') {
  $nombre   = trim($_POST['nombre'] ?? '');
  $correo   = trim($_POST['correo'] ?? '');
  $pass     = $_POST['contrasena'] ?? '';
  $noCtrl   = trim($_POST['no_control'] ?? '');
  $rfc      = strtoupper(trim($_POST['rfc'] ?? ''));
  $curp     = strtoupper(trim($_POST['curp'] ?? ''));
  if (!$nombre || !$correo || !$pass) jsonOut(['ok' => false, 'msg' => 'Completa todos los campos']);
  $hash = password_hash($pass, PASSWORD_DEFAULT);
  $db = getDB();
  $rol = 'usuario'; // Siempre usuario por defecto
  $stmt = $db->prepare("INSERT INTO Usuario (nombre,correo,contraseña,rol,no_control,rfc,curp) VALUES (?,?,?,?,?,?,?)");
  if (!$stmt) {
    // Fallback si las columnas nuevas no existen aún
    $stmt = $db->prepare("INSERT INTO Usuario (nombre,correo,contraseña,rol) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $nombre, $correo, $hash, $rol);
  } else {
    $stmt->bind_param('sssssss', $nombre, $correo, $hash, $rol, $noCtrl, $rfc, $curp);
  }
  if ($stmt->execute()) {
    $id = $db->insert_id;
    $_SESSION['usuario'] = [
      'id_usuario' => $id,
      'nombre' => $nombre,
      'correo' => $correo,
      'rol' => $rol,
      'no_control' => $noCtrl,
      'rfc' => $rfc,
      'curp' => $curp
    ];
    jsonOut(['ok' => true, 'rol' => $rol, 'nombre' => $nombre]);
  } else {
    jsonOut(['ok' => false, 'msg' => 'El correo ya está registrado']);
  }
}

// ── LOGOUT ──
if ($action === 'logout') {
  session_destroy();
  redirect('index.php');
}

// ── API: DATOS PARA SECCIÓN PÚBLICA ──
if ($action === 'get_eventos') {
  $db = getDB();
  $rows = $db->query("SELECT id_evento,nombre,descripcion,fecha,lugar,Imagen_url FROM Evento ORDER BY fecha DESC LIMIT 20");
  $data = [];
  while ($r = $rows->fetch_assoc()) $data[] = $r;
  jsonOut($data);
}

if ($action === 'get_cursos') {
  $db = getDB();
  $rows = $db->query("SELECT id_curso,nombre,descripcion,fecha_inicio,fecha_fin,Requisitos,costo FROM Curso ORDER BY fecha_inicio DESC LIMIT 20");
  $data = [];
  while ($r = $rows->fetch_assoc()) $data[] = $r;
  jsonOut($data);
}

if ($action === 'get_contenido') {
  $db = getDB();
  $rows = $db->query("SELECT c.*,u.nombre as autor FROM Contenido c LEFT JOIN Usuario u ON c.id_usuario=u.id_usuario ORDER BY c.fecha DESC LIMIT 20");
  $data = [];
  while ($r = $rows->fetch_assoc()) $data[] = $r;
  jsonOut($data);
}

// ── API: MIS INSCRIPCIONES ──
if ($action === 'mis_inscripciones' && isLoggedIn()) {
  $db = getDB();
  $uid = currentUser()['id_usuario'];
  $stmt = $db->prepare("SELECT i.id_inscripcion, i.fecha_inscripcion, i.estado, c.id_curso, c.nombre, c.descripcion, c.fecha_inicio, c.fecha_fin, c.costo FROM Inscripcion i JOIN Curso c ON i.id_curso=c.id_curso WHERE i.id_usuario=?");
  if (!$stmt) jsonOut([]);
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $data = [];
  while ($r = $stmt->get_result()->fetch_assoc()) $data[] = $r;
  jsonOut($data);
}

// ── API: INSCRIBIRSE A CURSO ──
if ($action === 'inscribir_curso' && isLoggedIn()) {
  $db = getDB();
  $uid    = currentUser()['id_usuario'];
  $id_curso = (int)($_POST['id_curso'] ?? 0);
  // Verificar duplicado
  $chk = $db->prepare("SELECT id_inscripcion FROM Inscripcion WHERE id_usuario=? AND id_curso=?");
  $chk->bind_param('ii', $uid, $id_curso);
  $chk->execute();
  if ($chk->get_result()->num_rows > 0) jsonOut(['ok' => false, 'msg' => 'Ya estás inscrito en este curso']);
  $stmt = $db->prepare("INSERT INTO Inscripcion (id_usuario, id_curso, fecha_inscripcion, estado) VALUES (?,?,NOW(),'pendiente')");
  if (!$stmt) jsonOut(['ok' => false, 'msg' => 'La tabla Inscripcion no existe. Ejecuta el SQL de instalación.']);
  $stmt->bind_param('ii', $uid, $id_curso);
  jsonOut(['ok' => $stmt->execute(), 'id' => $db->insert_id]);
}

// ── API: CANCELAR INSCRIPCION ──
if ($action === 'cancelar_inscripcion' && isLoggedIn()) {
  $db = getDB();
  $uid = currentUser()['id_usuario'];
  $id  = (int)($_POST['id'] ?? 0);
  $stmt = $db->prepare("DELETE FROM Inscripcion WHERE id_inscripcion=? AND id_usuario=?");
  $stmt->bind_param('ii', $id, $uid);
  jsonOut(['ok' => $stmt->execute()]);
}

// ── API: DATOS USUARIO PARA TICKET ──
if ($action === 'datos_ticket' && isLoggedIn()) {
  $db  = getDB();
  $uid = currentUser()['id_usuario'];
  $id_inscripcion = (int)($_POST['id_inscripcion'] ?? 0);
  $stmt = $db->prepare("SELECT u.nombre, u.correo, u.rfc, u.curp, u.no_control, i.id_inscripcion, i.fecha_inscripcion, c.nombre as curso_nombre, c.costo, c.fecha_inicio, c.fecha_fin FROM Inscripcion i JOIN Usuario u ON i.id_usuario=u.id_usuario JOIN Curso c ON i.id_curso=c.id_curso WHERE i.id_inscripcion=? AND i.id_usuario=?");
  $stmt->bind_param('ii', $id_inscripcion, $uid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if ($row) jsonOut(['ok' => true, 'datos' => $row]);
  else jsonOut(['ok' => false, 'msg' => 'No encontrado']);
}

// ── API ADMIN: CRUD ──
if ($action === 'crear_evento' && isAdmin()) {
  $db = getDB();
  $nombre = trim($_POST['nombre'] ?? '');
  $desc   = trim($_POST['descripcion'] ?? '');
  $fecha  = $_POST['fecha'] ?? '';
  $lugar  = trim($_POST['lugar'] ?? '');
  $uid    = currentUser()['id_usuario'];
  $img    = subirImagen('imagen_file');
  if (!$img) $img = trim($_POST['imagen_url'] ?? '');
  $stmt = $db->prepare("INSERT INTO Evento (nombre,descripcion,fecha,lugar,id_usuario,Imagen_url) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('ssssis', $nombre, $desc, $fecha, $lugar, $uid, $img);
  jsonOut(['ok' => $stmt->execute(), 'id' => $db->insert_id]);
}

if ($action === 'editar_evento' && isAdmin()) {
  $db = getDB();
  $id    = (int)($_POST['id'] ?? 0);
  $nombre = trim($_POST['nombre'] ?? '');
  $desc   = trim($_POST['descripcion'] ?? '');
  $fecha  = $_POST['fecha'] ?? '';
  $lugar  = trim($_POST['lugar'] ?? '');
  $img    = subirImagen('imagen_file');
  if (!$img) $img = trim($_POST['imagen_url'] ?? '');
  $stmt = $db->prepare("UPDATE Evento SET nombre=?,descripcion=?,fecha=?,lugar=?,Imagen_url=? WHERE id_evento=?");
  $stmt->bind_param('sssssi', $nombre, $desc, $fecha, $lugar, $img, $id);
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'eliminar_evento' && isAdmin()) {
  $db = getDB();
  $id = (int)($_POST['id'] ?? 0);
  $stmt = $db->prepare("DELETE FROM Evento WHERE id_evento=?");
  $stmt->bind_param('i', $id);
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'crear_curso' && isAdmin()) {
  $db = getDB();
  $nombre = trim($_POST['nombre'] ?? '');
  $desc   = trim($_POST['descripcion'] ?? '');
  $fi     = $_POST['fecha_inicio'] ?? '';
  $ff     = $_POST['fecha_fin'] ?? '';
  $req    = trim($_POST['requisitos'] ?? '');
  $costo  = floatval($_POST['costo'] ?? 0);
  $uid    = currentUser()['id_usuario'];
  $stmt = $db->prepare("INSERT INTO Curso (nombre,descripcion,fecha_inicio,fecha_fin,id_usuario,Requisitos,costo) VALUES (?,?,?,?,?,?,?)");
  if (!$stmt) {
    $stmt = $db->prepare("INSERT INTO Curso (nombre,descripcion,fecha_inicio,fecha_fin,id_usuario,Requisitos) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('sssssi', $nombre, $desc, $fi, $ff, $uid, $req);
  } else {
    $stmt->bind_param('ssssisd', $nombre, $desc, $fi, $ff, $uid, $req, $costo);
  }
  jsonOut(['ok' => $stmt->execute(), 'id' => $db->insert_id]);
}

if ($action === 'editar_curso' && isAdmin()) {
  $db = getDB();
  $id   = (int)($_POST['id'] ?? 0);
  $nombre = trim($_POST['nombre'] ?? '');
  $desc   = trim($_POST['descripcion'] ?? '');
  $fi     = $_POST['fecha_inicio'] ?? '';
  $ff     = $_POST['fecha_fin'] ?? '';
  $req    = trim($_POST['requisitos'] ?? '');
  $costo  = floatval($_POST['costo'] ?? 0);
  $stmt = $db->prepare("UPDATE Curso SET nombre=?,descripcion=?,fecha_inicio=?,fecha_fin=?,Requisitos=?,costo=? WHERE id_curso=?");
  if (!$stmt) {
    $stmt = $db->prepare("UPDATE Curso SET nombre=?,descripcion=?,fecha_inicio=?,fecha_fin=?,Requisitos=? WHERE id_curso=?");
    $stmt->bind_param('sssssi', $nombre, $desc, $fi, $ff, $req, $id);
  } else {
    $stmt->bind_param('sssssdi', $nombre, $desc, $fi, $ff, $req, $costo, $id);
  }
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'eliminar_curso' && isAdmin()) {
  $db = getDB();
  $id = (int)($_POST['id'] ?? 0);
  $stmt = $db->prepare("DELETE FROM Curso WHERE id_curso=?");
  $stmt->bind_param('i', $id);
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'crear_contenido' && isAdmin()) {
  $db = getDB();
  $titulo = trim($_POST['titulo'] ?? '');
  $desc   = trim($_POST['descripcion'] ?? '');
  $tipo   = trim($_POST['tipo'] ?? 'noticia');
  $fecha  = $_POST['fecha'] ?? date('Y-m-d');
  $uid    = currentUser()['id_usuario'];
  $img    = subirImagen('imagen_file');
  if (!$img) $img = trim($_POST['url'] ?? '');
  $stmt = $db->prepare("INSERT INTO Contenido (titulo,descripcion,tipo,url,fecha,id_usuario) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('sssssi', $titulo, $desc, $tipo, $img, $fecha, $uid);
  jsonOut(['ok' => $stmt->execute(), 'id' => $db->insert_id]);
}

if ($action === 'editar_contenido' && isAdmin()) {
  $db = getDB();
  $id    = (int)($_POST['id'] ?? 0);
  $titulo = trim($_POST['titulo'] ?? '');
  $desc   = trim($_POST['descripcion'] ?? '');
  $tipo   = trim($_POST['tipo'] ?? 'noticia');
  $fecha  = $_POST['fecha'] ?? date('Y-m-d');
  $img    = subirImagen('imagen_file');
  if (!$img) $img = trim($_POST['url'] ?? '');
  $stmt = $db->prepare("UPDATE Contenido SET titulo=?,descripcion=?,tipo=?,url=?,fecha=? WHERE id_contenido=?");
  $stmt->bind_param('sssssi', $titulo, $desc, $tipo, $img, $fecha, $id);
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'eliminar_contenido' && isAdmin()) {
  $db = getDB();
  $id = (int)($_POST['id'] ?? 0);
  $stmt = $db->prepare("DELETE FROM Contenido WHERE id_contenido=?");
  $stmt->bind_param('i', $id);
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'get_usuarios' && isAdmin()) {
  $db = getDB();
  $rows = $db->query("SELECT id_usuario,nombre,correo,rol FROM Usuario ORDER BY id_usuario");
  $data = [];
  while ($r = $rows->fetch_assoc()) $data[] = $r;
  jsonOut($data);
}

if ($action === 'cambiar_rol' && isAdmin()) {
  $db = getDB();
  $id  = (int)($_POST['id'] ?? 0);
  $rol = trim($_POST['rol'] ?? 'usuario');
  $stmt = $db->prepare("UPDATE Usuario SET rol=? WHERE id_usuario=?");
  $stmt->bind_param('si', $rol, $id);
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'eliminar_usuario' && isAdmin()) {
  $db = getDB();
  $id = (int)($_POST['id'] ?? 0);
  $stmt = $db->prepare("DELETE FROM Usuario WHERE id_usuario=?");
  $stmt->bind_param('i', $id);
  jsonOut(['ok' => $stmt->execute()]);
}

// ── ADMIN: INSCRIPCIONES ──
if ($action === 'get_inscripciones' && isAdmin()) {
  $db = getDB();
  $stmt = $db->query("SELECT i.id_inscripcion, i.fecha_inscripcion, i.estado, u.nombre as usuario, u.correo, c.nombre as curso FROM Inscripcion i JOIN Usuario u ON i.id_usuario=u.id_usuario JOIN Curso c ON i.id_curso=c.id_curso ORDER BY i.fecha_inscripcion DESC");
  $data = [];
  while ($r = $stmt->fetch_assoc()) $data[] = $r;
  jsonOut($data);
}

if ($action === 'cambiar_estado_inscripcion' && isAdmin()) {
  $db = getDB();
  $id     = (int)($_POST['id'] ?? 0);
  $estado = trim($_POST['estado'] ?? 'pendiente');
  $stmt = $db->prepare("UPDATE Inscripcion SET estado=? WHERE id_inscripcion=?");
  $stmt->bind_param('si', $estado, $id);
  jsonOut(['ok' => $stmt->execute()]);
}

if ($action === 'stats' && isAdmin()) {
  $db = getDB();
  $ev  = $db->query("SELECT COUNT(*) c FROM Evento")->fetch_assoc()['c'];
  $cu  = $db->query("SELECT COUNT(*) c FROM Curso")->fetch_assoc()['c'];
  $co  = $db->query("SELECT COUNT(*) c FROM Contenido")->fetch_assoc()['c'];
  $us  = $db->query("SELECT COUNT(*) c FROM Usuario")->fetch_assoc()['c'];
  $ins = @$db->query("SELECT COUNT(*) c FROM Inscripcion")->fetch_assoc()['c'] ?? 0;
  jsonOut(['eventos' => $ev, 'cursos' => $cu, 'contenido' => $co, 'usuarios' => $us, 'inscripciones' => $ins]);
}

// Variables para la vista
$user    = currentUser();
$isAdmin = isAdmin();
$isLogged = isLoggedIn();
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Plataforma de Deportes</title>
  <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800;900&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --blue: #1a56ff;
      --blue-soft: #e8effe;
      --blue-mid: #c0d0ff;
      --orange: #ff5a1f;
      --orange-soft: #fff0eb;
      --green: #10b981;
      --green-soft: #e0faf2;
      --red: #ef4444;
      --red-soft: #fef2f2;
      --gold: #f59e0b;
      --bg: #f0f4ff;
      --bg2: #e6ecf8;
      --surface: #ffffff;
      --surface2: #f8faff;
      --surface3: #eef2fb;
      --text1: #0d1b2a;
      --text2: #4a6080;
      --text3: #8fa3bc;
      --border: #dde5f4;
      --border2: #c8d5ea;
      --sidebar-w: 270px;
      --r: 16px;
      --r-sm: 10px;
      --r-lg: 22px;
      --shadow-sm: 0 2px 8px rgba(26, 86, 255, .07);
      --shadow-md: 0 8px 30px rgba(26, 86, 255, .12);
      --shadow-lg: 0 20px 60px rgba(26, 86, 255, .15);
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    html {
      scroll-behavior: smooth
    }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text1);
      overflow-x: hidden;
      min-height: 100vh
    }

    ::-webkit-scrollbar {
      width: 6px
    }

    ::-webkit-scrollbar-track {
      background: var(--bg)
    }

    ::-webkit-scrollbar-thumb {
      background: var(--border2);
      border-radius: 10px
    }

    /* ── AUTH ── */
    .auth-overlay {
      position: fixed;
      inset: 0;
      background: linear-gradient(135deg, #0d1b2a 0%, #1a2f4a 45%, #0e2040 100%);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center
    }

    .auth-overlay.hidden {
      display: none
    }

    .auth-box {
      background: var(--surface);
      border-radius: var(--r-lg);
      padding: 40px 44px;
      width: min(460px, 92vw);
      box-shadow: 0 32px 80px rgba(13, 27, 42, .4);
      position: relative;
      max-height: 92vh;
      overflow-y: auto
    }

    .auth-logo {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 28px
    }

    .auth-logo .logo-mark {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--blue), #4f78ff);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 24px;
      font-weight: 900;
      color: #fff;
      box-shadow: 0 4px 16px rgba(26, 86, 255, .35)
    }

    .auth-logo .logo-name {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 26px;
      font-weight: 900;
      letter-spacing: 3px
    }

    .auth-logo .logo-sub {
      font-size: 10px;
      color: var(--text3);
      letter-spacing: 2px;
      text-transform: uppercase
    }

    .auth-title {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 28px;
      font-weight: 900;
      letter-spacing: 1px;
      margin-bottom: 6px
    }

    .auth-sub {
      font-size: 13px;
      color: var(--text3);
      margin-bottom: 26px
    }

    .auth-form {
      display: flex;
      flex-direction: column;
      gap: 14px
    }

    .auth-label {
      font-size: 12px;
      font-weight: 700;
      color: var(--text2);
      letter-spacing: .5px;
      margin-bottom: 4px;
      display: block
    }

    .auth-input {
      width: 100%;
      padding: 12px 16px;
      border: 1.5px solid var(--border);
      border-radius: var(--r-sm);
      font-family: 'DM Sans', sans-serif;
      font-size: 14px;
      color: var(--text1);
      background: var(--surface2);
      outline: none;
      transition: all .2s
    }

    .auth-input:focus {
      border-color: var(--blue-mid);
      box-shadow: 0 0 0 3px rgba(26, 86, 255, .1)
    }

    .auth-btn {
      padding: 14px;
      border: none;
      border-radius: var(--r-sm);
      background: var(--blue);
      color: #fff;
      font-family: 'DM Sans', sans-serif;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: all .25s;
      box-shadow: 0 4px 20px rgba(26, 86, 255, .35);
      margin-top: 4px
    }

    .auth-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 28px rgba(26, 86, 255, .5)
    }

    .auth-switch {
      text-align: center;
      margin-top: 16px;
      font-size: 13px;
      color: var(--text2)
    }

    .auth-switch a {
      color: var(--blue);
      font-weight: 700;
      cursor: pointer;
      text-decoration: none
    }

    .auth-error {
      background: var(--red-soft);
      color: var(--red);
      border: 1px solid #fca5a5;
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 13px;
      display: none
    }

    .auth-error.show {
      display: block
    }

    /* ── LAYOUT ── */
    .app-shell {
      display: flex;
      min-height: 100vh
    }

    .sidebar {
      width: var(--sidebar-w);
      background: var(--surface);
      border-right: 1.5px solid var(--border);
      display: flex;
      flex-direction: column;
      position: fixed;
      left: 0;
      top: 0;
      height: 100vh;
      z-index: 100;
      transition: width .35s cubic-bezier(.4, 0, .2, 1);
      overflow: hidden;
      box-shadow: 4px 0 24px rgba(26, 86, 255, .06)
    }

    .sidebar.collapsed {
      width: 72px
    }

    .sidebar-logo {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 22px 18px 20px;
      border-bottom: 1.5px solid var(--border);
      white-space: nowrap
    }

    .logo-mark {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      flex-shrink: 0;
      background: linear-gradient(135deg, var(--blue), #4f78ff);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 22px;
      font-weight: 900;
      color: #fff;
      box-shadow: 0 4px 16px rgba(26, 86, 255, .35);
      letter-spacing: 1px
    }

    .logo-text-wrap {
      overflow: hidden;
      transition: opacity .2s
    }

    .logo-name {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 24px;
      font-weight: 900;
      letter-spacing: 3px;
      color: var(--text1)
    }

    .logo-sub {
      font-size: 10px;
      color: var(--text3);
      letter-spacing: 2px;
      text-transform: uppercase
    }

    .collapse-btn {
      margin-left: auto;
      width: 28px;
      height: 28px;
      border-radius: 8px;
      background: var(--surface3);
      border: 1.5px solid var(--border);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      color: var(--text3);
      flex-shrink: 0;
      transition: all .2s
    }

    .collapse-btn:hover {
      background: var(--blue-soft);
      color: var(--blue);
      border-color: var(--blue-mid)
    }

    .sidebar.collapsed .logo-text-wrap,
    .sidebar.collapsed .nav-label,
    .sidebar.collapsed .sec-title,
    .sidebar.collapsed .sb-foot-info {
      opacity: 0;
      pointer-events: none
    }

    .sec-title {
      font-size: 10px;
      font-weight: 700;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      color: var(--text3);
      padding: 18px 18px 6px;
      white-space: nowrap;
      transition: opacity .2s
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 16px;
      margin: 2px 10px;
      border-radius: var(--r-sm);
      cursor: pointer;
      white-space: nowrap;
      transition: all .2s;
      position: relative
    }

    .nav-item:hover {
      background: var(--blue-soft)
    }

    .nav-item.active {
      background: linear-gradient(90deg, var(--blue-soft), #dce8ff)
    }

    .nav-item.active .nav-icon {
      color: var(--blue)
    }

    .nav-item.active .nav-label {
      color: var(--blue);
      font-weight: 700
    }

    .nav-item.active::before {
      content: '';
      position: absolute;
      left: -10px;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 22px;
      background: var(--blue);
      border-radius: 0 4px 4px 0;
      box-shadow: 2px 0 8px rgba(26, 86, 255, .4)
    }

    .nav-icon {
      font-size: 18px;
      width: 22px;
      text-align: center;
      flex-shrink: 0;
      color: var(--text3)
    }

    .nav-label {
      font-size: 14px;
      font-weight: 500;
      color: var(--text2);
      transition: opacity .2s
    }

    .sidebar-footer {
      margin-top: auto;
      padding: 14px;
      border-top: 1.5px solid var(--border);
      display: flex;
      align-items: center;
      gap: 10px
    }

    .sb-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      flex-shrink: 0;
      background: linear-gradient(135deg, var(--blue), #4f78ff);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 14px;
      color: #fff;
      box-shadow: 0 3px 10px rgba(26, 86, 255, .3);
      text-transform: uppercase
    }

    .sb-foot-info {
      overflow: hidden;
      transition: opacity .2s
    }

    .sb-name {
      font-weight: 700;
      font-size: 13px
    }

    .sb-role {
      font-size: 11px;
      color: var(--text3)
    }

    .sb-settings {
      margin-left: auto;
      font-size: 16px;
      cursor: pointer;
      color: var(--text3);
      flex-shrink: 0;
      transition: color .2s
    }

    .sb-settings:hover {
      color: var(--blue)
    }

    /* ── MAIN ── */
    .main {
      margin-left: var(--sidebar-w);
      flex: 1;
      min-height: 100vh;
      transition: margin-left .35s cubic-bezier(.4, 0, .2, 1)
    }

    .main.collapsed {
      margin-left: 72px
    }

    .topbar {
      position: sticky;
      top: 0;
      z-index: 50;
      background: rgba(240, 244, 255, .9);
      backdrop-filter: blur(20px);
      border-bottom: 1.5px solid var(--border);
      padding: 14px 32px;
      display: flex;
      align-items: center;
      gap: 16px
    }

    .topbar-title {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 22px;
      font-weight: 800;
      letter-spacing: 1.5px
    }

    .topbar-sub {
      font-size: 12px;
      color: var(--text3);
      margin-top: 1px
    }

    .topbar-right {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 10px
    }

    .search-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: 30px;
      padding: 8px 16px;
      transition: all .2s;
      box-shadow: var(--shadow-sm)
    }

    .search-wrap:focus-within {
      border-color: var(--blue-mid);
      box-shadow: 0 0 0 3px rgba(26, 86, 255, .1)
    }

    .search-wrap input {
      background: none;
      border: none;
      outline: none;
      font-family: 'DM Sans', sans-serif;
      font-size: 13px;
      color: var(--text1);
      width: 180px
    }

    .search-wrap input::placeholder {
      color: var(--text3)
    }

    .logout-btn {
      padding: 8px 16px;
      border-radius: 30px;
      background: var(--red-soft);
      color: var(--red);
      border: 1.5px solid #fca5a5;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      transition: all .2s
    }

    .logout-btn:hover {
      background: var(--red);
      color: #fff
    }

    /* ── PAGES ── */
    .page {
      display: none
    }

    .page.active {
      display: block
    }

    .content {
      padding: 28px 32px 80px;
      max-width: 1440px
    }

    /* ── HERO ── */
    .hero {
      background: linear-gradient(135deg, #0d1b2a 0%, #1a2f4a 45%, #0e2040 100%);
      border-radius: var(--r-lg);
      padding: 44px 48px;
      margin-bottom: 28px;
      position: relative;
      overflow: hidden
    }

    .hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(ellipse 70% 80% at 85% 50%, rgba(26, 86, 255, .25) 0%, transparent 60%)
    }

    .hero-grid {
      position: absolute;
      inset: 0;
      opacity: .04;
      background-image: linear-gradient(rgba(255, 255, 255, 1) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 1) 1px, transparent 1px);
      background-size: 50px 50px
    }

    .hero-content {
      position: relative;
      z-index: 1
    }

    .hero-chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: #60a5fa;
      background: rgba(96, 165, 250, .1);
      border: 1px solid rgba(96, 165, 250, .2);
      border-radius: 20px;
      padding: 5px 14px;
      margin-bottom: 18px
    }

    .hero-h {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: clamp(38px, 5vw, 68px);
      font-weight: 900;
      line-height: .95;
      letter-spacing: 1px;
      color: #fff;
      margin-bottom: 14px
    }

    .hero-h em {
      color: #60a5fa;
      font-style: normal
    }

    .hero-p {
      font-size: 15px;
      color: rgba(255, 255, 255, .6);
      max-width: 420px;
      line-height: 1.7;
      margin-bottom: 24px
    }

    .hero-btns {
      display: flex;
      gap: 12px;
      flex-wrap: wrap
    }

    .btn-hero {
      font-family: 'DM Sans', sans-serif;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: .5px;
      border-radius: 30px;
      padding: 12px 26px;
      border: none;
      cursor: pointer;
      transition: all .25s
    }

    .btn-hero-primary {
      background: var(--blue);
      color: #fff;
      box-shadow: 0 4px 20px rgba(26, 86, 255, .4)
    }

    .btn-hero-primary:hover {
      box-shadow: 0 6px 28px rgba(26, 86, 255, .55);
      transform: translateY(-1px)
    }

    .btn-hero-ghost {
      background: rgba(255, 255, 255, .1);
      color: #fff;
      border: 1.5px solid rgba(255, 255, 255, .2)
    }

    .btn-hero-ghost:hover {
      background: rgba(255, 255, 255, .18)
    }

    .hero-kpis {
      display: flex;
      gap: 32px;
      padding-top: 28px;
      border-top: 1px solid rgba(255, 255, 255, .1);
      margin-top: 28px;
      flex-wrap: wrap
    }

    .hkpi-val {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 34px;
      font-weight: 900;
      color: #fff
    }

    .hkpi-val span {
      color: #60a5fa
    }

    .hkpi-lbl {
      font-size: 11px;
      color: rgba(255, 255, 255, .4);
      text-transform: uppercase;
      letter-spacing: 1.5px;
      margin-top: 2px
    }

    /* ── STATS ── */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 32px
    }

    .stat-card {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      padding: 20px 22px;
      position: relative;
      overflow: hidden;
      transition: all .3s;
      box-shadow: var(--shadow-sm)
    }

    .stat-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-md);
      border-color: var(--blue-mid)
    }

    .stat-icon-wrap {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      margin-bottom: 14px
    }

    .si-blue {
      background: var(--blue-soft)
    }

    .si-orange {
      background: var(--orange-soft)
    }

    .si-green {
      background: var(--green-soft)
    }

    .si-gold {
      background: #fef9e7
    }

    .stat-val {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 38px;
      font-weight: 900;
      color: var(--text1);
      line-height: 1
    }

    .stat-lbl {
      font-size: 12px;
      color: var(--text3);
      margin-top: 3px
    }

    .stat-prog {
      height: 4px;
      background: var(--bg2);
      border-radius: 10px;
      margin-top: 12px;
      overflow: hidden
    }

    .stat-prog-fill {
      height: 100%;
      border-radius: 10px
    }

    .prog-blue {
      background: linear-gradient(90deg, #60a5fa, var(--blue))
    }

    .prog-orange {
      background: linear-gradient(90deg, #fdba74, var(--orange))
    }

    .prog-green {
      background: linear-gradient(90deg, #6ee7b7, var(--green))
    }

    .prog-gold {
      background: linear-gradient(90deg, #fde68a, var(--gold))
    }

    /* ── SECTION HEADER ── */
    .sec-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px
    }

    .sec-h {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 22px;
      font-weight: 900;
      letter-spacing: 1px
    }

    .see-all {
      font-size: 12px;
      font-weight: 700;
      color: var(--blue);
      cursor: pointer
    }

    /* ── EVENTS GRID ── */
    .events-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 32px
    }

    .event-card {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      overflow: hidden;
      transition: all .3s;
      cursor: pointer;
      box-shadow: var(--shadow-sm)
    }

    .event-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-md);
      border-color: var(--blue-mid)
    }

    .event-card-header {
      padding: 16px 16px 12px
    }

    .ev-sport-tag {
      display: inline-block;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      padding: 4px 10px;
      border-radius: 20px
    }

    .tag-blue {
      background: var(--blue-soft);
      color: var(--blue)
    }

    .tag-orange {
      background: var(--orange-soft);
      color: var(--orange)
    }

    .tag-green {
      background: var(--green-soft);
      color: var(--green)
    }

    .ev-title {
      font-weight: 800;
      font-size: 15px;
      line-height: 1.3;
      margin: 8px 0 4px
    }

    .ev-venue {
      font-size: 12px;
      color: var(--text3);
      display: flex;
      align-items: center;
      gap: 4px
    }

    .event-card-footer {
      padding: 10px 16px 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-top: 1px solid var(--border)
    }

    .ev-date-chip {
      font-size: 11px;
      font-weight: 700;
      color: var(--blue);
      background: var(--blue-soft);
      padding: 4px 10px;
      border-radius: 20px
    }

    .ev-action {
      font-size: 11px;
      font-weight: 700;
      color: var(--text2);
      cursor: pointer
    }

    .ev-action:hover {
      color: var(--blue)
    }

    .no-data {
      text-align: center;
      padding: 40px 20px;
      color: var(--text3);
      font-size: 14px
    }

    /* ── COURSES GRID ── */
    .courses-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 32px
    }

    .course-card {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      overflow: hidden;
      transition: all .3s;
      box-shadow: var(--shadow-sm)
    }

    .course-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-md)
    }

    .cc-header {
      height: 120px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 50px;
      position: relative
    }

    .cc-body {
      padding: 16px
    }

    .cc-title {
      font-weight: 800;
      font-size: 15px;
      margin-bottom: 6px
    }

    .cc-desc {
      font-size: 12px;
      color: var(--text2);
      line-height: 1.6;
      margin-bottom: 10px;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden
    }

    .cc-meta {
      font-size: 11px;
      color: var(--text3);
      display: flex;
      gap: 12px;
      flex-wrap: wrap
    }

    .cc-req {
      font-size: 11px;
      color: var(--text2);
      background: var(--surface3);
      border-radius: 6px;
      padding: 6px 10px;
      margin-top: 8px;
      font-style: italic
    }

    /* ── CONTENIDO ── */
    .media-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 32px
    }

    .media-card {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      overflow: hidden;
      transition: all .3s;
      box-shadow: var(--shadow-sm)
    }

    .media-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-md)
    }

    .mc-thumb {
      height: 130px;
      background: linear-gradient(135deg, var(--blue-soft), var(--blue-mid));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 40px
    }

    .mc-body {
      padding: 14px
    }

    .mc-type {
      font-size: 9px;
      font-weight: 800;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: var(--blue);
      background: var(--blue-soft);
      padding: 3px 8px;
      border-radius: 20px;
      display: inline-block;
      margin-bottom: 8px
    }

    .mc-title {
      font-weight: 700;
      font-size: 14px;
      margin-bottom: 4px;
      line-height: 1.3
    }

    .mc-meta {
      font-size: 11px;
      color: var(--text3)
    }

    /* ── FILTER TABS ── */
    .ftabs {
      display: flex;
      gap: 6px;
      margin-bottom: 16px;
      flex-wrap: wrap
    }

    .ftab {
      padding: 7px 16px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      border: 1.5px solid var(--border);
      background: var(--surface);
      color: var(--text2);
      transition: all .2s;
      white-space: nowrap
    }

    .ftab:hover,
    .ftab.active {
      background: var(--blue);
      color: #fff;
      border-color: var(--blue)
    }

    /* ── ADMIN ── */
    .admin-header {
      background: linear-gradient(135deg, #0d1b2a, #1a2f4a);
      padding: 28px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 16px
    }

    .admin-title-wrap .admin-title {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 32px;
      font-weight: 900;
      letter-spacing: 2px;
      color: #fff
    }

    .admin-title-wrap .admin-sub {
      font-size: 13px;
      color: rgba(255, 255, 255, .5);
      margin-top: 2px
    }

    .admin-tabs {
      display: flex;
      gap: 4px;
      background: rgba(255, 255, 255, .08);
      border-radius: 12px;
      padding: 4px;
      flex-wrap: wrap
    }

    .adm-tab {
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      color: rgba(255, 255, 255, .6);
      transition: all .2s;
      white-space: nowrap
    }

    .adm-tab:hover {
      color: #fff;
      background: rgba(255, 255, 255, .1)
    }

    .adm-tab.active {
      background: #fff;
      color: var(--text1)
    }

    .admin-content {
      padding: 24px 32px 60px
    }

    .adm-panel {
      display: none
    }

    .adm-panel.active {
      display: block
    }

    .panel-box {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      box-shadow: var(--shadow-sm)
    }

    .adm-stats {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      margin-bottom: 28px
    }

    .adm-stat {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      padding: 18px 20px;
      text-align: center;
      box-shadow: var(--shadow-sm)
    }

    .adm-stat-val {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 36px;
      font-weight: 900;
      color: var(--blue)
    }

    .adm-stat-lbl {
      font-size: 11px;
      color: var(--text3);
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: 1px
    }

    .table-wrap {
      overflow-x: auto;
      border-radius: var(--r);
      border: 1.5px solid var(--border)
    }

    table {
      width: 100%;
      border-collapse: collapse
    }

    th {
      background: var(--surface3);
      padding: 10px 14px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: var(--text2);
      text-align: left;
      border-bottom: 1.5px solid var(--border)
    }

    td {
      padding: 10px 14px;
      font-size: 13px;
      border-bottom: 1px solid var(--border)
    }

    tr:last-child td {
      border-bottom: none
    }

    tr:hover td {
      background: var(--surface2)
    }

    .tbl-actions {
      display: flex;
      gap: 6px;
      flex-wrap: wrap
    }

    .tbl-btn {
      padding: 5px 10px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      cursor: pointer;
      border: 1.5px solid var(--border);
      background: var(--surface);
      color: var(--text2);
      transition: all .2s
    }

    .tbl-btn:hover {
      background: var(--blue-soft);
      color: var(--blue);
      border-color: var(--blue-mid)
    }

    .tbl-btn.danger:hover {
      background: var(--red-soft);
      color: var(--red);
      border-color: #fca5a5
    }

    .tbl-btn.success {
      background: var(--green-soft);
      color: var(--green);
      border-color: #6ee7b7
    }

    /* ── FORMS ── */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 16px;
      margin-bottom: 16px
    }

    .form-grid.full {
      grid-template-columns: 1fr
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px
    }

    .form-label {
      font-size: 12px;
      font-weight: 700;
      color: var(--text2);
      letter-spacing: .5px
    }

    .form-input,
    .form-select,
    .form-textarea {
      padding: 10px 14px;
      border: 1.5px solid var(--border);
      border-radius: var(--r-sm);
      font-family: 'DM Sans', sans-serif;
      font-size: 13px;
      color: var(--text1);
      background: var(--surface2);
      outline: none;
      transition: all .2s;
      width: 100%
    }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
      border-color: var(--blue-mid);
      box-shadow: 0 0 0 3px rgba(26, 86, 255, .1)
    }

    .form-textarea {
      min-height: 100px;
      resize: vertical
    }

    .form-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 20px
    }

    .file-input-wrap {
      position: relative;
      border: 2px dashed var(--border);
      border-radius: var(--r-sm);
      padding: 14px;
      text-align: center;
      cursor: pointer;
      transition: all .2s;
      background: var(--surface2)
    }

    .file-input-wrap:hover {
      border-color: var(--blue-mid);
      background: var(--blue-soft)
    }

    .file-input-wrap input[type=file] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
      width: 100%;
      height: 100%
    }

    .file-preview {
      width: 100%;
      max-height: 100px;
      object-fit: cover;
      border-radius: 6px;
      margin-top: 8px;
      display: none
    }

    .btn-publish {
      padding: 10px 22px;
      border-radius: var(--r-sm);
      background: var(--blue);
      color: #fff;
      border: none;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: all .2s;
      box-shadow: 0 4px 14px rgba(26, 86, 255, .3)
    }

    .btn-publish:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(26, 86, 255, .45)
    }

    .btn-cancel {
      padding: 10px 22px;
      border-radius: var(--r-sm);
      background: var(--red-soft);
      color: var(--red);
      border: 1.5px solid #fca5a5;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: all .2s
    }

    .btn-cancel:hover {
      background: var(--red);
      color: #fff
    }

    .btn-edit-row {
      padding: 6px 12px;
      border-radius: 6px;
      background: var(--blue-soft);
      color: var(--blue);
      border: 1.5px solid var(--blue-mid);
      font-size: 11px;
      font-weight: 700;
      cursor: pointer;
      transition: all .2s
    }

    .btn-edit-row:hover {
      background: var(--blue);
      color: #fff
    }

    /* ── MODAL ── */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(13, 27, 42, .6);
      backdrop-filter: blur(8px);
      z-index: 500;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px
    }

    .modal-overlay.open {
      display: flex
    }

    .modal-box {
      background: var(--surface);
      border-radius: var(--r-lg);
      width: min(600px, 96vw);
      max-height: 90vh;
      overflow-y: auto;
      position: relative;
      box-shadow: 0 32px 80px rgba(13, 27, 42, .35)
    }

    .modal-close {
      position: absolute;
      top: 14px;
      right: 16px;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: var(--surface3);
      border: 1.5px solid var(--border);
      cursor: pointer;
      font-size: 14px;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: center
    }

    .modal-close:hover {
      background: var(--red-soft);
      color: var(--red)
    }

    .m-body {
      padding: 24px
    }

    .m-title {
      font-family: 'Barlow Condensed', sans-serif;
      font-size: 26px;
      font-weight: 900;
      letter-spacing: 1px;
      margin-bottom: 10px
    }

    .m-tags {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      margin-bottom: 12px
    }

    .m-tag {
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      padding: 4px 10px;
      border-radius: 20px
    }

    .tag-blue.m-tag {
      background: var(--blue-soft);
      color: var(--blue)
    }

    .m-excerpt {
      font-size: 13px;
      color: var(--text2);
      line-height: 1.7;
      margin-bottom: 16px
    }

    .m-info-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 10px;
      margin-bottom: 16px
    }

    .m-info-card {
      background: var(--surface2);
      border-radius: 10px;
      padding: 12px
    }

    .m-info-label {
      font-size: 10px;
      font-weight: 700;
      color: var(--text3);
      letter-spacing: 1px;
      text-transform: uppercase;
      margin-bottom: 4px
    }

    .m-info-val {
      font-size: 13px;
      font-weight: 600;
      color: var(--text1)
    }

    /* ── INSCRIPCIONES ── */
    .insc-card {
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--r);
      padding: 20px;
      box-shadow: var(--shadow-sm);
      display: flex;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 12px;
      transition: all .3s
    }

    .insc-card:hover {
      box-shadow: var(--shadow-md);
      border-color: var(--blue-mid)
    }

    .insc-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: var(--blue-soft);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      flex-shrink: 0
    }

    .insc-info {
      flex: 1
    }

    .insc-nombre {
      font-weight: 800;
      font-size: 15px;
      margin-bottom: 4px
    }

    .insc-meta {
      font-size: 12px;
      color: var(--text3)
    }

    .insc-actions {
      display: flex;
      gap: 8px;
      margin-top: 12px;
      flex-wrap: wrap
    }

    .estado-chip {
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase
    }

    .estado-pendiente {
      background: #fef9e7;
      color: var(--gold)
    }

    .estado-activo {
      background: var(--green-soft);
      color: var(--green)
    }

    .estado-cancelado {
      background: var(--red-soft);
      color: var(--red)
    }

    /* ── TOAST ── */
    .toast {
      position: fixed;
      bottom: 24px;
      right: 24px;
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: 14px;
      padding: 12px 18px;
      display: flex;
      align-items: center;
      gap: 10px;
      box-shadow: var(--shadow-lg);
      z-index: 9999;
      transform: translateY(80px);
      opacity: 0;
      transition: all .4s cubic-bezier(.34, 1.56, .64, 1);
      pointer-events: none;
      max-width: 340px
    }

    .toast.show {
      transform: translateY(0);
      opacity: 1
    }

    /* ── REVEAL ── */
    .reveal {
      opacity: 0;
      transform: translateY(18px);
      transition: opacity .5s ease, transform .5s ease
    }

    .reveal.visible {
      opacity: 1;
      transform: translateY(0)
    }

    /* ── GRADIENT BG CARDS ── */
    .g-blue {
      background: linear-gradient(135deg, #0d1b2a, #1a3a6e)
    }

    .g-orange {
      background: linear-gradient(135deg, #7c2d12, #c2410c)
    }

    .g-green {
      background: linear-gradient(135deg, #064e3b, #065f46)
    }

    .g-purple {
      background: linear-gradient(135deg, #3b0764, #6b21a8)
    }

    .g-red {
      background: linear-gradient(135deg, #7f1d1d, #b91c1c)
    }

    .g-teal {
      background: linear-gradient(135deg, #134e4a, #0f766e)
    }

    /* ── TICKET PRINT ── */
    @media print {

      .controles,
      .topbar,
      .sidebar,
      .app-shell>.main>*:not(#ticketPrintArea) {
        display: none !important;
      }

      #ticketPrintArea {
        display: block !important;
      }

      body,
      html {
        background: white;
      }
    }

    #ticketPrintArea {
      display: none;
    }

    /* ── RESPONSIVE ── */
    @media(max-width:900px) {
      .stats-row {
        grid-template-columns: repeat(2, 1fr)
      }

      .events-grid,
      .courses-grid,
      .media-grid {
        grid-template-columns: 1fr
      }

      .adm-stats {
        grid-template-columns: repeat(2, 1fr)
      }

      .form-grid {
        grid-template-columns: 1fr
      }

      .hero {
        padding: 28px 24px
      }

      .sidebar {
        transform: translateX(-100%)
      }

      .main {
        margin-left: 0 !important
      }

      .content {
        padding: 20px 16px 80px
      }

      .admin-content {
        padding: 16px
      }
    }
  </style>
</head>

<body>

  <!-- TICKET PRINT AREA (hidden, shown only for print) -->
  <div id="ticketPrintArea"></div>

  <!-- AUTH OVERLAY -->
  <div class="auth-overlay hidden" id="authOverlay">
    <div class="auth-box" style="position:relative">
      <button onclick="closeAuth()" style="position:absolute;top:14px;right:14px;width:30px;height:30px;border-radius:50%;background:var(--surface3);border:1.5px solid var(--border);cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;z-index:2" title="Cerrar">✕</button>
      <!-- LOGIN FORM -->
      <div id="formLogin">
        <div class="auth-logo">
          <div class="logo-mark">X</div>
          <div>
            <div class="logo-name">Xonacatlan</div>
            <div class="logo-sub">Plataforma de Deportes</div>
          </div>
        </div>
        <div class="auth-title">Iniciar Sesión</div>
        <div class="auth-sub">Accede a tu plataforma deportiva</div>
        <div class="auth-error" id="loginError"></div>
        <div class="auth-form">
          <div><label class="auth-label">Correo electrónico</label><input class="auth-input" type="email" id="loginEmail" placeholder="tu@correo.com"></div>
          <div><label class="auth-label">Contraseña</label><input class="auth-input" type="password" id="loginPass" placeholder="••••••••" onkeydown="if(event.key==='Enter')doLogin()"></div>
          <button class="auth-btn" onclick="doLogin()">Entrar →</button>
        </div>
        <div class="auth-switch">¿No tienes cuenta? <a onclick="switchForm('registro')">Regístrate aquí</a></div>
      </div>
      <!-- REGISTRO FORM -->
      <div id="formRegistro" style="display:none">
        <div class="auth-logo">
          <div class="logo-mark">X</div>
          <div>
            <div class="logo-name">Xonacatlan</div>
            <div class="logo-sub">Plataforma de Deportes</div>
          </div>
        </div>
        <div class="auth-title">Crear Cuenta</div>
        <div class="auth-sub">Únete a la plataforma deportiva</div>
        <div class="auth-error" id="regError"></div>
        <div class="auth-form">
          <div><label class="auth-label">Nombre completo *</label><input class="auth-input" type="text" id="regNombre" placeholder="Tu nombre completo"></div>
          <div><label class="auth-label">Correo electrónico *</label><input class="auth-input" type="email" id="regEmail" placeholder="tu@correo.com"></div>
          <div><label class="auth-label">Contraseña *</label><input class="auth-input" type="password" id="regPass" placeholder="Mínimo 6 caracteres"></div>
          <div><label class="auth-label">No. de Control</label><input class="auth-input" type="text" id="regNoControl" placeholder="Número de control (opcional)"></div>
          <div><label class="auth-label">RFC</label><input class="auth-input" type="text" id="regRFC" placeholder="RFC (opcional)"></div>
          <div><label class="auth-label">CURP</label><input class="auth-input" type="text" id="regCURP" placeholder="CURP (opcional)" onkeydown="if(event.key==='Enter')doRegistro()"></div>
          <button class="auth-btn" onclick="doRegistro()">Crear Cuenta →</button>
        </div>
        <div class="auth-switch">¿Ya tienes cuenta? <a onclick="switchForm('login')">Inicia sesión</a></div>
      </div>
    </div>
  </div>

  <!-- APP SHELL -->
  <div class="app-shell">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        <div class="logo-mark">X</div>
        <div class="logo-text-wrap">
          <div class="logo-name">Xonacatlan</div>
          <div class="logo-sub">Plataforma de Deportes</div>
        </div>
        <button class="collapse-btn" onclick="toggleSidebar()" id="collapseBtn">◀</button>
      </div>

      <div class="sec-title">Principal</div>
      <div class="nav-item active" onclick="navigate('dashboard',this)">
        <div class="nav-icon">🏠</div>
        <div class="nav-label">Inicio</div>
      </div>
      <div class="nav-item" onclick="navigate('eventos',this)">
        <div class="nav-icon">📅</div>
        <div class="nav-label">Eventos</div>
      </div>
      <div class="nav-item" onclick="navigate('cursos',this)">
        <div class="nav-icon">🎓</div>
        <div class="nav-label">Cursos</div>
      </div>
      <div class="nav-item" onclick="navigate('contenido',this)">
        <div class="nav-icon">📰</div>
        <div class="nav-label">Noticias</div>
      </div>

      <!-- Solo visible si está logueado como usuario -->
      <div id="navUserSection" style="display:none">
        <div class="sec-title">Mi Cuenta</div>
        <div class="nav-item" onclick="navigate('misinscripciones',this)">
          <div class="nav-icon">📋</div>
          <div class="nav-label">Mis Inscripciones</div>
        </div>
      </div>

      <!-- Solo visible si es admin -->
      <div id="navAdminSection" style="display:none">
        <div class="sec-title">Sistema</div>
        <div class="nav-item" onclick="navigate('admin',this)" id="navAdmin">
          <div class="nav-icon">⚙️</div>
          <div class="nav-label">Panel Admin</div>
        </div>
      </div>

      <div class="sidebar-footer" id="sbFooterGuest">
        <div class="sb-avatar" style="background:var(--surface3);color:var(--text3);box-shadow:none">👤</div>
        <div class="sb-foot-info">
          <div class="sb-name">Visitante</div>
          <div class="sb-role">Sin sesión</div>
        </div>
        <div class="sb-settings" title="Iniciar sesión" onclick="openAuth('login')" style="color:var(--blue)">🔑</div>
      </div>
      <div class="sidebar-footer" id="sbFooterUser" style="display:none">
        <div class="sb-avatar" id="sbAvatar">?</div>
        <div class="sb-foot-info">
          <div class="sb-name" id="sbName">—</div>
          <div class="sb-role" id="sbRole">—</div>
        </div>
        <div class="sb-settings" title="Cerrar sesión" onclick="doLogout()">⏻</div>
      </div>
    </aside>

    <!-- MAIN -->
    <main class="main" id="main">

      <!-- TOPBAR -->
      <div class="topbar">
        <div>
          <div class="topbar-title" id="topbarTitle">Inicio</div>
          <div class="topbar-sub" id="topbarSub">Bienvenido a la plataforma de Deportes de Xonacatlan</div>
        </div>
        <div class="topbar-right">
          <div class="search-wrap">
            <span style="color:var(--text3)">🔍</span>
            <input type="text" placeholder="Buscar eventos, cursos..." id="searchInput" oninput="handleSearch(this.value)">
          </div>
          <div id="topbarGuest" style="display:flex;gap:8px;align-items:center">
            <button style="padding:8px 16px;border-radius:30px;background:var(--surface);color:var(--text2);border:1.5px solid var(--border);font-size:12px;font-weight:700;cursor:pointer;transition:all .2s" onclick="openAuth('login')">Iniciar Sesión</button>
            <button class="btn-publish" style="padding:8px 18px;font-size:12px;border-radius:30px" onclick="openAuth('registro')">Registrarse</button>
          </div>
          <div id="topbarUser" style="display:none;gap:8px;align-items:center">
            <span id="topbarUserName" style="font-size:13px;font-weight:600;color:var(--text2)"></span>
            <button class="logout-btn" onclick="doLogout()">⏻ Salir</button>
          </div>
        </div>
      </div>

      <!-- ══════ DASHBOARD ══════ -->
      <div class="page active" id="page-dashboard">
        <div class="content">
          <div class="hero reveal">
            <div class="hero-grid"></div>
            <div class="hero-content">
              <div class="hero-chip">⚡ Plataforma Deportiva Pública</div>
              <div class="hero-h">ENTRENA.<br><em>COMPITE.</em><br>DOMINA.</div>
              <p class="hero-p">Ecosistema deportivo completo: eventos, cursos profesionales y noticias en un solo lugar.</p>
              <div class="hero-btns">
                <button class="btn-hero btn-hero-primary" onclick="navigate('eventos',null)">Explorar Eventos ▶</button>
                <button class="btn-hero btn-hero-ghost" onclick="navigate('cursos',null)">Ver Cursos</button>
              </div>
              <div class="hero-kpis">
                <div>
                  <div class="hkpi-val" id="hkpiEventos">—</div>
                  <div class="hkpi-lbl">Eventos</div>
                </div>
                <div>
                  <div class="hkpi-val" id="hkpiCursos">—</div>
                  <div class="hkpi-lbl">Cursos</div>
                </div>
                <div>
                  <div class="hkpi-val" id="hkpiContenido">—</div>
                  <div class="hkpi-lbl">Noticias</div>
                </div>
                <div>
                  <div class="hkpi-val" id="hkpiUsuarios">—</div>
                  <div class="hkpi-lbl">Usuarios</div>
                </div>
              </div>
            </div>
          </div>
          <div class="stats-row">
            <div class="stat-card reveal">
              <div class="stat-icon-wrap si-blue">📅</div>
              <div class="stat-val" id="scEventos">—</div>
              <div class="stat-lbl">Eventos Registrados</div>
              <div class="stat-prog">
                <div class="stat-prog-fill prog-blue" style="width:70%"></div>
              </div>
            </div>
            <div class="stat-card reveal">
              <div class="stat-icon-wrap si-orange">🎓</div>
              <div class="stat-val" id="scCursos">—</div>
              <div class="stat-lbl">Cursos Disponibles</div>
              <div class="stat-prog">
                <div class="stat-prog-fill prog-orange" style="width:60%"></div>
              </div>
            </div>
            <div class="stat-card reveal">
              <div class="stat-icon-wrap si-green">📰</div>
              <div class="stat-val" id="scContenido">—</div>
              <div class="stat-lbl">Publicaciones</div>
              <div class="stat-prog">
                <div class="stat-prog-fill prog-green" style="width:80%"></div>
              </div>
            </div>
            <div class="stat-card reveal">
              <div class="stat-icon-wrap si-gold">👥</div>
              <div class="stat-val" id="scUsuarios">—</div>
              <div class="stat-lbl">Usuarios Registrados</div>
              <div class="stat-prog">
                <div class="stat-prog-fill prog-gold" style="width:55%"></div>
              </div>
            </div>
          </div>
          <div class="sec-header reveal">
            <div class="sec-h">Próximos Eventos</div>
            <div class="see-all" onclick="navigate('eventos',null)">Ver todos ›</div>
          </div>
          <div class="events-grid" id="dashEventos">
            <div class="no-data">Cargando…</div>
          </div>
          <div class="sec-header reveal" style="margin-top:8px">
            <div class="sec-h">Cursos Recientes</div>
            <div class="see-all" onclick="navigate('cursos',null)">Ver todos ›</div>
          </div>
          <div class="courses-grid" id="dashCursos">
            <div class="no-data">Cargando…</div>
          </div>
        </div>
      </div>

      <!-- ══════ EVENTOS ══════ -->
      <div class="page" id="page-eventos">
        <div class="content">
          <div class="sec-header">
            <div class="sec-h">📅 Todos los Eventos</div>
          </div>
          <div class="ftabs" id="ftabsEventos">
            <div class="ftab active" onclick="filterEventos('todos',this)">Todos</div>
            <div class="ftab" onclick="filterEventos('futbol',this)">⚽ Fútbol</div>
            <div class="ftab" onclick="filterEventos('basquet',this)">🏀 Basquet</div>
            <div class="ftab" onclick="filterEventos('tenis',this)">🎾 Tenis</div>
          </div>
          <div class="events-grid" id="allEventos">
            <div class="no-data">Cargando…</div>
          </div>
        </div>
      </div>

      <!-- ══════ CURSOS ══════ -->
      <div class="page" id="page-cursos">
        <div class="content">
          <div class="sec-header">
            <div class="sec-h">🎓 Cursos y Programas</div>
          </div>
          <div class="courses-grid" id="allCursos">
            <div class="no-data">Cargando…</div>
          </div>
        </div>
      </div>

      <!-- ══════ CONTENIDO ══════ -->
      <div class="page" id="page-contenido">
        <div class="content">
          <div class="sec-header">
            <div class="sec-h">📰 Noticias y Contenido</div>
          </div>
          <div class="media-grid" id="allContenido">
            <div class="no-data">Cargando…</div>
          </div>
        </div>
      </div>

      <!-- ══════ MIS INSCRIPCIONES ══════ -->
      <div class="page" id="page-misinscripciones">
        <div class="content">
          <div class="sec-header">
            <div class="sec-h">📋 Mis Inscripciones</div>
            <button class="btn-publish" onclick="navigate('cursos',null)">＋ Inscribirme a un curso</button>
          </div>
          <div id="listaMisInscripciones">
            <div class="no-data">Cargando…</div>
          </div>
        </div>
      </div>

      <!-- ══════ ADMIN ══════ -->
      <div class="page" id="page-admin">
        <div class="admin-header">
          <div class="admin-title-wrap">
            <div class="admin-title">⚙️ Panel Administrador</div>
            <div class="admin-sub">Gestión completa del sistema</div>
          </div>
          <div class="admin-tabs">
            <div class="adm-tab active" onclick="switchAdmTab(0,this)">📊 Inicio</div>
            <div class="adm-tab" onclick="switchAdmTab(1,this)">📅 Eventos</div>
            <div class="adm-tab" onclick="switchAdmTab(2,this)">🎓 Cursos</div>
            <div class="adm-tab" onclick="switchAdmTab(3,this)">📰 Contenido</div>
            <div class="adm-tab" onclick="switchAdmTab(4,this)">👥 Usuarios</div>
            <div class="adm-tab" onclick="switchAdmTab(5,this)">📋 Inscripciones</div>
          </div>
        </div>
        <div class="admin-content">

          <!-- ADM 0: Stats -->
          <div class="adm-panel active" id="adm-0">
            <div class="adm-stats">
              <div class="adm-stat">
                <div class="adm-stat-val" id="admEv">—</div>
                <div class="adm-stat-lbl">📅 Eventos</div>
              </div>
              <div class="adm-stat">
                <div class="adm-stat-val" id="admCu">—</div>
                <div class="adm-stat-lbl">🎓 Cursos</div>
              </div>
              <div class="adm-stat">
                <div class="adm-stat-val" id="admCo">—</div>
                <div class="adm-stat-lbl">📰 Contenido</div>
              </div>
              <div class="adm-stat">
                <div class="adm-stat-val" id="admUs">—</div>
                <div class="adm-stat-lbl">👥 Usuarios</div>
              </div>
            </div>
            <div class="panel-box" style="padding:24px">
              <p style="font-size:14px;color:var(--text2);line-height:1.8">Bienvenido al panel de administración. Gestiona <strong>Eventos</strong>, <strong>Cursos</strong>, <strong>Contenido</strong>, <strong>Usuarios</strong> e <strong>Inscripciones</strong> desde las pestañas de arriba.</p>
            </div>
          </div>

          <!-- ADM 1: Eventos -->
          <div class="adm-panel" id="adm-1">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
              <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900">📅 Gestión de Eventos</div>
              <button class="btn-publish" onclick="showFormEvento()">＋ Nuevo Evento</button>
            </div>
            <div class="panel-box" style="padding:24px;margin-bottom:20px;display:none" id="formEventoWrap">
              <div style="font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:900;margin-bottom:16px" id="formEventoTitulo">Nuevo Evento</div>
              <input type="hidden" id="ev-id">
              <div class="form-grid">
                <div class="form-group"><label class="form-label">Nombre *</label><input class="form-input" id="ev-nombre" placeholder="Nombre del evento"></div>
                <div class="form-group"><label class="form-label">Fecha *</label><input type="date" class="form-input" id="ev-fecha"></div>
                <div class="form-group"><label class="form-label">Lugar</label><input class="form-input" id="ev-lugar" placeholder="Sede del evento"></div>
                <div class="form-group">
                  <label class="form-label">Imagen del evento</label>
                  <div class="file-input-wrap" id="evImgWrap">
                    <input type="file" id="ev-img-file" accept="image/*" onchange="previewImg(this,'evPreview','evImgWrap')">
                    <span id="evImgLabel">📁 Haz clic o arrastra una imagen aquí</span>
                    <img id="evPreview" class="file-preview" alt="preview">
                  </div>
                  <input class="form-input" id="ev-img" placeholder="O pega una URL de imagen" style="margin-top:6px">
                </div>
              </div>
              <div class="form-grid full">
                <div class="form-group"><label class="form-label">Descripción</label><textarea class="form-textarea" id="ev-desc" placeholder="Descripción del evento…"></textarea></div>
              </div>
              <div class="form-actions">
                <button class="btn-publish" onclick="guardarEvento()">💾 Guardar</button>
                <button class="btn-cancel" onclick="document.getElementById('formEventoWrap').style.display='none'">✕ Cancelar</button>
              </div>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Nombre</th>
                    <th>Fecha</th>
                    <th>Lugar</th>
                    <th>Imagen</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody id="tbEventos">
                  <tr>
                    <td colspan="5" style="text-align:center;color:var(--text3)">Cargando…</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ADM 2: Cursos -->
          <div class="adm-panel" id="adm-2">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
              <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900">🎓 Gestión de Cursos</div>
              <button class="btn-publish" onclick="showFormCurso()">＋ Nuevo Curso</button>
            </div>
            <div class="panel-box" style="padding:24px;margin-bottom:20px;display:none" id="formCursoWrap">
              <div style="font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:900;margin-bottom:16px" id="formCursoTitulo">Nuevo Curso</div>
              <input type="hidden" id="cu-id">
              <div class="form-grid">
                <div class="form-group"><label class="form-label">Nombre *</label><input class="form-input" id="cu-nombre" placeholder="Nombre del curso"></div>
                <div class="form-group"><label class="form-label">Costo ($)</label><input type="number" step="0.01" class="form-input" id="cu-costo" placeholder="0.00"></div>
                <div class="form-group"><label class="form-label">Fecha Inicio</label><input type="date" class="form-input" id="cu-fi"></div>
                <div class="form-group"><label class="form-label">Fecha Fin</label><input type="date" class="form-input" id="cu-ff"></div>
                <div class="form-group" style="grid-column:1/-1"><label class="form-label">Requisitos</label><input class="form-input" id="cu-req" placeholder="Requisitos previos"></div>
              </div>
              <div class="form-grid full">
                <div class="form-group"><label class="form-label">Descripción</label><textarea class="form-textarea" id="cu-desc" placeholder="Descripción del curso…"></textarea></div>
              </div>
              <div class="form-actions">
                <button class="btn-publish" onclick="guardarCurso()">💾 Guardar</button>
                <button class="btn-cancel" onclick="document.getElementById('formCursoWrap').style.display='none'">✕ Cancelar</button>
              </div>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Nombre</th>
                    <th>Inicio</th>
                    <th>Fin</th>
                    <th>Costo</th>
                    <th>Requisitos</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody id="tbCursos">
                  <tr>
                    <td colspan="6" style="text-align:center;color:var(--text3)">Cargando…</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ADM 3: Contenido -->
          <div class="adm-panel" id="adm-3">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
              <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900">📰 Gestión de Contenido</div>
              <button class="btn-publish" onclick="showFormContenido()">＋ Nuevo Contenido</button>
            </div>
            <div class="panel-box" style="padding:24px;margin-bottom:20px;display:none" id="formContenidoWrap">
              <div style="font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:900;margin-bottom:16px" id="formContenidoTitulo">Nuevo Contenido</div>
              <input type="hidden" id="co-id">
              <div class="form-grid">
                <div class="form-group"><label class="form-label">Título *</label><input class="form-input" id="co-titulo" placeholder="Título del contenido"></div>
                <div class="form-group"><label class="form-label">Tipo</label>
                  <select class="form-select" id="co-tipo">
                    <option value="noticia">Noticia</option>
                    <option value="video">Video</option>
                    <option value="imagen">Imagen</option>
                    <option value="infografia">Infografía</option>
                  </select>
                </div>
                <div class="form-group"><label class="form-label">Fecha</label><input type="date" class="form-input" id="co-fecha"></div>
                <div class="form-group">
                  <label class="form-label">Imagen / Archivo</label>
                  <div class="file-input-wrap" id="coImgWrap">
                    <input type="file" id="co-img-file" accept="image/*" onchange="previewImg(this,'coPreview','coImgWrap')">
                    <span id="coImgLabel">📁 Haz clic o arrastra una imagen aquí</span>
                    <img id="coPreview" class="file-preview" alt="preview">
                  </div>
                  <input class="form-input" id="co-url" placeholder="O pega una URL" style="margin-top:6px">
                </div>
              </div>
              <div class="form-grid full">
                <div class="form-group"><label class="form-label">Descripción</label><textarea class="form-textarea" id="co-desc" placeholder="Descripción…"></textarea></div>
              </div>
              <div class="form-actions">
                <button class="btn-publish" onclick="guardarContenido()">💾 Guardar</button>
                <button class="btn-cancel" onclick="document.getElementById('formContenidoWrap').style.display='none'">✕ Cancelar</button>
              </div>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Título</th>
                    <th>Tipo</th>
                    <th>Fecha</th>
                    <th>URL/Imagen</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody id="tbContenido">
                  <tr>
                    <td colspan="5" style="text-align:center;color:var(--text3)">Cargando…</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ADM 4: Usuarios -->
          <div class="adm-panel" id="adm-4">
            <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;margin-bottom:16px">👥 Gestión de Usuarios</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Rol</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody id="tbUsuarios">
                  <tr>
                    <td colspan="5" style="text-align:center;color:var(--text3)">Cargando…</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ADM 5: Inscripciones -->
          <div class="adm-panel" id="adm-5">
            <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;margin-bottom:16px">📋 Gestión de Inscripciones</div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Curso</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody id="tbInscripciones">
                  <tr>
                    <td colspan="6" style="text-align:center;color:var(--text3)">Cargando…</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>

    </main>
  </div>

  <!-- MODAL DETALLE EVENTO -->
  <div class="modal-overlay" id="modalEvento">
    <div class="modal-box">
      <button class="modal-close" onclick="closeModal('modalEvento')">✕</button>
      <div id="mEvImg" style="height:180px;border-radius:var(--r-lg) var(--r-lg) 0 0;overflow:hidden;background:var(--blue-soft);display:flex;align-items:center;justify-content:center;font-size:60px"></div>
      <div class="m-body">
        <div class="m-tags"><span class="m-tag tag-blue">📅 Evento</span></div>
        <div class="m-title" id="mEvNombre"></div>
        <div class="m-excerpt" id="mEvDesc"></div>
        <div class="m-info-grid">
          <div class="m-info-card">
            <div class="m-info-label">📍 Lugar</div>
            <div class="m-info-val" id="mEvLugar"></div>
          </div>
          <div class="m-info-card">
            <div class="m-info-label">📅 Fecha</div>
            <div class="m-info-val" id="mEvFecha"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL DETALLE CURSO -->
  <div class="modal-overlay" id="modalCurso">
    <div class="modal-box">
      <button class="modal-close" onclick="closeModal('modalCurso')">✕</button>
      <div class="m-body" style="padding-top:20px">
        <div class="m-tags"><span class="m-tag tag-blue">🎓 Curso</span></div>
        <div class="m-title" id="mCuNombre"></div>
        <div class="m-excerpt" id="mCuDesc"></div>
        <div class="m-info-grid">
          <div class="m-info-card">
            <div class="m-info-label">📅 Inicio</div>
            <div class="m-info-val" id="mCuInicio"></div>
          </div>
          <div class="m-info-card">
            <div class="m-info-label">🏁 Fin</div>
            <div class="m-info-val" id="mCuFin"></div>
          </div>
          <div class="m-info-card">
            <div class="m-info-label">💰 Costo</div>
            <div class="m-info-val" id="mCuCosto"></div>
          </div>
        </div>
        <div class="cc-req" id="mCuReq" style="display:none"></div>
        <div style="margin-top:16px" id="mCuActions"></div>
      </div>
    </div>
  </div>

  <!-- TOAST -->
  <div class="toast" id="toast"><span id="toastIcon">✅</span><span id="toastMsg">Mensaje</span></div>

  <script>
    // ═══════════════════════════════════════════════
    //  STATE
    // ═══════════════════════════════════════════════
    const STATE = {
      user: null,
      isAdmin: false,
      userId: null,
      eventos: [],
      cursos: [],
      contenido: []
    };

    // ═══════════════════════════════════════════════
    //  API HELPER (soporta FormData para archivos)
    // ═══════════════════════════════════════════════
    async function apiFetch(data, fileInputs = {}) {
      const fd = new FormData();
      for (const k in data) fd.append(k, data[k]);
      // Adjuntar archivos si los hay
      for (const k in fileInputs) {
        const input = fileInputs[k];
        if (input && input.files && input.files[0]) fd.append(k, input.files[0]);
      }
      const r = await fetch('index.php', {
        method: 'POST',
        body: fd
      });
      return r.json();
    }

    // ═══════════════════════════════════════════════
    //  AUTH
    // ═══════════════════════════════════════════════
    function switchForm(f) {
      document.getElementById('formLogin').style.display = f === 'login' ? 'block' : 'none';
      document.getElementById('formRegistro').style.display = f === 'registro' ? 'block' : 'none';
    }

    async function doLogin() {
      const correo = document.getElementById('loginEmail').value.trim();
      const contrasena = document.getElementById('loginPass').value;
      const err = document.getElementById('loginError');
      err.classList.remove('show');
      if (!correo || !contrasena) {
        showErr(err, 'Completa todos los campos');
        return;
      }
      const res = await apiFetch({
        action: 'login',
        correo,
        contrasena
      });
      if (res.ok) {
        STATE.user = {
          nombre: res.nombre,
          rol: res.rol
        };
        STATE.isAdmin = res.rol === 'admin';
        afterLogin();
      } else showErr(err, res.msg);
    }

    async function doRegistro() {
      const nombre = document.getElementById('regNombre').value.trim();
      const correo = document.getElementById('regEmail').value.trim();
      const contrasena = document.getElementById('regPass').value;
      const no_control = document.getElementById('regNoControl').value.trim();
      const rfc = document.getElementById('regRFC').value.trim();
      const curp = document.getElementById('regCURP').value.trim();
      const err = document.getElementById('regError');
      err.classList.remove('show');
      if (!nombre || !correo || !contrasena) {
        showErr(err, 'Completa los campos obligatorios');
        return;
      }
      const res = await apiFetch({
        action: 'registro',
        nombre,
        correo,
        contrasena,
        no_control,
        rfc,
        curp
      });
      if (res.ok) {
        STATE.user = {
          nombre: res.nombre,
          rol: res.rol
        };
        STATE.isAdmin = res.rol === 'admin';
        afterLogin();
      } else showErr(err, res.msg);
    }

    function showErr(el, msg) {
      el.textContent = msg;
      el.classList.add('show');
    }

    function openAuth(form) {
      switchForm(form);
      document.getElementById('authOverlay').classList.remove('hidden');
    }

    function closeAuth() {
      document.getElementById('authOverlay').classList.add('hidden');
    }

    function afterLogin() {
      closeAuth();
      const u = STATE.user;
      const initials = u.nombre.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
      document.getElementById('sbAvatar').textContent = initials;
      document.getElementById('sbName').textContent = u.nombre;
      document.getElementById('sbRole').textContent = STATE.isAdmin ? '🔑 Administrador' : '👤 Usuario';
      document.getElementById('sbFooterGuest').style.display = 'none';
      document.getElementById('sbFooterUser').style.display = 'flex';
      document.getElementById('topbarGuest').style.display = 'none';
      document.getElementById('topbarUser').style.display = 'flex';
      document.getElementById('topbarUserName').textContent = u.nombre;
      if (STATE.isAdmin) {
        document.getElementById('navAdminSection').style.display = 'block';
      } else {
        document.getElementById('navUserSection').style.display = 'block';
      }
      document.getElementById('topbarSub').textContent = `Bienvenido/a, ${u.nombre} 🏅`;
      loadAll();
      showToast(`👋 ¡Hola, ${u.nombre}!`, 'success');
    }

    function doLogout() {
      fetch('index.php?action=logout');
      STATE.user = null;
      STATE.isAdmin = false;
      STATE.userId = null;
      document.getElementById('sbFooterGuest').style.display = 'flex';
      document.getElementById('sbFooterUser').style.display = 'none';
      document.getElementById('topbarGuest').style.display = 'flex';
      document.getElementById('topbarUser').style.display = 'none';
      document.getElementById('navAdminSection').style.display = 'none';
      document.getElementById('navUserSection').style.display = 'none';
      document.getElementById('topbarSub').textContent = 'Bienvenido a la plataforma de Deportes de Xonacatlan';
      navigate('dashboard', document.querySelector('.nav-item'));
      showToast('👋 Sesión cerrada', 'info');
    }

    // ── Init: check PHP session ──
    (function checkSession() {
      <?php if ($isLogged): ?>
        STATE.user = {
          nombre: <?= json_encode($user['nombre']) ?>,
          rol: <?= json_encode($user['rol']) ?>
        };
        STATE.isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
        document.addEventListener('DOMContentLoaded', afterLogin);
      <?php else: ?>
        document.addEventListener('DOMContentLoaded', loadAll);
      <?php endif; ?>
    })();

    // ═══════════════════════════════════════════════
    //  LOAD DATA
    // ═══════════════════════════════════════════════
    async function loadAll() {
      await Promise.all([loadEventos(), loadCursos(), loadContenido()]);
      if (STATE.isAdmin) loadStats();
    }

    async function loadEventos() {
      const data = await apiFetch({
        action: 'get_eventos'
      });
      STATE.eventos = data;
      renderEventosDash(data.slice(0, 3));
      renderEventosPage(data);
    }

    async function loadCursos() {
      const data = await apiFetch({
        action: 'get_cursos'
      });
      STATE.cursos = data;
      renderCursosDash(data.slice(0, 3));
      renderCursosPage(data);
    }

    async function loadContenido() {
      const data = await apiFetch({
        action: 'get_contenido'
      });
      STATE.contenido = data;
      renderContenidoPage(data);
    }

    async function loadStats() {
      const s = await apiFetch({
        action: 'stats'
      });
      ['Ev', 'Cu', 'Co', 'Us'].forEach((k, i) => {
        const v = [s.eventos, s.cursos, s.contenido, s.usuarios][i];
        document.getElementById('adm' + k).textContent = v;
        document.getElementById('hkpi' + ['Eventos', 'Cursos', 'Contenido', 'Usuarios'][i]).textContent = v;
        document.getElementById('sc' + ['Eventos', 'Cursos', 'Contenido', 'Usuarios'][i]).textContent = v;
      });
    }

    // ═══════════════════════════════════════════════
    //  RENDER
    // ═══════════════════════════════════════════════
    const gradients = ['g-blue', 'g-orange', 'g-green', 'g-purple', 'g-red', 'g-teal'];
    const emojis = ['⚽', '🏀', '🎾', '🏊', '🥊', '🎯', '🏋️', '🚴'];

    function renderEventosDash(list) {
      const el = document.getElementById('dashEventos');
      el.innerHTML = list.length ? list.map((ev, i) => eventCardHTML(ev, i)).join('') : '<div class="no-data">📅 No hay eventos registrados aún</div>';
    }

    function renderEventosPage(list) {
      const el = document.getElementById('allEventos');
      el.innerHTML = list.length ? list.map((ev, i) => eventCardHTML(ev, i)).join('') : '<div class="no-data">📅 No hay eventos registrados aún</div>';
    }

    function eventCardHTML(ev, i) {
      const g = gradients[i % gradients.length];
      const em = emojis[i % emojis.length];
      const fecha = ev.fecha ? new Date(ev.fecha + 'T12:00:00').toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
      }) : '—';
      const thumb = ev.Imagen_url ? `<img src="${escHtml(ev.Imagen_url)}" style="width:100%;height:80px;object-fit:cover;border-radius:10px;margin-bottom:10px" onerror="this.parentNode.innerHTML='<div style=height:80px;display:flex;align-items:center;justify-content:center;font-size:36px;margin-bottom:10px class=${g}>${em}</div>'">` :
        `<div style="height:80px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:36px;margin-bottom:10px" class="${g}">${em}</div>`;
      return `<div class="event-card" onclick="openModalEvento(${JSON.stringify(ev).replace(/"/g,'&quot;')})">
    <div class="event-card-header">
      ${thumb}
      <div class="ev-sport-tag tag-blue">📅 Evento</div>
      <div class="ev-title">${escHtml(ev.nombre)}</div>
      <div class="ev-venue">📍 ${escHtml(ev.lugar||'Sin sede')}</div>
    </div>
    <div class="event-card-footer">
      <div class="ev-date-chip">${fecha}</div>
      <div class="ev-action">Ver detalles →</div>
    </div>
  </div>`;
    }

    function renderCursosDash(list) {
      const el = document.getElementById('dashCursos');
      el.innerHTML = list.length ? list.map((cu, i) => cursoCardHTML(cu, i)).join('') : '<div class="no-data">🎓 No hay cursos registrados aún</div>';
    }

    function renderCursosPage(list) {
      const el = document.getElementById('allCursos');
      el.innerHTML = list.length ? list.map((cu, i) => cursoCardHTML(cu, i)).join('') : '<div class="no-data">🎓 No hay cursos registrados aún</div>';
    }

    function cursoCardHTML(cu, i) {
      const g = gradients[i % gradients.length];
      const em = emojis[i % emojis.length];
      const fi = cu.fecha_inicio ? new Date(cu.fecha_inicio + 'T12:00:00').toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short'
      }) : '—';
      const ff = cu.fecha_fin ? new Date(cu.fecha_fin + 'T12:00:00').toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short'
      }) : '—';
      const costo = cu.costo ? `💰 $${parseFloat(cu.costo).toFixed(2)}` : '💰 Gratuito';
      return `<div class="course-card" onclick="openModalCurso(${JSON.stringify(cu).replace(/"/g,'&quot;')})">
    <div class="cc-header ${g}" style="cursor:pointer">${em}</div>
    <div class="cc-body">
      <div class="cc-title">${escHtml(cu.nombre)}</div>
      <div class="cc-desc">${escHtml(cu.descripcion||'Sin descripción')}</div>
      <div class="cc-meta"><span>📅 ${fi} – ${ff}</span><span>${costo}</span></div>
      ${cu.Requisitos ? `<div class="cc-req">📋 ${escHtml(cu.Requisitos)}</div>` : ''}
    </div>
  </div>`;
    }

    function renderContenidoPage(list) {
      const el = document.getElementById('allContenido');
      if (!list.length) {
        el.innerHTML = '<div class="no-data">📰 No hay contenido publicado aún</div>';
        return;
      }
      const typeIcon = {
        video: '🎬',
        imagen: '🖼️',
        infografia: '📊',
        noticia: '📰'
      };
      el.innerHTML = list.map((c, i) => {
        const ic = typeIcon[c.tipo] || '📄';
        const fecha = c.fecha ? new Date(c.fecha + 'T12:00:00').toLocaleDateString('es-MX', {
          day: '2-digit',
          month: 'short',
          year: 'numeric'
        }) : '—';
        const thumb = c.url && (c.url.match(/\.(jpg|jpeg|png|gif|webp)/i) || c.url.startsWith('uploads/')) ?
          `<div class="mc-thumb" style="background:none"><img src="${escHtml(c.url)}" style="width:100%;height:130px;object-fit:cover" onerror="this.parentNode.innerHTML='${ic}'"></div>` :
          `<div class="mc-thumb ${gradients[i%gradients.length]}">${ic}</div>`;
        return `<div class="media-card">
      ${thumb}
      <div class="mc-body">
        <div class="mc-type">${c.tipo||'general'}</div>
        <div class="mc-title">${escHtml(c.titulo)}</div>
        <div class="mc-meta">${fecha} · ${escHtml(c.autor||'Redacción')}</div>
        ${c.url&&!c.url.startsWith('uploads/') ? `<a href="${escHtml(c.url)}" target="_blank" style="font-size:11px;color:var(--blue);font-weight:700">Ver enlace →</a>` : ''}
      </div>
    </div>`;
      }).join('');
    }

    // ═══════════════════════════════════════════════
    //  MIS INSCRIPCIONES
    // ═══════════════════════════════════════════════
    async function loadMisInscripciones() {
      const data = await apiFetch({
        action: 'mis_inscripciones'
      });
      const el = document.getElementById('listaMisInscripciones');
      if (!data.length) {
        el.innerHTML = '<div class="no-data">📋 No tienes inscripciones aún. <a style="color:var(--blue);cursor:pointer;font-weight:700" onclick="navigate(\'cursos\',null)">Ver cursos disponibles →</a></div>';
        return;
      }
      el.innerHTML = data.map(ins => {
        const fi = ins.fecha_inicio ? new Date(ins.fecha_inicio + 'T12:00:00').toLocaleDateString('es-MX', {
          day: '2-digit',
          month: 'short',
          year: 'numeric'
        }) : '—';
        const ff = ins.fecha_fin ? new Date(ins.fecha_fin + 'T12:00:00').toLocaleDateString('es-MX', {
          day: '2-digit',
          month: 'short',
          year: 'numeric'
        }) : '—';
        const costo = ins.costo ? `$${parseFloat(ins.costo).toFixed(2)}` : 'Gratuito';
        return `<div class="insc-card">
      <div class="insc-icon">🎓</div>
      <div class="insc-info">
        <div class="insc-nombre">${escHtml(ins.nombre)}</div>
        <div class="insc-meta">📅 ${fi} – ${ff} &nbsp;·&nbsp; 💰 ${costo}</div>
        <div style="margin-top:6px"><span class="estado-chip estado-${ins.estado||'pendiente'}">${ins.estado||'pendiente'}</span></div>
        <div class="insc-actions">
          <button class="btn-publish" style="padding:7px 14px;font-size:12px" onclick="generarTicket(${ins.id_inscripcion})">🎫 Generar Ticket de Pago</button>
          <button class="tbl-btn danger" onclick="cancelarInscripcion(${ins.id_inscripcion})">✕ Cancelar</button>
        </div>
      </div>
    </div>`;
      }).join('');
    }

    async function cancelarInscripcion(id) {
      if (!confirm('¿Cancelar esta inscripción?')) return;
      const res = await apiFetch({
        action: 'cancelar_inscripcion',
        id
      });
      if (res.ok) {
        showToast('✅ Inscripción cancelada', 'info');
        loadMisInscripciones();
      }
    }

    async function inscribirCurso(id_curso) {
      if (!STATE.user) {
        openAuth('login');
        return;
      }
      const res = await apiFetch({
        action: 'inscribir_curso',
        id_curso
      });
      if (res.ok) {
        showToast('✅ ¡Inscripción exitosa!', 'success');
        closeModal('modalCurso');
        if (document.getElementById('page-misinscripciones').classList.contains('active')) loadMisInscripciones();
      } else showToast('⚠️ ' + res.msg, 'warning');
    }

    // ═══════════════════════════════════════════════
    //  TICKET DE PAGO (basado en tiket.html)
    // ═══════════════════════════════════════════════
    async function generarTicket(id_inscripcion) {
      const res = await apiFetch({
        action: 'datos_ticket',
        id_inscripcion
      });
      if (!res.ok) {
        showToast('⚠️ No se pudo obtener los datos del ticket', 'error');
        return;
      }
      const d = res.datos;

      function generarLineaCaptura() {
        let l = '';
        for (let i = 0; i < 27; i++) l += Math.floor(Math.random() * 10);
        return l;
      }
      const lineaRaw = generarLineaCaptura();
      const lineaVisual = `${lineaRaw.substring(0,6)} ${lineaRaw.substring(6,12)} ${lineaRaw.substring(12,18)} ${lineaRaw.substring(18,24)} ${lineaRaw.substring(24,27)}`;
      const fechaActual = new Date();
      const fechaEmision = fechaActual.toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric'
      });
      const fechaLimiteObj = new Date();
      fechaLimiteObj.setDate(fechaActual.getDate() + 15);
      const fechaLimite = fechaLimiteObj.toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric'
      });
      const totalPagar = d.costo ? parseFloat(d.costo).toFixed(2) : '0.00';
      const nombre = (d.nombre || '').toUpperCase();
      const rfc = (d.rfc || 'SIN DATO').toUpperCase();
      const curp = (d.curp || 'SIN DATO').toUpperCase();
      const noCtrl = d.no_control || '—';
      const concepto = d.curso_nombre || 'Inscripción a curso';

      const html = `
  <style>
    .fup-page{font-family:Arial,sans-serif;color:#000;padding:40px;border:1px solid #ccc;line-height:1.1}
    .fup-header{display:grid;grid-template-columns:1fr 2fr 1fr;align-items:center;margin-bottom:20px}
    .header-text{text-align:center;font-size:11px;font-weight:bold}
    .header-right{text-align:right;font-size:13px;font-weight:bold}
    .barcode-section{display:flex;justify-content:space-between;margin-top:10px}
    .barcode-area{width:60%}
    .summary-area{width:35%;text-align:right}
    .section-title{border-top:2px solid #000;border-bottom:2px solid #000;font-weight:bold;font-size:11px;padding:2px 5px;margin:15px 0 5px 0}
    .data-table{width:100%;font-size:10px;margin-bottom:10px}
    .label{font-size:8px;font-weight:bold;display:block;margin-top:5px}
    .contribucion-table{width:100%;border-collapse:collapse;font-size:10px}
    .contribucion-table th{border-bottom:1px solid #000;text-align:left;padding:5px}
    .contribucion-table td{padding:5px}
    .bancos-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;font-size:8px;text-align:center;margin-top:10px}
    .footer-legal{font-size:8.5px;text-align:justify;margin-top:20px;line-height:1.3}
  </style>
  <div class="fup-page">
    <div class="fup-header">
      <div style="font-size:9px"><strong>GOBIERNO DEL<br>ESTADO DE<br>MÉXICO</strong></div>
      <div class="header-text">SECRETARÍA DE EDUCACIÓN<br>DIRECCIÓN DE DEPORTES<br>MUNICIPIO DE XONACATLAN</div>
      <div class="header-right">DEPORTES</div>
    </div>
    <div class="barcode-section">
      <div class="barcode-area">
        <div style="font-size:10px;font-weight:bold">LINEA DE CAPTURA PARA PAGO EN VENTANILLA</div>
        <svg id="barcode_ticket"></svg>
        <div style="font-size:15px;font-weight:bold;letter-spacing:1px">${lineaVisual}</div>
        <div style="font-size:9px;margin-top:2px">POR FAVOR CAPTURE SIN ESPACIOS</div>
      </div>
      <div class="summary-area">
        <div style="font-size:14px;font-weight:bold;margin-bottom:15px">FORMATO UNIVERSAL DE PAGO<br>FORMATO GRATUITO</div>
        <div style="font-size:10px">
          Fecha de emisión: &nbsp;&nbsp;&nbsp; ${fechaEmision}<br>
          Fecha límite de pago: &nbsp; ${fechaLimite}
        </div>
        <div style="font-size:18px;font-weight:bold;margin-top:20px">Total a pagar: $ ${totalPagar}</div>
      </div>
    </div>
    <div class="section-title">DATOS DEL CONTRIBUYENTE</div>
    <table class="data-table">
      <tr>
        <td width="33%"><span class="label">RFC</span>${escHtml(rfc)}</td>
        <td width="33%"><span class="label">CURP</span>${escHtml(curp)}</td>
        <td width="33%"><span class="label">NO. CONTROL</span>${escHtml(noCtrl)}</td>
      </tr>
      <tr>
        <td colspan="2"><span class="label">NOMBRE, DENOMINACIÓN O RAZÓN SOCIAL</span>${escHtml(nombre)}</td>
        <td><span class="label">OBSERVACIONES</span>SIN OBSERVACIONES</td>
      </tr>
    </table>
    <div class="section-title">DATOS DE LA CONTRIBUCIÓN</div>
    <table class="contribucion-table">
      <thead><tr><th>CLAVE</th><th>DESCRIPCIÓN</th><th style="text-align:right">CANTIDAD</th><th style="text-align:right">TARIFA O TASA</th><th style="text-align:right">SUBTOTAL</th></tr></thead>
      <tbody>
        <tr>
          <td>977007</td>
          <td>${escHtml(concepto)}</td>
          <td style="text-align:right">1</td>
          <td style="text-align:right">$ ${totalPagar}</td>
          <td style="text-align:right">$ ${totalPagar}</td>
        </tr>
      </tbody>
    </table>
    <div style="text-align:right;margin-top:10px">
      <div style="font-size:12px;font-weight:bold">TOTAL A PAGAR: $ ${totalPagar}</div>
      <div style="font-size:10px">PAGAR EN UNA SOLA EXHIBICIÓN</div>
    </div>
    <div style="text-align:center;margin-top:25px">
      <div style="font-size:9px;font-weight:bold;margin-bottom:10px">PAGO EN VENTANILLA CON LAS SIGUIENTES INSTITUCIONES AUTORIZADAS</div>
      <div class="bancos-grid">
        <div>AFIRME TRN0846<br>BANORTE-IXE 131017<br>COMERCIAL CITY FRESKO</div>
        <div>BANAMEX PA: 4122/01<br>BANREGIO<br>FINANCIERA PARA EL BIENESTAR</div>
        <div>BANCO AZTECA<br>BBVA CIE1336150<br>HSBC RAP 7131<br>SANTANDER 0009619</div>
        <div>BANCO DEL BAJÍO 1009<br>CHEDRAUI<br>OXXO<br>SCOTIABANK 3793</div>
        <div>SORIANA</div>
        <div style="grid-column:span 2">FARM. GUADALAJARA / SUPER KOMPRAS</div>
      </div>
    </div>
    <div style="text-align:center;margin-top:15px;font-size:10px;font-weight:bold">TRANSFERENCIA INTERBANCARIA</div>
    <div style="font-size:9px;text-align:center;margin-top:5px">
      Banco Destino: HSBC | Nombre del Beneficiario: Gobierno del Estado de México<br>
      CLABE: 021180550300071311 | Concepto: Colocar línea de captura a 27 dígitos sin espacios
    </div>
    <div class="footer-legal">
      ESTE DOCUMENTO NO ES EL COMPROBANTE DE PAGO, SÓLO ES VÁLIDO CON LA CERTIFICACIÓN O COMPROBANTE DE PAGO EMITIDO POR LA INSTITUCIÓN DE CRÉDITO O ESTABLECIMIENTOS MERCANTILES AUTORIZADOS.<br><br>
      Por favor verifique que la línea de captura y el importe que aparece en el comprobante de pago coincidan con la información impresa en este formato universal de pago.<br><br>
      CON FUNDAMENTO EN LOS ARTÍCULOS 107 Y 176 DEL CÓDIGO FINANCIERO DEL ESTADO DE MÉXICO Y MUNICIPIOS, MANIFIESTO BAJO PROTESTA DE DECIR VERDAD QUE SON CIERTOS LOS DATOS QUE SE MUESTRAN EN LA PRESENTE DECLARACIÓN.
    </div>
  </div>`;

      const area = document.getElementById('ticketPrintArea');
      area.innerHTML = html;
      area.style.display = 'block';

      // Generar código de barras
      setTimeout(() => {
        if (typeof JsBarcode !== 'undefined') {
          JsBarcode("#barcode_ticket", lineaRaw, {
            format: "CODE128",
            displayValue: false,
            height: 50,
            width: 1.4,
            margin: 0
          });
        }
        window.print();
        area.style.display = 'none';
        area.innerHTML = '';
      }, 300);
    }

    // ═══════════════════════════════════════════════
    //  ADMIN: Tablas
    // ═══════════════════════════════════════════════
    async function loadAdminEventos() {
      const data = await apiFetch({
        action: 'get_eventos'
      });
      const tb = document.getElementById('tbEventos');
      if (!data.length) {
        tb.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text3)">Sin registros</td></tr>';
        return;
      }
      tb.innerHTML = data.map(ev => `<tr>
    <td><strong>${escHtml(ev.nombre)}</strong></td>
    <td>${ev.fecha||'—'}</td>
    <td>${escHtml(ev.lugar||'—')}</td>
    <td>${ev.Imagen_url?`<img src="${escHtml(ev.Imagen_url)}" style="height:36px;border-radius:4px;object-fit:cover" onerror="this.style.display='none'">`:'-'}</td>
    <td><div class="tbl-actions">
      <button class="btn-edit-row" onclick="editEvento(${JSON.stringify(ev).replace(/"/g,'&quot;')})">✏️ Editar</button>
      <button class="tbl-btn danger" onclick="eliminarEvento(${ev.id_evento})">🗑 Borrar</button>
    </div></td>
  </tr>`).join('');
    }

    async function loadAdminCursos() {
      const data = await apiFetch({
        action: 'get_cursos'
      });
      const tb = document.getElementById('tbCursos');
      if (!data.length) {
        tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text3)">Sin registros</td></tr>';
        return;
      }
      tb.innerHTML = data.map(cu => `<tr>
    <td><strong>${escHtml(cu.nombre)}</strong></td>
    <td>${cu.fecha_inicio||'—'}</td>
    <td>${cu.fecha_fin||'—'}</td>
    <td>$${parseFloat(cu.costo||0).toFixed(2)}</td>
    <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(cu.Requisitos||'—')}</td>
    <td><div class="tbl-actions">
      <button class="btn-edit-row" onclick="editCurso(${JSON.stringify(cu).replace(/"/g,'&quot;')})">✏️ Editar</button>
      <button class="tbl-btn danger" onclick="eliminarCurso(${cu.id_curso})">🗑 Borrar</button>
    </div></td>
  </tr>`).join('');
    }

    async function loadAdminContenido() {
      const data = await apiFetch({
        action: 'get_contenido'
      });
      const tb = document.getElementById('tbContenido');
      if (!data.length) {
        tb.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text3)">Sin registros</td></tr>';
        return;
      }
      tb.innerHTML = data.map(c => `<tr>
    <td><strong>${escHtml(c.titulo)}</strong></td>
    <td>${escHtml(c.tipo||'—')}</td>
    <td>${c.fecha||'—'}</td>
    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
      ${c.url?(c.url.startsWith('uploads/')?`<img src="${escHtml(c.url)}" style="height:30px;border-radius:4px;object-fit:cover">`:`<a href="${escHtml(c.url)}" target="_blank" style="color:var(--blue)">Ver ↗</a>`):'—'}
    </td>
    <td><div class="tbl-actions">
      <button class="btn-edit-row" onclick="editContenido(${JSON.stringify(c).replace(/"/g,'&quot;')})">✏️ Editar</button>
      <button class="tbl-btn danger" onclick="eliminarContenido(${c.id_contenido})">🗑 Borrar</button>
    </div></td>
  </tr>`).join('');
    }

    async function loadAdminUsuarios() {
      const data = await apiFetch({
        action: 'get_usuarios'
      });
      const tb = document.getElementById('tbUsuarios');
      if (!data.length) {
        tb.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text3)">Sin registros</td></tr>';
        return;
      }
      tb.innerHTML = data.map(u => `<tr>
    <td>${u.id_usuario}</td>
    <td><strong>${escHtml(u.nombre)}</strong></td>
    <td>${escHtml(u.correo)}</td>
    <td><span style="font-size:11px;font-weight:700;background:${u.rol==='admin'?'var(--blue-soft)':'var(--green-soft)'};color:${u.rol==='admin'?'var(--blue)':'var(--green)'};padding:3px 10px;border-radius:20px">${u.rol}</span></td>
    <td><div class="tbl-actions">
      <button class="tbl-btn" onclick="cambiarRol(${u.id_usuario},'${u.rol==='admin'?'usuario':'admin'}')">⇄ ${u.rol==='admin'?'→ usuario':'→ admin'}</button>
      <button class="tbl-btn danger" onclick="eliminarUsuario(${u.id_usuario})">🗑 Borrar</button>
    </div></td>
  </tr>`).join('');
    }

    async function loadAdminInscripciones() {
      const data = await apiFetch({
        action: 'get_inscripciones'
      });
      const tb = document.getElementById('tbInscripciones');
      if (!Array.isArray(data) || !data.length) {
        tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text3)">Sin registros</td></tr>';
        return;
      }
      tb.innerHTML = data.map(ins => `<tr>
    <td>${ins.id_inscripcion}</td>
    <td><strong>${escHtml(ins.usuario)}</strong><br><small style="color:var(--text3)">${escHtml(ins.correo)}</small></td>
    <td>${escHtml(ins.curso)}</td>
    <td>${ins.fecha_inscripcion?ins.fecha_inscripcion.substring(0,10):'—'}</td>
    <td><span class="estado-chip estado-${ins.estado||'pendiente'}">${ins.estado||'pendiente'}</span></td>
    <td><div class="tbl-actions">
      <select class="form-select" style="width:auto;padding:4px 8px;font-size:11px" onchange="cambiarEstadoInscripcion(${ins.id_inscripcion},this.value)">
        <option value="pendiente" ${ins.estado==='pendiente'?'selected':''}>Pendiente</option>
        <option value="activo" ${ins.estado==='activo'?'selected':''}>Activo</option>
        <option value="cancelado" ${ins.estado==='cancelado'?'selected':''}>Cancelado</option>
      </select>
    </div></td>
  </tr>`).join('');
    }

    // ═══════════════════════════════════════════════
    //  CRUD ADMIN
    // ═══════════════════════════════════════════════
    function showFormEvento() {
      const w = document.getElementById('formEventoWrap');
      document.getElementById('formEventoTitulo').textContent = 'Nuevo Evento';
      document.getElementById('ev-id').value = '';
      ['ev-nombre', 'ev-fecha', 'ev-lugar', 'ev-img', 'ev-desc'].forEach(id => document.getElementById(id).value = '');
      resetFileInput('ev-img-file', 'evPreview', 'evImgLabel', '📁 Haz clic o arrastra una imagen aquí');
      w.style.display = 'block';
      w.scrollIntoView({
        behavior: 'smooth'
      });
    }

    function editEvento(ev) {
      const w = document.getElementById('formEventoWrap');
      document.getElementById('formEventoTitulo').textContent = 'Editar Evento';
      document.getElementById('ev-id').value = ev.id_evento;
      document.getElementById('ev-nombre').value = ev.nombre || '';
      document.getElementById('ev-fecha').value = ev.fecha || '';
      document.getElementById('ev-lugar').value = ev.lugar || '';
      document.getElementById('ev-img').value = ev.Imagen_url || '';
      document.getElementById('ev-desc').value = ev.descripcion || '';
      if (ev.Imagen_url) {
        const p = document.getElementById('evPreview');
        p.src = ev.Imagen_url;
        p.style.display = 'block';
      }
      w.style.display = 'block';
      w.scrollIntoView({
        behavior: 'smooth'
      });
    }

    async function guardarEvento() {
      const id = document.getElementById('ev-id').value;
      const nombre = document.getElementById('ev-nombre').value.trim();
      if (!nombre) {
        showToast('⚠️ El nombre es obligatorio', 'warning');
        return;
      }
      const data = {
        nombre,
        descripcion: document.getElementById('ev-desc').value,
        fecha: document.getElementById('ev-fecha').value,
        lugar: document.getElementById('ev-lugar').value,
        imagen_url: document.getElementById('ev-img').value
      };
      data.action = id ? 'editar_evento' : 'crear_evento';
      if (id) data.id = id;
      const res = await apiFetch(data, {
        imagen_file: document.getElementById('ev-img-file')
      });
      if (res.ok) {
        showToast(id ? '✅ Evento actualizado' : '✅ Evento creado', 'success');
        document.getElementById('formEventoWrap').style.display = 'none';
        loadAdminEventos();
        loadEventos();
      } else showToast('❌ Error al guardar', 'error');
    }

    async function eliminarEvento(id) {
      if (!confirm('¿Eliminar este evento?')) return;
      const res = await apiFetch({
        action: 'eliminar_evento',
        id
      });
      if (res.ok) {
        showToast('🗑 Evento eliminado', 'info');
        loadAdminEventos();
        loadEventos();
      }
    }

    function showFormCurso() {
      const w = document.getElementById('formCursoWrap');
      document.getElementById('formCursoTitulo').textContent = 'Nuevo Curso';
      document.getElementById('cu-id').value = '';
      ['cu-nombre', 'cu-fi', 'cu-ff', 'cu-req', 'cu-desc', 'cu-costo'].forEach(id => document.getElementById(id).value = '');
      w.style.display = 'block';
      w.scrollIntoView({
        behavior: 'smooth'
      });
    }

    function editCurso(cu) {
      const w = document.getElementById('formCursoWrap');
      document.getElementById('formCursoTitulo').textContent = 'Editar Curso';
      document.getElementById('cu-id').value = cu.id_curso;
      document.getElementById('cu-nombre').value = cu.nombre || '';
      document.getElementById('cu-fi').value = cu.fecha_inicio || '';
      document.getElementById('cu-ff').value = cu.fecha_fin || '';
      document.getElementById('cu-req').value = cu.Requisitos || '';
      document.getElementById('cu-desc').value = cu.descripcion || '';
      document.getElementById('cu-costo').value = cu.costo || '';
      w.style.display = 'block';
      w.scrollIntoView({
        behavior: 'smooth'
      });
    }

    async function guardarCurso() {
      const id = document.getElementById('cu-id').value;
      const nombre = document.getElementById('cu-nombre').value.trim();
      if (!nombre) {
        showToast('⚠️ El nombre es obligatorio', 'warning');
        return;
      }
      const data = {
        nombre,
        descripcion: document.getElementById('cu-desc').value,
        fecha_inicio: document.getElementById('cu-fi').value,
        fecha_fin: document.getElementById('cu-ff').value,
        requisitos: document.getElementById('cu-req').value,
        costo: document.getElementById('cu-costo').value || 0
      };
      data.action = id ? 'editar_curso' : 'crear_curso';
      if (id) data.id = id;
      const res = await apiFetch(data);
      if (res.ok) {
        showToast(id ? '✅ Curso actualizado' : '✅ Curso creado', 'success');
        document.getElementById('formCursoWrap').style.display = 'none';
        loadAdminCursos();
        loadCursos();
      } else showToast('❌ Error al guardar', 'error');
    }

    async function eliminarCurso(id) {
      if (!confirm('¿Eliminar este curso?')) return;
      const res = await apiFetch({
        action: 'eliminar_curso',
        id
      });
      if (res.ok) {
        showToast('🗑 Curso eliminado', 'info');
        loadAdminCursos();
        loadCursos();
      }
    }

    function showFormContenido() {
      const w = document.getElementById('formContenidoWrap');
      document.getElementById('formContenidoTitulo').textContent = 'Nuevo Contenido';
      document.getElementById('co-id').value = '';
      ['co-titulo', 'co-url', 'co-fecha', 'co-desc'].forEach(id => document.getElementById(id).value = '');
      document.getElementById('co-tipo').value = 'noticia';
      resetFileInput('co-img-file', 'coPreview', 'coImgLabel', '📁 Haz clic o arrastra una imagen aquí');
      w.style.display = 'block';
      w.scrollIntoView({
        behavior: 'smooth'
      });
    }

    function editContenido(c) {
      const w = document.getElementById('formContenidoWrap');
      document.getElementById('formContenidoTitulo').textContent = 'Editar Contenido';
      document.getElementById('co-id').value = c.id_contenido;
      document.getElementById('co-titulo').value = c.titulo || '';
      document.getElementById('co-tipo').value = c.tipo || 'noticia';
      document.getElementById('co-fecha').value = c.fecha || '';
      document.getElementById('co-url').value = c.url || '';
      document.getElementById('co-desc').value = c.descripcion || '';
      if (c.url) {
        const p = document.getElementById('coPreview');
        p.src = c.url;
        p.style.display = 'block';
      }
      w.style.display = 'block';
      w.scrollIntoView({
        behavior: 'smooth'
      });
    }

    async function guardarContenido() {
      const id = document.getElementById('co-id').value;
      const titulo = document.getElementById('co-titulo').value.trim();
      if (!titulo) {
        showToast('⚠️ El título es obligatorio', 'warning');
        return;
      }
      const data = {
        titulo,
        descripcion: document.getElementById('co-desc').value,
        tipo: document.getElementById('co-tipo').value,
        url: document.getElementById('co-url').value,
        fecha: document.getElementById('co-fecha').value || new Date().toISOString().split('T')[0]
      };
      data.action = id ? 'editar_contenido' : 'crear_contenido';
      if (id) data.id = id;
      const res = await apiFetch(data, {
        imagen_file: document.getElementById('co-img-file')
      });
      if (res.ok) {
        showToast(id ? '✅ Contenido actualizado' : '✅ Contenido creado', 'success');
        document.getElementById('formContenidoWrap').style.display = 'none';
        loadAdminContenido();
        loadContenido();
      } else showToast('❌ Error al guardar', 'error');
    }

    async function eliminarContenido(id) {
      if (!confirm('¿Eliminar este contenido?')) return;
      const res = await apiFetch({
        action: 'eliminar_contenido',
        id
      });
      if (res.ok) {
        showToast('🗑 Contenido eliminado', 'info');
        loadAdminContenido();
        loadContenido();
      }
    }

    async function cambiarRol(id, nuevoRol) {
      if (!confirm(`¿Cambiar rol a "${nuevoRol}"?`)) return;
      const res = await apiFetch({
        action: 'cambiar_rol',
        id,
        rol: nuevoRol
      });
      if (res.ok) {
        showToast('✅ Rol actualizado', 'success');
        loadAdminUsuarios();
      }
    }

    async function eliminarUsuario(id) {
      if (!confirm('¿Eliminar este usuario permanentemente?')) return;
      const res = await apiFetch({
        action: 'eliminar_usuario',
        id
      });
      if (res.ok) {
        showToast('🗑 Usuario eliminado', 'info');
        loadAdminUsuarios();
      }
    }

    async function cambiarEstadoInscripcion(id, estado) {
      const res = await apiFetch({
        action: 'cambiar_estado_inscripcion',
        id,
        estado
      });
      if (res.ok) showToast('✅ Estado actualizado', 'success');
    }

    // ═══════════════════════════════════════════════
    //  navegacion
    // ═══════════════════════════════════════════════
    const pageTitles = {
      dashboard: 'Dashboard',
      eventos: 'Eventos',
      cursos: 'Cursos',
      contenido: 'Noticias',
      misinscripciones: 'Mis Inscripciones',
      admin: 'Panel Admin'
    };

    function navigate(page, navEl) {
      if (page === 'admin' && !STATE.isAdmin) {
        showToast('⛔ Solo administradores', 'error');
        return;
      }
      if ((page === 'misinscripciones') && !STATE.user) {
        openAuth('login');
        return;
      }
      document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
      document.getElementById('page-' + page).classList.add('active');
      if (navEl) navEl.classList.add('active');
      document.getElementById('topbarTitle').textContent = pageTitles[page] || page;
      if (page === 'admin') {
        loadAdminEventos();
        loadAdminCursos();
        loadAdminContenido();
        loadAdminUsuarios();
        loadAdminInscripciones();
        loadStats();
      }
      if (page === 'misinscripciones') loadMisInscripciones();
    }

    function switchAdmTab(idx, el) {
      document.querySelectorAll('.adm-tab').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.adm-panel').forEach(p => p.classList.remove('active'));
      el.classList.add('active');
      document.getElementById('adm-' + idx).classList.add('active');
    }

    // ═══════════════════════════════════════════════
    //  MODALS (ventanas emergentes)
    // ═══════════════════════════════════════════════
    function openModalEvento(ev) {
      document.getElementById('mEvNombre').textContent = ev.nombre || '—';
      document.getElementById('mEvDesc').textContent = ev.descripcion || 'Sin descripción';
      document.getElementById('mEvLugar').textContent = ev.lugar || '—';
      document.getElementById('mEvFecha').textContent = ev.fecha ? new Date(ev.fecha + 'T12:00:00').toLocaleDateString('es-MX', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      }) : '—';
      const imgDiv = document.getElementById('mEvImg');
      if (ev.Imagen_url) {
        imgDiv.innerHTML = `<img src="${escHtml(ev.Imagen_url)}" style="width:100%;height:180px;object-fit:cover" onerror="this.parentNode.innerHTML='⚽'">`;
      } else {
        imgDiv.textContent = '⚽';
      }
      openModal('modalEvento');
    }

    function openModalCurso(cu) {
      document.getElementById('mCuNombre').textContent = cu.nombre || '—';
      document.getElementById('mCuDesc').textContent = cu.descripcion || 'Sin descripción';
      document.getElementById('mCuInicio').textContent = cu.fecha_inicio || '—';
      document.getElementById('mCuFin').textContent = cu.fecha_fin || '—';
      document.getElementById('mCuCosto').textContent = cu.costo ? `$${parseFloat(cu.costo).toFixed(2)}` : 'Gratuito';
      const req = document.getElementById('mCuReq');
      if (cu.Requisitos) {
        req.textContent = '📋 ' + cu.Requisitos;
        req.style.display = 'block';
      } else req.style.display = 'none';
      // Botón inscripción
      const actions = document.getElementById('mCuActions');
      if (STATE.user && !STATE.isAdmin) {
        actions.innerHTML = `<button class="btn-publish" onclick="inscribirCurso(${cu.id_curso})">✅ Inscribirme a este curso</button>`;
      } else if (!STATE.user) {
        actions.innerHTML = `<button class="btn-publish" onclick="openAuth('login');closeModal('modalCurso')">🔑 Inicia sesión para inscribirte</button>`;
      } else {
        actions.innerHTML = '';
      }
      openModal('modalCurso');
    }

    function openModal(id) {
      document.getElementById(id).classList.add('open');
      document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
      document.getElementById(id).classList.remove('open');
      document.body.style.overflow = '';
    }

    document.getElementById('authOverlay').addEventListener('click', function(e) {
      if (e.target === this) closeAuth();
    });
    document.querySelectorAll('.modal-overlay').forEach(m => {
      m.addEventListener('click', e => {
        if (e.target === m) {
          m.classList.remove('open');
          document.body.style.overflow = '';
        }
      });
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => {
          m.classList.remove('open');
          document.body.style.overflow = '';
        });
      }
    });

    // ═══════════════════════════════════════════════
    //  menu 
    // ═══════════════════════════════════════════════
    function toggleSidebar() {
      const sb = document.getElementById('sidebar'),
        mn = document.getElementById('main');
      sb.classList.toggle('collapsed');
      mn.classList.toggle('collapsed');
      document.getElementById('collapseBtn').textContent = sb.classList.contains('collapsed') ? '▶' : '◀';
    }

    // ═══════════════════════════════════════════════
    //  filtro de busqueda pendiente 
    // ═══════════════════════════════════════════════
    function filterEventos(cat, el) {
      document.getElementById('ftabsEventos').querySelectorAll('.ftab').forEach(t => t.classList.remove('active'));
      el.classList.add('active');
      if (cat === 'todos') {
        renderEventosPage(STATE.eventos);
        return;
      }
      const filtered = STATE.eventos.filter(ev => (ev.nombre || '').toLowerCase().includes(cat) || (ev.descripcion || '').toLowerCase().includes(cat));
      renderEventosPage(filtered.length ? filtered : STATE.eventos);
    }

    function handleSearch(q) {
      q = q.toLowerCase().trim();
      if (!q) {
        renderEventosDash(STATE.eventos.slice(0, 3));
        return;
      }
      const fe = STATE.eventos.filter(ev => (ev.nombre || '').toLowerCase().includes(q) || (ev.lugar || '').toLowerCase().includes(q));
      renderEventosDash(fe.slice(0, 3));
    }

    // ═══════════════════════════════════════════════
    //  ayuda de subida
    // ═══════════════════════════════════════════════
    function previewImg(input, previewId, wrapId) {
      if (!input.files || !input.files[0]) return;
      const reader = new FileReader();
      reader.onload = e => {
        const p = document.getElementById(previewId);
        p.src = e.target.result;
        p.style.display = 'block';
      };
      reader.readAsDataURL(input.files[0]);
    }

    function resetFileInput(inputId, previewId, labelId, labelText) {
      const input = document.getElementById(inputId);
      if (input) input.value = '';
      const p = document.getElementById(previewId);
      if (p) {
        p.src = '';
        p.style.display = 'none';
      }
      const l = document.getElementById(labelId);
      if (l) l.textContent = labelText;
    }

    // ═══════════════════════════════════════════════
    //  notis
    // ═══════════════════════════════════════════════
    function showToast(msg, type = 'success') {
      const icons = {
        success: '✅',
        error: '❌',
        info: '💡',
        warning: '⚠️'
      };
      document.getElementById('toastMsg').textContent = msg;
      document.getElementById('toastIcon').textContent = icons[type] || '✅';
      const t = document.getElementById('toast');
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3500);
    }

    // ═══════════════════════════════════════════════
    //  revelar
    // ═══════════════════════════════════════════════
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((e, i) => {
        if (e.isIntersecting) {
          setTimeout(() => e.target.classList.add('visible'), i * 70);
          observer.unobserve(e.target);
        }
      });
    }, {
      threshold: 0.08,
      rootMargin: '0px 0px -30px 0px'
    });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

    // ═══════════════════════════════════════════════
    //  son utiles
    // ═══════════════════════════════════════════════
    function escHtml(str) {
      if (!str) return '';
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
  </script>
</body>

</html>