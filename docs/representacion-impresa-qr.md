# Código QR de SUNAT en la representación impresa

Fecha: 13/11/2026. Aplica a facturas, boletas (`InvoicePdfService`) y notas de crédito
(`CreditNotePdfService`).

## Norma

RS 097-2012/SUNAT, anexo N.° 6, numeral 6.4 (texto de la RS 114-2019/SUNAT). Desde el
1/1/2020 (RS 309-2018/SUNAT) la representación impresa lleva **solo el código QR**; el valor
resumen puede seguir impreso aparte.

- Simbología QR Code 2005 (ISO/IEC 18004), sin Micro QR; corrección de errores **Q**; UTF-8.
- Módulo de al menos 0,19 mm; tamaño máximo 6 × 6 cm con el margen; zona de silencio de
  1 mm; en negro; en la parte inferior del documento.
- Contenido, separado por `|`, con el mismo formato del comprobante:
  `RUC | TIPO DE DOCUMENTO | SERIE | NÚMERO | MTO TOTAL IGV | MTO TOTAL DEL COMPROBANTE |
  FECHA DE EMISIÓN | TIPO DE DOCUMENTO ADQUIRENTE | NÚMERO DE DOCUMENTO ADQUIRENTE | VALOR RESUMEN`.

## Implementación

- `SunatQrPayload::fromXml()` arma el contenido leyendo el XML firmado (`cbc:ID`,
  `cbc:InvoiceTypeCode`, IGV del tributo `1000`, `PayableAmount`, `IssueDate`, documento del
  adquirente con su `schemeID` y `ds:DigestValue`). Sin XML no hay QR.
- `QrCodeEncoder` es un codificador propio (modo byte, nivel Q, versiones 1 a 10, hasta 151
  bytes) para no sumar dependencias; dibuja el símbolo como rectángulos negros del PDF.
- Factura y boleta: QR de 66 pt (≈ 23 mm, módulo ≥ 0,38 mm) abajo a la derecha de la última
  página; el pie crece y el contenido termina más arriba. Nota de crédito: abajo a la izquierda
  de la última página, bajo el piso del detalle.
- Los PDF ya guardados no se regeneran; las descargas web se generan al momento con el QR.

## Verificación

- `tests/Unit/SunatQrTest.php`: contenido para factura, boleta anónima y nota de crédito;
  estructura del símbolo; QR único y sin cruzar el contenido.
- QR leídos con un decodificador independiente (OpenCV) desde PDFs renderizados a 200 ppp:
  factura, boleta, nota, contenido de 120 bytes y símbolos de versión 10 (140 y 151 bytes).
- Contra los 48 XML de producción, en solo lectura y sin red: los 48 producen 10 campos con
  el formato esperado.
