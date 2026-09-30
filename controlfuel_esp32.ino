#include <SPI.h>
#include <MFRC522.h>

// ===== PINES (igual que tu hardware) =====
#define SS_PIN        5
#define RST_PIN       4
#define RELAY_PIN     27
#define TRIG_PIN      12
#define ECHO_PIN      14   // Recuerda: el HC-SR04 entrega 5V en ECHO; usa un
                            // divisor de voltaje hacia este pin para no dañar el ESP32.
#define BUZZER_PIN    15

// ===== Calibración de la bomba =====
const float BOMBA_LITROS_POR_MINUTO = 12.0;   // ajusta según tu bomba real
const unsigned long TIEMPO_BOMBA_MAX_MS = 60000; // tope de seguridad: 60s

// ===== Intervalos =====
const unsigned long INTERVALO_LECTURA_TANQUE_MS = 15000; // cada 15 s
const unsigned long ANTI_REBOTE_TARJETA_MS      = 4000;  // evita doble lectura
const unsigned long TIMEOUT_RESPUESTA_PC_MS     = 5000;  // espera máx. respuesta del puente

MFRC522 rfid(SS_PIN, RST_PIN);
unsigned long ultimaLecturaTanque = 0;
unsigned long ultimoDespacho = 0;
String ultimoUidLeido = "";

void setup() {
  Serial.begin(115200);
  while (!Serial) { ; }

  pinMode(RELAY_PIN, OUTPUT);
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  digitalWrite(RELAY_PIN, HIGH); // HIGH = bomba apagada (relé activo en LOW)
  digitalWrite(BUZZER_PIN, LOW);

  SPI.begin();
  rfid.PCD_Init();

  Serial.println("# ControlFuel listo (modo USB) — esperando al puente en la PC");
}

void loop() {
  // ----- Lectura periódica del sensor ultrasónico -----
  if (millis() - ultimaLecturaTanque >= INTERVALO_LECTURA_TANQUE_MS) {
    float distancia = medirDistancia();
    if (distancia > 0) {
      Serial.print("LECTURA;");
      Serial.println(distancia, 1);
    }
    ultimaLecturaTanque = millis();
  }

  // ----- Lectura de tarjeta RFID -----
  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uid = uidToString(rfid.uid.uidByte, rfid.uid.size);

    bool esRepetida = (uid == ultimoUidLeido) &&
                       (millis() - ultimoDespacho < ANTI_REBOTE_TARJETA_MS);

    if (!esRepetida) {
      procesarDespacho(uid);
      ultimoUidLeido = uid;
      ultimoDespacho = millis();
    }

    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
  }

  // Cualquier línea de depuración/ruido que llegue por Serial mientras
  // tanto se ignora — solo nos importa la respuesta cuando la pedimos.
  delay(200);
}

// ============================================================
// Sensor ultrasónico
// ============================================================
float medirDistancia() {
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);

  long duracion = pulseIn(ECHO_PIN, HIGH, 30000);
  if (duracion == 0) return -1;
  return duracion * 0.0343 / 2.0;
}

// ============================================================
// Pide autorización al puente de la PC (por USB) y despacha
// ============================================================
void procesarDespacho(const String& uid) {
  // Vacía cualquier dato viejo en el buffer antes de preguntar
  while (Serial.available()) Serial.read();

  Serial.print("DESPACHO;");
  Serial.println(uid);

  String linea = esperarRespuesta(TIMEOUT_RESPUESTA_PC_MS);

  if (linea.startsWith("AUTORIZADO;")) {
    float litros = linea.substring(11).toFloat();

    tonoBuzzer(900, 200);
    delay(80);
    tonoBuzzer(1400, 300);

    despacharLitros(litros);

  } else if (linea.startsWith("RECHAZADO;")) {
    tonoBuzzer(250, 180);
    delay(90);
    tonoBuzzer(250, 180);
    delay(90);
    tonoBuzzer(200, 250);

  } else {
    // Sin respuesta del puente (PC apagada, script no corriendo, etc.)
    tonoBuzzer(200, 500);
  }
}

/** Lee líneas de Serial hasta encontrar una que empiece con
 *  AUTORIZADO; o RECHAZADO;, o hasta que se acabe el tiempo. */
String esperarRespuesta(unsigned long timeoutMs) {
  unsigned long inicio = millis();
  String buffer = "";

  while (millis() - inicio < timeoutMs) {
    while (Serial.available()) {
      char c = Serial.read();
      if (c == '\n') {
        buffer.trim();
        if (buffer.startsWith("AUTORIZADO;") || buffer.startsWith("RECHAZADO;")) {
          return buffer;
        }
        buffer = ""; // línea irrelevante, seguir esperando
      } else if (c != '\r') {
        buffer += c;
      }
    }
  }
  return ""; // timeout
}

void despacharLitros(float litros) {
  if (litros <= 0) return;

  unsigned long duracionMs = (unsigned long) ((litros / BOMBA_LITROS_POR_MINUTO) * 60000.0);
  duracionMs = min(duracionMs, TIEMPO_BOMBA_MAX_MS);

  digitalWrite(RELAY_PIN, LOW);  // LOW = bomba encendida
  delay(duracionMs);
  digitalWrite(RELAY_PIN, HIGH); // HIGH = bomba apagada
}

// ============================================================
// Utilidades
// ============================================================
String uidToString(byte* buffer, byte tamano) {
  String resultado = "";
  for (byte i = 0; i < tamano; i++) {
    if (buffer[i] < 0x10) resultado += "0";
    resultado += String(buffer[i], HEX);
  }
  resultado.toUpperCase();
  return resultado;
}

void tonoBuzzer(int frecuencia, int duracion) {
  tone(BUZZER_PIN, frecuencia, duracion);
  delay(duracion + 40);
  noTone(BUZZER_PIN);
}
