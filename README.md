# Jet SQL Paginator

Paginación backend real para **JetEngine Query Builder SQL Custom Mode**.

Resuelve el issue [#8078](https://github.com/CrocoBlock/suggestions/issues/8078) de Crocoblock: la falta de paginación nativa para queries SQL avanzadas en JetEngine.

---

## El problema que resuelve

JetEngine en SQL Custom Mode ejecuta el SQL completo y aplica paginación en PHP, cargando **todos los registros** a memoria antes de mostrar N items. Con miles de registros, esto es un problema grave de performance.

Este plugin intercepta el SQL **antes** de que JE lo ejecute e inyecta `LIMIT/OFFSET` real en la base de datos.

---

## Instalación

1. Subir la carpeta `jet-sql-paginator` a `/wp-content/plugins/`
2. Activar desde **Plugins** en el admin de WordPress
3. Requiere JetEngine activo

---

## Uso

### 1. Añadir `{{PAGINATE:N}}` al SQL en JetEngine Query Builder

En **JetEngine > Query Builder**, añade el placeholder al final de tu SQL:

```sql
SELECT
    cm.object_ID,
    cm.contrato_tipo,
    p.post_title
FROM {prefix}contratos_meta cm
INNER JOIN {prefix}posts p ON p.ID = cm.object_ID
WHERE p.post_type = 'contratos'
  AND p.post_status = 'publish'
ORDER BY cm.contrato_f_reno DESC
{{PAGINATE:12}}
```

- `12` es el número de items por página
- El placeholder va **al final**, después del `ORDER BY`
- No añadir `LIMIT` propio
- `{prefix}` se reemplaza automáticamente por el prefijo de la DB

### 2. Configurar el CSS ID del Listing Grid

**Este paso es crítico para que JSF funcione con AJAX.**

En Bricks/Elementor/Gutenberg, en el widget **Listing Grid**:
- Configurar **CSS ID** = el mismo valor que el **Query ID** de la query en JE

Ejemplo: si la query tiene ID `102` en JE Query Builder, el CSS ID del grid debe ser `102`.

Sin este paso, JSF no puede encontrar el grid y el AJAX no funciona.

### 3. Añadir el widget de paginación JSF

Añade el widget **Pagination** de JetSmartFilters en la misma página:
- **Pagination for**: JetEngine
- **Apply type**: AJAX (o Page Reload)
- **Query ID**: el ID numérico de tu query (ej: `102`)

---

## Cómo funciona internamente

```
Usuario carga la página
  └─ JE ejecuta query 102
  └─ Plugin intercepta via after-query-setup (priority 0):
       Lee ?pagenum=N de la URL → escribe en final_query['_page']
  └─ Plugin intercepta via after-query-setup (priority 1):
       Detecta {{PAGINATE:12}}
       Ejecuta COUNT(*) para obtener total real
       Reemplaza {{PAGINATE:12}} por LIMIT 12 OFFSET 0
       JE ejecuta SQL con LIMIT real → devuelve 12 items
  └─ Plugin intercepta via set-props:
       Inyecta found_posts=TOTAL y max_num_pages=CEIL(TOTAL/12)
       JSF renderiza paginador con las páginas correctas

Usuario hace clic en página 2
  └─ JSF envía AJAX o recarga con ?pagenum=2
  └─ Mismo flujo con OFFSET 12
       → devuelve items 13-24
```

---

## Compatibilidad

| Builder | Soporte |
|---|---|
| Bricks | ✅ Confirmado |
| Elementor | ✅ Debería funcionar |
| Gutenberg | ✅ Debería funcionar |

| Tipo de query | Soporte |
|---|---|
| SQL Custom Mode (Advanced) | ✅ |
| SQL Standard Mode | ❌ No necesita este plugin |
| Posts/Terms/Users Query | ❌ No necesita este plugin |

---

## Endpoint REST (opcional)

El plugin registra un endpoint REST para acceso externo o debug:

```
GET /wp-json/jet-sql-paginator/v1/query?query_id=102&page=1
```

Respuesta:
```json
{
  "success": true,
  "items": [...],
  "pagination": {
    "total": 1250,
    "total_pages": 105,
    "current": 1,
    "per_page": 12,
    "has_prev": false,
    "has_next": true
  }
}
```

---

## Limitaciones conocidas

| Caso | Comportamiento |
|---|---|
| SQL sin `{{PAGINATE:N}}` | Se ignora, JE funciona normal |
| Query con `GROUP BY` raíz | COUNT wrapper puede fallar; usa fallback ejecutando el SQL completo y contando filas |
| CSS ID del grid no configurado | JSF AJAX no funciona; usar Page Reload como alternativa |

---

## Estructura

```
jet-sql-paginator/
├── jet-sql-paginator.php              # Plugin principal
└── includes/
    └── class-jet-sql-paginator.php   # Lógica completa
```
