# Reactivación tras pago Sistarbanc

Actualizado: 8 de septiembre de 2026.

El enlace de pago registraba el pago y emitía el recibo, pero no cambiaba el estado de la empresa. Ahora, después de persistir la confirmación y los detalles del recibo, el callback llama a `reactivar_empresa_pago.php` antes de la emisión electrónica.

La reactivación local cambia exclusivamente SU a SI, para la empresa facturadora 397, con pago autorizado, recibo y detalles completos, identidad única por RUT y ausencia de otra deuda. Conserva BA y los demás estados. No modifica importes, saldos, recibos ni numeración. Los errores se registran sin convertir una falla de reactivación en rechazo bancario.

## Migrate en segundo plano

`tools/process_sistarbanc_migrate.php` consume los eventos REACTIVADA del log del callback y solicita exclusivamente liberar la sucursal mediante el servicio existente de gestión de empresas. Se despliega junto con `tools/SistarbancMigrateWorker.php` fuera del directorio público, en `/var/lib/dynamica/sistarbanc_worker`.

Cron instalado en `/etc/cron.d/dynamica-sistarbanc-migrate`: cada cinco minutos, máximo tres trabajos por ejecución, con bloqueo para evitar ejecuciones superpuestas y límite externo de 420 segundos. La confirmación del pago no espera a este proceso.

Cada empresa/transacción tiene un solo intento. Antes del envío se comprueba que siga SI y que el pago y la ausencia de otra deuda continúen válidos. Un resultado incompleto o error se archiva como SIN_CONFIRMACION, nunca como éxito. Un corte durante el envío también se archiva como incierto, sin reenvío automático. Una empresa nuevamente suspendida/inactiva se descarta. Resolver esos casos exige revisión posterior; este diseño no garantiza que Migrate aplique todos los cambios.

## Registros

- Local: `log/reactivacion_dinamica_YYYYMMDD.jsonl`, con empresa, pago, recibo y resultado.
- Remoto: `/var/lib/dynamica/sistarbanc_reactivation/*.done.json`, evidencia privada; no subir respuestas o credenciales a Git.
- Cron: `/var/lib/dynamica/sistarbanc_reactivation/cron.log`.

Los .done.json retienen evidencia y evitan reenviar el mismo registro. No borrar estos archivos como parte de una limpieza de pendientes.

## Validación y operación

Se probaron nueve escenarios locales en tablas temporales de desarrollo: reactivación, repetición, BA, deuda adicional, falta de detalles, rechazo, RUT ambiguo, deuda de otro cliente del mismo RUT y transacción mezclada. Las pruebas del trabajador cubren confirmación, incertidumbre, descarte, deduplicación y recuperación tras corte; `tools/test_worker.php` usa un remitente simulado y no llama a Migrate.

La lectura del estado remoto solo se considera confirmada cuando el servicio devuelve el estado esperado para el código de sucursal. HTTP 502 y MsgCod=100 sin estado no son confirmación funcional.

La regularización histórica se hace sobre pagos existentes, con respaldo y verificaciones, invocando únicamente la reactivación; nunca repitiendo la confirmación bancaria ni emitiendo otra vez el recibo.

Para detener la sincronización remota, retirar únicamente el cron y conservar la evidencia. Para revertir el cambio del callback, restaurar su respaldo previo. La reversión de código no revierte estados de empresas ya cambiados.

Control en el Login y modificaciones al proceso de suspensión quedan fuera de esta intervención. Todavía no existe una consulta independiente soportada por Migrate que resuelva todos los retornos inciertos.

## Comprobación en producción del 08/09/2026

Se regularizaron cuatro empresas con pagos anteriores verificados: SU a SI en Dinámica. Las ventas y recibos se compararon antes/después y no cambiaron. Se conservaron respaldos privados y no se repitieron confirmaciones bancarias.

Los cuatro trabajos Migrate se procesaron fuera del callback y terminaron con HTTP 502 alrededor de 60 segundos. Quedaron archivados SIN_CONFIRMACION, sin reenvío automático. Esto valida el procesamiento y el registro del error, pero no demuestra el estado final remoto. Dinámica quedó comprobado; Migrate sigue necesitando una confirmación independiente o la respuesta del proveedor. La identidad de la clienta de la captura original no fue confirmada.
