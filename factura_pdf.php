<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/baseDeDatos.php';

const PDF_MAX_BYTES = 31457280;
const PDF_ALLOWED_HOSTS = ['appuy.migrate.info', 'webuy.migrate.info'];

function responderError(string $mensaje, int $estado = 400): void
{
    http_response_code($estado);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    $texto = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Documento no disponible</title><style>body{margin:0;background:#f9fafb;font-family:Arial,sans-serif;color:#1f2937}.box{max-width:520px;margin:12vh auto;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.05)}h1{font-size:24px;margin:0 0 12px}p{color:#4b5563;line-height:1.5}button{margin-top:14px;border:0;border-radius:8px;padding:11px 18px;background:#ef4444;color:#fff;font-weight:700;cursor:pointer}</style></head><body>';
    echo '<main class="box"><h1>Documento no disponible</h1><p>' . $texto . '</p><button type="button" onclick="window.close()">Cerrar</button></main></body></html>';
    exit;
}

function hostPermitido(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return in_array($host, PDF_ALLOWED_HOSTS, true);
}

function obtenerUrlAbsoluta(string $base, string $referencia): string
{
    $referencia = html_entity_decode(trim($referencia), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~^https://~i', $referencia)) {
        return $referencia;
    }
    $partes = parse_url($base);
    if (!$partes || empty($partes['scheme']) || empty($partes['host'])) {
        return '';
    }
    if (strpos($referencia, '//') === 0) {
        return $partes['scheme'] . ':' . $referencia;
    }
    $origen = $partes['scheme'] . '://' . $partes['host'];
    if (strpos($referencia, '/') === 0) {
        return $origen . $referencia;
    }
    $directorio = isset($partes['path']) ? rtrim(str_replace('\\', '/', dirname($partes['path'])), '/') : '';
    return $origen . ($directorio ? $directorio . '/' : '/') . $referencia;
}

function descargarRecurso(string $url): array
{
    if (!hostPermitido($url)) {
        throw new RuntimeException('Origen de documento no permitido.');
    }

    $contenido = '';
    $excedido = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'Dynamica-PagarEnLinea/1.0',
        CURLOPT_WRITEFUNCTION => static function ($curl, string $datos) use (&$contenido, &$excedido): int {
            if (strlen($contenido) + strlen($datos) > PDF_MAX_BYTES) {
                $excedido = true;
                return 0;
            }
            $contenido .= $datos;
            return strlen($datos);
        },
    ]);
    $ok = curl_exec($ch);
    $estado = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tipo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $urlFinal = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    if ($excedido) {
        throw new RuntimeException('El documento supera el tamaño permitido.');
    }
    if ($ok === false || $estado < 200 || $estado >= 300 || !hostPermitido($urlFinal)) {
        throw new RuntimeException($error !== '' ? 'No fue posible recuperar el documento.' : 'El proveedor no entregó el documento.');
    }
    return [$contenido, $tipo, $urlFinal];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderError('La solicitud no es válida.', 405);
}

$rut = preg_replace('/\D+/', '', (string)($_POST['rut'] ?? ''));
$idVenta = filter_var($_POST['idventa'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$accion = (string)($_POST['accion'] ?? 'ver');

if ($rut === '' || $idVenta === false || !in_array($accion, ['ver', 'descargar'], true)) {
    responderError('No fue posible identificar la factura solicitada.');
}

try {
    $conexion = new dbMysql($vhost, $vuser, $vpass, $vbd);
    $rutSeguro = $conexion->escape($rut);
    $sql = "SELECT v.Enlace_Pdf, v.Serie, v.Numero
            FROM Ventas v
            INNER JOIN Clientes c ON c.IdCliente = v.IdCliente AND c.IdEmpresa = v.IdEmpresa
            WHERE v.IdVentas = " . (int)$idVenta . "
              AND c.Documento = '" . $rutSeguro . "'
              AND v.VC = 'V'
              AND v.Saldo > 0
              AND v.TV = 'CREDITO'
              AND v.IdTipoDoc IN (101,111)
              AND v.IdEmpresa = '" . ID_EMPRESA_FIJO . "'
              AND v.Estado IN ('CFE Autorizado.','CFE Firmado.','CFE Enviado.')
              AND v.Moneda = 'UYU'
            LIMIT 1";
    $resultado = $conexion->consulta($sql);
    $factura = $resultado ? mysqli_fetch_assoc($resultado) : null;

    if (!$factura || trim((string)$factura['Enlace_Pdf']) === '') {
        responderError('El PDF de esta factura no se encuentra disponible.', 404);
    }

    [$pagina, $tipoPagina, $urlPagina] = descargarRecurso(trim((string)$factura['Enlace_Pdf']));
    $pdf = $pagina;

    if (strncmp($pagina, '%PDF-', 5) !== 0) {
        libxml_use_internal_errors(true);
        $documento = new DOMDocument();
        $documento->loadHTML($pagina);
        $iframe = $documento->getElementsByTagName('iframe')->item(0);
        if (!$iframe) {
            throw new RuntimeException('El proveedor no informó el archivo PDF.');
        }
        $urlPdf = obtenerUrlAbsoluta($urlPagina, (string)$iframe->getAttribute('src'));
        if ($urlPdf === '' || !hostPermitido($urlPdf)) {
            throw new RuntimeException('La ubicación del PDF no es válida.');
        }
        [$pdf] = descargarRecurso($urlPdf);
    }

    if (strncmp($pdf, '%PDF-', 5) !== 0) {
        throw new RuntimeException('El archivo recibido no es un PDF válido.');
    }

    $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Factura_' . $factura['Serie'] . $factura['Numero']) . '.pdf';
    $disposicion = $accion === 'descargar' ? 'attachment' : 'inline';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
} catch (Throwable $e) {
    error_log('factura_pdf: ' . $e->getMessage());
    responderError('No fue posible obtener el PDF en este momento. Intente nuevamente.', 502);
}
