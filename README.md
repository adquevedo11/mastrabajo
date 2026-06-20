# Organizador de Cédulas PDF

Herramienta web para consolidar múltiples PDFs de cédulas de identidad en un solo archivo, ordenado según el listado definido en un Excel.

---

## ¿Para qué sirve?

Dado un Excel con una lista de personas y sus PDFs de cédula subidos individualmente, la app genera un único PDF consolidado (`Cedulas_Ordenadas.pdf`) con todas las cédulas en el orden exacto del Excel.

---

## Flujo de uso

1. **Cargar el Excel** — debe tener columnas de número de documento, nombres y apellidos.
2. **Subir los PDFs** — arrastrando o seleccionando. Cada PDF puede tener varias páginas.
3. **Asociar** — arrastrar cada PDF a la persona correspondiente en la tabla, o usar el botón de enlace.
4. **Generar** — cuando todos estén asociados, generar y descargar el PDF consolidado.

El manual completo está disponible dentro de la app en el enlace **Manual** del footer.

---

## Requisitos

- PHP 8.1+
- Composer
- Extensión GD habilitada
- Servidor web (Apache/Nginx) o XAMPP para desarrollo local

---

## Instalación local

```bash
# Clonar el repositorio
git clone https://github.com/adquevedo11/mastrabajo.git
cd mastrabajo

# Instalar dependencias PHP
composer install

# Crear carpetas de almacenamiento
mkdir -p storage/uploads storage/generated storage/temp
```

Apuntar el servidor web a la raíz del proyecto. En XAMPP, colocar la carpeta en `htdocs/` y acceder desde `http://localhost/MasTrabajo`.

---

## Estructura del proyecto

```
MasTrabajo/
├── app/
│   ├── Controllers/       # PdfController, GenerateController, ExcelController…
│   ├── Services/          # PdfService, AssociationService, ExcelService…
│   └── Helpers/           # Response
├── assets/
│   ├── css/app.css
│   ├── js/app.js
│   └── favicon.svg / favicon.png
├── storage/
│   ├── uploads/           # PDFs subidos (excluido del repo)
│   └── generated/         # PDFs consolidados generados (excluido del repo)
├── vendor/                # Dependencias Composer (excluido del repo)
├── api.php                # Enrutador de la API REST
├── index.php              # Vista principal (SPA)
├── config.php             # Constantes de rutas
└── composer.json
```

---

## Dependencias principales

| Paquete | Uso |
|---|---|
| [setasign/fpdi](https://github.com/Setasign/FPDI) | Importar y combinar páginas PDF |
| [setasign/fpdf](https://github.com/Setasign/FPDF) | Generar PDFs |
| [phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) | Leer y exportar Excel |

---

## PDFs incompatibles

Los PDFs generados con formato moderno (PDF 1.5+) no son compatibles con el parser gratuito de FPDI. Si una tarjeta aparece con borde rojo:

1. Ir a **[ilovepdf.com → Optimizar PDF](https://www.ilovepdf.com/es/optimizar_pdf)**
2. Convertir el archivo
3. Eliminar el PDF incompatible en la app y subir el convertido

En servidores con Ghostscript instalado (`apt install ghostscript`) la conversión ocurre automáticamente.

---

## Licencia

Uso interno. Todos los derechos reservados.
