# Plan de pruebas integrado — ControlFuel

## 1. Propósito

Este plan valida el incremento técnico mínimo de ControlFuel como sistema compuesto por:

- firmware del ESP32;
- lector RFID RC522;
- sensor ultrasónico;
- relé, válvula, servomotor o bomba de prueba;
- API y aplicación web PHP;
- base de datos MySQL/MariaDB;
- dashboard y reportes.

Las pruebas deben ejecutarse en laboratorio, con combustible sustituido por agua u otro medio seguro de demostración. No se deben conectar los componentes a una instalación real de combustible.

> **Regla de evidencia:** una prueba solo puede marcarse como `Aprobada` si se conserva la fecha, el responsable, los datos utilizados, el resultado obtenido y una evidencia: fotografía, video, captura, respuesta JSON, log serial, consulta SQL o archivo generado.

## 2. Estados de prueba

- **Aprobada:** cumple el resultado esperado.
- **Fallida:** no cumple el criterio.
- **Bloqueada:** no se pudo ejecutar por una dependencia ausente.
- **Pendiente:** todavía no se ejecutó.

## 3. Datos y condiciones de prueba

Utilizar valores ficticios y seguros:

| Elemento | Valor sugerido |
|---|---|
| UID autorizado | `A1B2C3D4` |
| UID no registrado | `NO_EXISTE` |
| Titular | `Operador_Prueba` |
| Nivel normal | 62,4 % |
| Volumen de referencia | Según recipiente calibrado |
| Nivel crítico | 10 % o el configurado en `configuracion.nivel_critico_pct` |
| Límite crisis | 5 L o el configurado en `limite_crisis_litros` |
| Límite normal | El configurado en `litros_normal_max` |
| API key | Solo valor local, no publicarlo |
| URL backend | URL local de Apache/PHP |

### Precondición de base de datos

Antes de ejecutar T10 y T14, resolver la siguiente inconsistencia:

- `api/despacho.php` utiliza `configuracion.litros_normal_max`.
- El esquema de base de datos enviado no contiene esa columna.

Agregarla o modificar la lógica, y actualizar `database/schema.sql` antes de marcar las pruebas de despacho como aprobadas.

## 4. Pruebas de base de datos

### BD01 — Creación del esquema

**Procedimiento:**

```bash
mysql -u root -p < database/schema.sql
```

**Resultado esperado:** se crean las tablas:

```text
usuarios
tarjetas_rfid
configuracion
lecturas_tanque
despachos
```

**Evidencia:** salida de MySQL y consulta:

```sql
USE controlfuel;
SHOW TABLES;
```

**Estado:** Pendiente.

### BD02 — Configuración inicial

**Consulta:**

```sql
SELECT * FROM configuracion WHERE id = 1;
```

**Resultado esperado:** existe una única fila con modo operativo, capacidad del tanque, límite de crisis y umbral crítico.

**Estado:** Pendiente.

### BD03 — Integridad de relaciones

**Procedimiento:** registrar una tarjeta con `registrado_por` válido y crear un despacho asociado.

**Resultado esperado:** las claves foráneas relacionan usuario, tarjeta y despacho correctamente.

**Estado:** Pendiente.

## 5. Pruebas de software web

### T01 — Login válido

**Procedimiento:** abrir `/login.php` e ingresar credenciales válidas.

**Resultado esperado:** redirección al dashboard y sesión activa.

**Evidencia:** captura del dashboard.

**Estado:** Pendiente.

### T02 — Login inválido

**Procedimiento:** ingresar una contraseña incorrecta.

**Resultado esperado:** no se crea sesión y aparece un mensaje de error controlado.

**Estado:** Pendiente.

### T03 — Control de roles

**Procedimiento:** iniciar sesión como operador e intentar abrir `usuarios.php`, `rfid.php` y `reportes.php`.

**Resultado esperado:** acceso restringido o redirección al dashboard.

**Estado:** Pendiente.

### T04 — Registrar tarjeta RFID

**Procedimiento:** como administrador, registrar `A1B2C3D4` con titular `Operador_Prueba`.

**Resultado esperado:** aparece como tarjeta activa en `rfid.php`.

**Estado:** Pendiente.

### T05 — UID duplicado

**Procedimiento:** registrar de nuevo `A1B2C3D4`.

**Resultado esperado:** la base de datos rechaza el duplicado y la interfaz muestra un mensaje controlado.

**Estado:** Pendiente.

### T06 — Activar y desactivar tarjeta

**Procedimiento:** cambiar el estado de la tarjeta desde `rfid.php`.

**Resultado esperado:** el listado refleja correctamente `Activa` o `Inactiva`.

**Estado:** Pendiente.

## 6. Pruebas de API

Sustituir `URL_BASE` y `API_KEY_LOCAL` en los comandos.

### T07 — Lectura de tanque válida

```bash
curl -i -X POST "URL_BASE/api/lectura_tanque.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: API_KEY_LOCAL" \
  -d '{"nivel_pct":62.4,"volumen_litros":6240}'
```

**Resultado esperado:** código HTTP exitoso, respuesta `{"ok":true}` y una nueva fila en `lecturas_tanque`.

**Evidencia:** respuesta, consulta SQL y captura del dashboard.

**Estado:** Pendiente.

### T08 — API key inválida

**Procedimiento:** repetir T07 con una clave incorrecta.

**Resultado esperado:** HTTP `401` y motivo `API key inválida`.

**Estado:** Pendiente.

### T09 — Datos fuera de rango

```bash
curl -i -X POST "URL_BASE/api/lectura_tanque.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: API_KEY_LOCAL" \
  -d '{"nivel_pct":150,"volumen_litros":-20}'
```

**Resultado esperado:** nivel almacenado como máximo en 100 y volumen como mínimo en 0.

**Estado:** Pendiente.

### T10 — Despacho autorizado por tarjeta física

**Precondiciones:**

- ESP32 conectado al Wi-Fi.
- RC522 conectado y funcionando.
- UID `A1B2C3D4` activo.
- Nivel superior al umbral crítico.
- Configuración normal válida.

**Procedimiento:** acercar la tarjeta al RC522.

**Resultado esperado:**

1. el ESP32 lee el UID;
2. envía la solicitud al backend;
3. el backend devuelve `autorizado: true`;
4. se habilita el relé/actuador de prueba;
5. se registra un despacho completado.

**Evidencia:** video o fotografía del montaje, log serial, respuesta JSON, fila SQL y captura del dashboard.

**Estado:** Pendiente.

### T11 — UID no registrado

**Procedimiento:** presentar una tarjeta no registrada o enviar:

```bash
curl -i -X POST "URL_BASE/api/despacho.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: API_KEY_LOCAL" \
  -d '{"uid_rfid":"NO_EXISTE"}'
```

**Resultado esperado:** el backend responde `autorizado: false` con motivo `Tarjeta no registrada` y el actuador permanece apagado.

**Estado:** Pendiente.

### T12 — Tarjeta inactiva

**Procedimiento:** desactivar `A1B2C3D4` y volver a presentarla.

**Resultado esperado:** rechazo con motivo `Tarjeta inactiva`; relé o bomba apagado.

**Estado:** Pendiente.

### T13 — Nivel crítico

**Precondición:** configurar un nivel crítico distinto de cero, por ejemplo 10 %.

**Procedimiento:** enviar o producir una lectura por debajo del umbral:

```bash
curl -i -X POST "URL_BASE/api/lectura_tanque.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: API_KEY_LOCAL" \
  -d '{"nivel_pct":5,"volumen_litros":500}'
```

Luego presentar una tarjeta activa.

**Resultado esperado:** rechazo por `Nivel de tanque crítico` y actuador apagado.

**Estado:** Pendiente.

### T14 — Modo crisis y límite volumétrico

**Precondición:** resolver `litros_normal_max` en la base de datos o en el código.

**Procedimiento:**

1. activar modo crisis en el dashboard;
2. asegurar que el nivel esté por encima del umbral crítico;
3. presentar una tarjeta activa;
4. observar el volumen autorizado y el actuador.

**Resultado esperado:** el volumen autorizado corresponde a `limite_crisis_litros`; el actuador se corta al alcanzar el límite o termina el intervalo definido por el prototipo.

**Evidencia:** captura del modo crisis, log serial, medición del volumen y fila SQL.

**Estado:** Pendiente.

## 7. Pruebas de hardware y firmware

### H01 — Lectura RFID

**Objetivo:** verificar que el RC522 entregue el UID al ESP32.

**Procedimiento:** presentar tres veces la tarjeta autorizada y una tarjeta no autorizada.

**Resultado esperado:** cada UID aparece correctamente en el monitor serial y se diferencia el autorizado del no autorizado.

**Métrica:** porcentaje de lecturas correctas = lecturas correctas / lecturas totales × 100.

**Meta sugerida:** al menos 95 % en condiciones controladas.

**Estado:** Pendiente.

### H02 — Calibración ultrasónica

**Objetivo:** comparar la distancia o nivel calculado con una referencia conocida.

**Procedimiento:** tomar mediciones en al menos cinco niveles del recipiente y registrar valor real, valor estimado y error.

**Fórmula:**

```text
error (%) = |valor_estimado - valor_referencia| / valor_referencia × 100
```

**Resultado esperado:** error promedio menor o igual al 5 %, según el criterio del proyecto.

**Evidencia:** tabla de mediciones, fotografía del recipiente y log serial.

**Estado:** Pendiente.

### H03 — Actuador en autorización

**Procedimiento:** presentar una tarjeta autorizada con nivel normal.

**Resultado esperado:** el relé, válvula, servomotor o bomba de prueba cambia al estado habilitado.

**Evidencia:** video o fotografía y log serial.

**Estado:** Pendiente.

### H04 — Actuador en rechazo

**Procedimiento:** presentar una tarjeta inactiva, inexistente o activar el nivel crítico.

**Resultado esperado:** el actuador permanece apagado o vuelve al estado seguro.

**Estado:** Pendiente.

### H05 — Autocorte en modo crisis

**Procedimiento:** iniciar un despacho controlado en modo crisis y medir desde el momento en que se alcanza el límite hasta la desconexión del actuador.

**Resultado esperado:** tiempo menor a 200 ms, si esta es la meta validada del proyecto.

**Evidencia:** video de alta velocidad, osciloscopio, log con marcas de tiempo o instrumento equivalente.

**Estado:** Pendiente.

### H06 — Pérdida de Wi-Fi

**Procedimiento:** desconectar temporalmente la red durante el estado de espera y durante una solicitud.

**Resultado esperado:** el actuador permanece apagado; el ESP32 reintenta la conexión y no autoriza una operación sin respuesta válida del servidor.

**Estado:** Pendiente.

### H07 — Parada de emergencia

**Procedimiento:** activar el botón de emergencia si está incorporado.

**Resultado esperado:** el actuador se apaga inmediatamente y el sistema registra o muestra el estado de emergencia, según la implementación.

**Estado:** Pendiente.

## 8. Pruebas de dashboard y reportes

### T15 — Dashboard

**Resultado esperado:** muestra modo operativo, nivel porcentual, volumen, fecha de última lectura, gráfico de siete días y despachos recientes.

**Estado:** Pendiente.

### T16 — Filtro de reportes

**Resultado esperado:** `reportes.php` muestra únicamente despachos entre las fechas seleccionadas.

**Estado:** Pendiente.

### T17 — Exportación CSV

**Resultado esperado:** se descarga un CSV con fecha/hora, UID, titular, litros, modo, estado y motivo.

**Estado:** Pendiente.

### T18 — Exportación PDF

**Resultado esperado:** se descarga un PDF legible con periodo, registros y total de litros completados.

**Estado:** Pendiente.

## 9. Métricas para el informe

| Métrica | Fórmula o método | Meta | Resultado |
|---|---|---:|---:|
| Latencia RFID | Tiempo entre lectura del UID y respuesta/activación | < 1,5 s | Pendiente |
| Precisión volumétrica | Error frente a volumen de referencia | ≤ 5 % | Pendiente |
| Autocorte | Tiempo entre límite y apagado del actuador | < 200 ms | Pendiente |
| Persistencia | Eventos encontrados / eventos generados × 100 | 100 % | Pendiente |
| Lectura RFID | Lecturas correctas / intentos × 100 | ≥ 95 % | Pendiente |
| Disponibilidad del API | Solicitudes respondidas / solicitudes enviadas × 100 | Definir | Pendiente |

## 10. Registro consolidado

| ID | Fecha | Responsable | Resultado obtenido | Estado | Evidencia |
|---|---|---|---|---|---|
| BD01 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| BD02 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| BD03 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T01 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T02 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T03 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T04 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T05 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T06 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T07 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T08 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T09 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T10 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T11 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T12 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T13 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T14 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| H01 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| H02 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| H03 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| H04 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| H05 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| H06 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| H07 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T15 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T16 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T17 | AAAA-MM-DD | Nombre |  | Pendiente |  |
| T18 | AAAA-MM-DD | Nombre |  | Pendiente |  |

## 11. Evidencias que deben anexarse

- Fotografía general del montaje.
- Diagrama de conexión de pines.
- Fotografía del RC522 leyendo la tarjeta.
- Captura o video del monitor serial del ESP32.
- Captura del dashboard antes y después de una lectura.
- Respuestas JSON del API.
- Consultas SQL de las filas generadas.
- Tabla de calibración del sensor.
- Medición de latencia RFID.
- Medición del autocorte.
- Reporte CSV y PDF generado.
- Registro de defectos y acciones correctivas.
