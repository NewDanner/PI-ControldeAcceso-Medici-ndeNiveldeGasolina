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

// ===== Intervalos =====
const unsigned long INTERVALO_LECTURA_TANQUE_MS = 15000; // cada 15 s
const unsigned long ANTI_REBOTE_TARJETA_MS      = 4000;
const unsigned long TIMEOUT_RESPUESTA_PC_MS     = 5000;  // espera máx. del handshake inicial
const unsigned long TOPE_SEGURIDAD_BOMBA_MS      = 180000; // 3 min: corte de seguridad si el puente se cae

MFRC522 rfid(SS_PIN, RST_PIN);
unsigned long ultimaLecturaTanque = 0;
unsigned long ultimoDespacho = 0;
String ultimoUidLeido = "";

bool bombaEncendida = false;
unsigned long inicioBomba = 0;
String bufferEntrada = "";

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

  Serial.println("# ControlFuel listo (sesion abierta) - esperando al puente en la PC");
}

void loop() {
  // ----- Lectura periódica del sensor ultrasónico (siempre activa) -----
  if (millis() - ultimaLecturaTanque >= INTERVALO_LECTURA_TANQUE_MS) {
    float distancia = medirDistancia();
    if (distancia > 0) {
      Serial.print("LECTURA;");
      Serial.println(distancia, 1);
    }
    ultimaLecturaTanque = millis();
  }

  if (bombaEncendida) {
    // ----- Mientras despacha: solo escuchar DETENER, sin bloquear -----
    revisarComandoDetener();

    // Corte de seguridad si el puente se cayó y nunca llegó DETENER
    if (millis() - inicioBomba > TOPE_SEGURIDAD_BOMBA_MS) {
      apagarBomba();
    }

  } else if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    // ----- Sin despacho en curso: se puede leer una tarjeta nueva -----
    String uid = uidToString(rfid.uid.uidByte, rfid.uid.size);

    bool esRepetida = (uid == ultimoUidLeido) &&
                       (millis() - ultimoDespacho < ANTI_REBOTE_TARJETA_MS);

    if (!esRepetida) {
      pedirInicioDespacho(uid);
      ultimoUidLeido = uid;
      ultimoDespacho = millis();
    }

    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
  }

  delay(50);
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
// Pide al puente autorización para iniciar (handshake breve)
// ============================================================
void pedirInicioDespacho(const String& uid) {
  while (Serial.available()) Serial.read(); // limpia ruido viejo

  Serial.print("DESPACHO;");
  Serial.println(uid);

  String linea = esperarLinea(TIMEOUT_RESPUESTA_PC_MS);

  if (linea == "INICIAR") {
    tonoBuzzer(900, 200);
    delay(80);
    tonoBuzzer(1400, 300);

    digitalWrite(RELAY_PIN, LOW); // LOW = bomba encendida
    bombaEncendida = true;
    inicioBomba = millis();
    bufferEntrada = ""; // listo para escuchar DETENER desde ahora

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

/** Lee líneas hasta encontrar "INICIAR" o "RECHAZADO;...", o hasta agotar el tiempo. */
String esperarLinea(unsigned long timeoutMs) {
  unsigned long inicio = millis();
  String buffer = "";

  while (millis() - inicio < timeoutMs) {
    while (Serial.available()) {
      char c = Serial.read();
      if (c == '\n') {
        buffer.trim();
        if (buffer == "INICIAR" || buffer.startsWith("RECHAZADO;")) {
          return buffer;
        }
        buffer = "";
      } else if (c != '\r') {
        buffer += c;
      }
    }
  }
  return "";
}

/** Revisa, sin bloquear, si llegó la línea "DETENER" mientras la bomba corre. */
void revisarComandoDetener() {
  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n') {
      bufferEntrada.trim();
      if (bufferEntrada == "DETENER") {
        apagarBomba();
      }
      bufferEntrada = "";
    } else if (c != '\r') {
      bufferEntrada += c;
    }
  }
}

void apagarBomba() {
  digitalWrite(RELAY_PIN, HIGH); // HIGH = bomba apagada
  bombaEncendida = false;
  tonoBuzzer(700, 150);
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
