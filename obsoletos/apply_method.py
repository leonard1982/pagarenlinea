from pathlib import Path
path = Path('migrateInvoicyServicesPRODUCCION.php')
text = path.read_text()
start = text.index('public function envio')
end = text.index('public function anulacion', start)
original = Path('original_method.txt').read_text()
path.write_text(text[:start] + original + '\n' + text[end:])
