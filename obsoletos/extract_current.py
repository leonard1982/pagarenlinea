from pathlib import Path
text = Path('migrateInvoicyServicesPRODUCCION.php').read_text()
start = text.index('public function envio')
end = text.index('public function anulacion', start)
Path('current_method.txt').write_text(text[start:end])
