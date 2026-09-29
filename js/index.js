const PAGOS_APP = window.PAGOS_APP || {};
const BANK_OPTIONS = Array.isArray(PAGOS_APP.bancos) ? PAGOS_APP.bancos : [];
//const APP_ENV = typeof PAGOS_APP.env === 'undefined' ? '' : PAGOS_APP.env;
//const IS_DESARROLLO = APP_ENV === 'DESARROLLO';
const APP_ENV = (PAGOS_APP.env || '').toString().trim().toUpperCase();
const IS_DESARROLLO = (APP_ENV === 'DESARROLLO');

const bankError = document.getElementById('bank-error');

function showBankError(message) {
  if (!bankError) return;
  bankError.textContent = message;
  bankError.classList.remove('hidden');
}

function hideBankError() {
  if (!bankError) return;
  bankError.classList.add('hidden');
}

document.addEventListener('DOMContentLoaded', () => {
  const screenRut        = document.getElementById('screen-rut');
  const screenInvoices   = document.getElementById('screen-invoices');
  const volverBtn        = document.getElementById('volver-btn');
  const rutInput         = document.getElementById('rut');
  const infoRut          = document.getElementById('info-rut');
  const infoRazonSocial  = document.getElementById('info-razon-social');
  const consultarBtn     = document.getElementById('consultar-btn');
  const msg              = document.getElementById('msg');
  const loader           = document.getElementById('loader');
  const invoicesList     = document.getElementById('invoices-list');
  const totalAPagarEl    = document.getElementById('total-a-pagar');
  const bankSection      = document.getElementById('bank-section');
  const bankSearch       = document.getElementById('bank-search');
  const bankSelect       = document.getElementById('bank-select');

  const fmtNum = new Intl.NumberFormat('es-UY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function setMsg(t = '', isError = false) {
    msg.textContent = t;
    msg.className = 'text-sm ' + (isError ? 'text-red-600' : 'text-gray-700');
  }

  function sanitizeRutInput() {
    if (!rutInput) return '';
    const cleaned = (rutInput.value || '').replace(/[^0-9]/g, '').trim();
    rutInput.value = cleaned;
    return cleaned;
  }

  function showBankError(message) {
    if (!bankError) return;
    bankError.textContent = message;
    bankError.classList.remove('hidden');
  }

  function hideBankError() {
    if (!bankError) return;
    bankError.classList.add('hidden');
  }

  function showLoader(show = true) {
    loader.classList.toggle('hidden', !show);
  }

  // Render (ordenadas por Fecha dd/mm/yyyy ascendente)
  function renderFacturas(facturas = []) {
    if (!invoicesList) return;
    invoicesList.innerHTML = '';

    if (facturas.length === 0) {
      invoicesList.innerHTML = `
        <div class="p-4 bg-gray-50 rounded-lg border border-gray-200 text-gray-700">
          No hay facturas pendientes.
        </div>`;
      return;
    }

    facturas.sort((a, b) => {
      // Data trae 'Fecha' con mayúscula
      const pa = (a.Fecha || '').split('/').reverse().join('-'); // yyyy-mm-dd
      const pb = (b.Fecha || '').split('/').reverse().join('-');
      return new Date(pa) - new Date(pb);
    });

    for (const f of facturas) {
      const monto = Number(f.TotMntAPagar || 0);
      const saldo = Number(f.importe || 0);
      const pdfDisponible = f.pdf_disponible === true;
      const rutFactura = (infoRut ? infoRut.textContent : '').replace(/[^0-9]/g, '');

      const fila = document.createElement('div');
      fila.className = 'flex items-center justify-between gap-2 p-3 sm:p-4 bg-gray-50 rounded-xl border border-gray-200 hover:border-gray-300 transition-colors';

      const accionesPdf = pdfDisponible
        ? `
          <form method="post" action="factura_pdf.php" class="shrink-0">
              <input type="hidden" name="rut" value="${rutFactura}"/>
              <input type="hidden" name="idventa" value="${(f.IdVentas ?? '').toString()}"/>
              <input type="hidden" name="accion" value="descargar"/>
              <button type="submit" class="pl-2 sm:pl-4 flex flex-col items-center justify-center group" aria-label="Descargar PDF de la factura ${(f.Serie ?? '')}${(f.Numero ?? '')}">
                <span class="w-7 h-7 sm:w-8 sm:h-8 rounded-full border border-red-200 text-red-600 flex items-center justify-center group-hover:bg-red-50 transition-colors mb-1" aria-hidden="true">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="sm:w-4 sm:h-4">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                  </svg>
                </span>
                <span class="text-[9px] sm:text-[10px] text-gray-500 font-medium group-hover:text-red-600 transition-colors text-center leading-tight">Descargar<br>PDF</span>
              </button>
          </form>`
        : `
          <div class="pl-2 sm:pl-4 shrink-0 text-[10px] sm:text-xs font-medium text-gray-500 text-center leading-tight">
            PDF no disponible
          </div>`;

      // 👇 Se agregan TODOS los hidden que usaremos para armar el payload
      fila.innerHTML = `
        <div class="text-sm text-gray-700 min-w-0">
          <input type="hidden" class="txt_idVenta"        value="${(f.IdVentas ?? '').toString()}"/>
          <input type="hidden" class="txt_idCuenta"       value="${(f.idCuenta ?? '').toString()}"/>
          <input type="hidden" class="txt_idFactura"      value="${(f.idFactura ?? '').toString()}"/>
          <input type="hidden" class="txt_importe"        value="${(f.importe ?? '0').toString()}"/>
          <input type="hidden" class="txt_moneda"         value="${((f.moneda ?? 'UYU') + '').toUpperCase()}"/>
          <input type="hidden" class="txt_importeGravado" value="${(f.importeGravado ?? 0).toString()}"/>
          <input type="hidden" class="txt_consumidorFinal" value="${(f.consumidorFinal ?? '1')}"/>
          <input type="hidden" class="txt_fechaVenc"      value="${(f.fechaVenc ?? '').toString()}"/>

          <p><span class="font-medium"><b>Factura:</b></span> ${(f.TipoDoc ?? '')} ${(f.Serie ?? '')}${(f.Numero ?? '')}</p>
          <p><span class="font-medium">Fecha:</span> ${f.Fecha ?? ''}</p>
        </div>

        <div class="text-right pr-2 sm:pr-4 border-r border-gray-200 shrink-0">
          <p class="font-bold text-lg text-gray-900">$${fmtNum.format(saldo)}</p>
          <p class="text-xs text-gray-500">de $${fmtNum.format(monto)}</p>
        </div>

        ${accionesPdf}
      `;

      invoicesList.appendChild(fila);
    }
  }

  function renderBankOptions(filter = '') {
    if (!bankSelect) return;
    const normalized = (filter || '').trim().toLowerCase();
    bankSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Seleccione un banco';
    placeholder.selected = true;
    bankSelect.appendChild(placeholder);

    const matches = BANK_OPTIONS.filter(bank => {
      const label = (bank.label || '').toLowerCase();
      return label.includes(normalized);
    });

    if (matches.length === 0) {
      bankSelect.disabled = true;
      const empty = document.createElement('option');
      empty.value = '';
      empty.disabled = true;
      empty.textContent = normalized ? 'No hay coincidencias' : 'Sin bancos disponibles';
      bankSelect.appendChild(empty);
      return;
    }

    bankSelect.disabled = false;

    for (const bank of matches) {
      const option = document.createElement('option');
      option.value = bank.code;
      option.textContent = bank.label;
      bankSelect.appendChild(option);
    }

    bankSelect.selectedIndex = 0;
  }

  async function ejecutarConsultaRut() {
    const rut = sanitizeRutInput();

    if (!rut) {
      setMsg('Ingresa el RUT.', true);
      rutInput.focus();
      rutInput.classList.add('ring-2', 'ring-red-500');
      setTimeout(() => rutInput.classList.remove('ring-2', 'ring-red-500'), 1500);
      return;
    }

    if (!/^\d+$/.test(rut)) {
      setMsg('El RUT solo debe contener números.', true);
      rutInput.focus();
      rutInput.classList.add('ring-2', 'ring-red-500');
      setTimeout(() => rutInput.classList.remove('ring-2', 'ring-red-500'), 1500);
      return;
    }

    consultarBtn.disabled = true;
    consultarBtn.classList.add('opacity-50', 'cursor-not-allowed');
    showLoader(true);
    setMsg('Consultando empresa...');

    try {
      const fd = new FormData();
      fd.append('rut', rut);

      const resp = await fetch('validarRUT.php', { method: 'POST', body: fd });
      if (!resp.ok) throw new Error('No se pudo contactar el servidor.');
      const data = await resp.json();

      if (data && data.ok && data.empresa) {
        const nombre = (data.empresa.razon_social && data.empresa.razon_social.trim() !== '')
          ? data.empresa.razon_social
          : (data.empresa.nombre_fantasia || 'Sin nombre');

        infoRut.textContent = data.empresa.rut || rut;
        infoRazonSocial.textContent = nombre;

        if (data.tiene_facturas && Array.isArray(data.facturas) && data.facturas.length > 0) {
          renderFacturas(data.facturas);

          // total (con moneda del primer item)
          if (totalAPagarEl) {
            const cod = (((data.facturas[0] || {}).moneda) || 'UYU');
            totalAPagarEl.textContent = `${cod} ${fmtNum.format(Number(data.total_pendiente || 0))}`;
          }

          if (bankSection) {
            bankSection.classList.remove('hidden');
            renderBankOptions('');
            if (bankSearch) bankSearch.value = '';
            hideBankError();
          }

          screenRut.classList.add('hidden');
          screenInvoices.classList.remove('hidden');
          setMsg('Empresa encontrada.');
        } else {
          setMsg(data.message || 'La empresa no tiene facturas pendientes.', true);
        }
      } else {
        setMsg((data && data.message) || 'No existe una empresa con ese RUT.', true);
      }
    } catch (err) {
      setMsg(err.message || 'Error inesperado.', true);
    } finally {
      consultarBtn.disabled = false;
      consultarBtn.classList.remove('opacity-50', 'cursor-not-allowed');
      showLoader(false);
    }
  }

  consultarBtn.addEventListener('click', (e) => {
    e.preventDefault();
    ejecutarConsultaRut();
  });
  
  if (rutInput && sanitizeRutInput() !== '') {
    ejecutarConsultaRut();
  }

  if (volverBtn) {
    volverBtn.addEventListener('click', (e) => {
      e.preventDefault();
      screenInvoices.classList.add('hidden');
      screenRut.classList.remove('hidden');
      setMsg('');
      if (bankSection) {
        bankSection.classList.add('hidden');
        if (bankSearch) bankSearch.value = '';
        if (bankSelect) {
          bankSelect.innerHTML = '';
        }
      }
      rutInput.focus();
    });
  }

  if (bankSearch) {
    bankSearch.addEventListener('input', (event) => {
      renderBankOptions(event.target.value || '');
      hideBankError();
    });
  }

  if (bankSelect) {
    bankSelect.addEventListener('change', () => hideBankError());
  }

  renderBankOptions('');
});

// === Helpers fuera del DOMContentLoaded ===
function toB64Url(str) {
  return btoa(unescape(encodeURIComponent(str)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

document.getElementById('pagar-btn').addEventListener('click', function () {
  const vals = sel => Array.from(document.querySelectorAll(sel))
    .map(el => (el.value ?? '').toString().trim())
    .filter(v => v !== '');

  //ahora sí recolectamos IdVentas también
  const idventas        = vals('.txt_idVenta');
  const idcuenta        = vals('.txt_idCuenta');
  const fact            = vals('.txt_idFactura');
  const total           = vals('.txt_importe');
  const gravado         = vals('.txt_importeGravado');
  const moneda          = vals('.txt_moneda');
  const consumidorfinal = vals('.txt_consumidorFinal');
  const fechavenc       = vals('.txt_fechaVenc');

  if (!idventas.length) {
    alert('No hay ventas seleccionadas.');
    return;
  }

  // Payload con los nombres exactos que espera iniciar_pago.php
  const payload = {
    idventas,
    idcuenta,
    fact,
    total,
    gravado,
    moneda,
    cf: consumidorfinal,
    fechavenc,
    debug: '0'
  };

  const bankSelectEl = document.getElementById('bank-select');
  const selectedBank = bankSelectEl ? (bankSelectEl.value || '').trim() : '';
  if (!selectedBank) {
    showBankError('Seleccioná un banco antes de continuar.');
    if (bankSelectEl) {
      bankSelectEl.focus();
    }
    return;
  }

	console.log('APP_ENV:', APP_ENV);
	console.log('IS_DESARROLLO:', IS_DESARROLLO);
	console.log('selectedBank:', selectedBank);


  payload.idBanco = selectedBank;

  if (IS_DESARROLLO) {
  	payload.idBanco = '009';
  }

  console.log(JSON.stringify(payload));
  const dat = toB64Url(JSON.stringify(payload));
  console.log(dat);
  const url = `https://www.datosdynamica.net/pagarenlinea/iniciar_pago.php?dat=${encodeURIComponent(dat)}`;
  window.location.href = url;
});


//  https://www.datosdynamica.net/pagarenlinea/
