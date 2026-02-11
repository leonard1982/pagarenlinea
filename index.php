<?php
require_once __DIR__ . '/config.php';
// URL del logo de la empresa. Se recomienda mantenerla en un archivo de configuración.
$logoUrl  = 'https://www.datosdynamica.net/facturadorbeta/_lib/libraries/grp/login/image.png';

$availableBanks = [
  ['code' => '110', 'label' => 'BANDES'],
  ['code' => '153', 'label' => 'BBVA'],
  ['code' => '001', 'label' => 'BROU'],
  ['code' => '216', 'label' => 'Heritage'],
  ['code' => '157', 'label' => 'HSBC'],
  ['code' => '113', 'label' => 'Itaú'],
  ['code' => '137', 'label' => 'Santander'],
  ['code' => '128', 'label' => 'Scotiabank'],
];

$scriptVersion = date('Ymd_His');

$rut = '';

if (isset($_GET['x']) && $_GET['x'] !== '')
{
    $b64 = $_GET['x'];

    // Base64 URL-safe → base64 normal
    $b64 = strtr($b64, '-_', '+/');

    // Reponer padding "=" si hace falta
    $pad = strlen($b64) % 4;
    if ($pad)
    {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    $rut = base64_decode($b64, true);

    if ($rut === false)
    {
        $rut = '';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <!-- Metadatos básicos del documento -->
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Plataforma de Pagos</title>

  <!-- Tailwind CSS desde CDN para un prototipado rápido de estilos -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Fuentes de Google Fonts para una tipografía consistente -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>

  <!-- Hoja de estilos personalizada -->
  <link rel="stylesheet" href="css/index.css">

  <!-- Estilos en línea para personalizaciones rápidas y animaciones -->
  <style>
    body { font-family: 'Inter', sans-serif; }
    .fade-in { animation: fadeIn .5s ease-in-out; }
    @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
  </style>
</head>
<body class="bg-gray-50 text-gray-800">
  <!-- Contenedor principal que centra el contenido -->
  <div class="min-h-screen flex flex-col items-center justify-center p-4">
    <div id="payment-flow" class="w-full max-w-md mx-auto">

      <!-- PANTALLA 1: INGRESO DE RUT -->
      <div id="screen-rut" class="bg-white p-8 rounded-2xl shadow-sm border border-gray-200">
        <div class="text-center">
          <!-- Logo de la empresa -->
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo DYNÁMICA" class="mx-auto mb-4">
          <!-- Título y descripción principal -->
          <h1 class="text-3xl font-bold text-gray-900">Pagá tus facturas, fácil y rápido</h1>
          <p class="mt-3 text-gray-600">Consultá tus facturas pendientes y abonalas de forma segura con el Sistema de Pagos Electrónicos (SPE) de Sistarbanc</p>
        </div>

        <!-- Formulario de consulta -->
        <div class="mt-8 space-y-4">
          <div>
            <label for="rut" class="sr-only">RUT</label>
            <input type="text" id="rut" name="rut" placeholder="Ingresá tu RUT"
                <?php if ($rut !== ''): ?>value="<?= htmlspecialchars($rut, ENT_QUOTES, 'UTF-8') ?>" <?php endif; ?>
                oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                class="w-full px-4 py-3 bg-gray-100 border-gray-200 border rounded-lg text-gray-800 focus:outline-none focus:ring-2 focus:ring-red-500 transition-shadow duration-200"
                autocomplete="off">
          </div>

          <!-- Contenedores para mensajes de estado y loader -->
          <div id="msg" class="text-sm"></div>
          <div id="loader" class="hidden text-sm">Cargando...</div>

          <!-- Botón para iniciar la consulta de facturas -->
          <button id="consultar-btn"
                  type="button"
                  class="w-full bg-red-500 text-white font-semibold py-3 px-4 rounded-lg hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-all duration-300 transform hover:scale-105 shadow-sm">
            Consultar
          </button>
        </div>
      </div>

      <!-- PANTALLA 2: LISTADO DE FACTURAS -->
      <div id="screen-invoices" class="hidden bg-white p-8 rounded-2xl shadow-sm border border-gray-200 fade-in">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-2xl font-bold text-gray-900">Facturas Pendientes</h2>
          <!-- Botón para regresar a la pantalla de RUT -->
          <button id="volver-btn" type="button" class="text-sm font-medium text-red-600 hover:text-red-800">&larr; Volver</button>
        </div>

        <!-- Información de la empresa consultada -->
        <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
          <p class="text-sm text-gray-600">RUT: <span id="info-rut" class="font-semibold text-gray-800"></span></p>
          <p class="text-sm text-gray-600 mt-1">Razón Social: <span id="info-razon-social" class="font-semibold text-gray-800"></span></p>
        </div>

        <!-- Contenedor donde se inyectará dinámicamente la lista de facturas -->
        <div id="invoices-list" class="space-y-3"></div>

        <!-- Sección de total y botón de pago -->
        <div class="mt-8 border-t border-gray-200 pt-6 space-y-6">
          <div class="flex justify-between items-baseline mb-2">
            <p class="text-lg font-medium text-gray-600"><b>Total a pagar:</b></p>
            <!-- El total se actualizará dinámicamente -->
            <p class="text-3xl font-bold text-red-600" id="total-a-pagar">$ 0,00</p>
          </div>

          <div id="bank-section" class="hidden">
            <!--<p class="text-sm font-semibold text-gray-700 tracking-wide">Por favor seleccione el banco</p>-->
            <!--<div class="mt-3 space-y-2">
              <input id="bank-search"
                     type="search"
                     autocomplete="off"
                     placeholder="Buscar banco por código o nombre..."
                     class="w-full px-4 py-3 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-red-400 transition duration-200">-->
              <select id="bank-select"
                      class="w-full px-4 py-3 bg-white border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-400 transition duration-200 appearance-none">
              </select>
              <p id="bank-error" class="hidden text-sm text-red-600 font-medium">Seleccioná un banco para continuar.</p>
            </div>
          </div>
          <br>
          <!-- Botón para proceder al pago (funcionalidad futura) -->
          <button type="button" id="pagar-btn"
                  class="w-full bg-red-500 text-white font-bold py-4 px-4 rounded-lg hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-all duration-300 transform hover:scale-105 shadow-sm text-lg">
            Pagar
          </button>
        </div>
      </div>

    </div>
  </div>

  <script>
    window.PAGOS_APP = window.PAGOS_APP || {};
    window.PAGOS_APP.bancos = <?= json_encode($availableBanks, JSON_UNESCAPED_UNICODE) ?>;
    window.PAGOS_APP.env = <?= json_encode(trim((string)APP_ENV), JSON_UNESCAPED_UNICODE) ?>;
    //window.PAGOS_APP.env = <?= json_encode(APP_ENV, JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <!-- Script principal de la aplicación -->
  <script src="js/index.js?f=<?= htmlspecialchars($scriptVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
