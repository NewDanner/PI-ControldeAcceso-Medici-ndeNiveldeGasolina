"""
====================================================================
 ControlFuel - Puente Serial (USB) <-> API PHP  (sesion abierta)
====================================================================
Protocolo con el ESP32 (por el cable USB):
    ESP32 -> PC:  "LECTURA;<distancia_cm>"   (cada 15 s)
    ESP32 -> PC:  "DESPACHO;<uid>"           (al leer una tarjeta)
    PC -> ESP32:  "INICIAR"                  (autorizado: prende la bomba)
    PC -> ESP32:  "RECHAZADO;<motivo>"       (no autorizado)
    PC -> ESP32:  "DETENER"                  (alguien detuvo el despacho
                                               desde el dashboard, o se
                                               llegó al límite de crisis)

Requisitos (una sola vez):
    pip install pyserial requests

Uso:
    1. Ajusta PUERTO_SERIAL más abajo.
    2. Con XAMPP corriendo, ejecuta:  python controlfuel_bridge.py
    3. Déjalo con la ventana abierta mientras uses el sistema.
====================================================================
"""

import sys
import time

try:
    import serial
    import requests
except ImportError:
    print("Faltan librerías. Instala con:")
    print("    pip install pyserial requests")
    sys.exit(1)

# ===================== CONFIGURA ESTO =====================
PUERTO_SERIAL = "COM5"                                   # <-- cambia según tu PC
BAUD_RATE = 115200
SERVER_BASE = "http://localhost/controlfuel"              # <-- ajusta si tu carpeta se llama distinto
API_KEY = "7f3a9c1e-4b82-4d05-9e6f-2c8d5a1b90f4"           # igual que config/api.php
INTERVALO_SONDEO_SESION_SEG = 1.0
# ============================================================

HEADERS = {"Content-Type": "application/json", "X-Api-Key": API_KEY}

bomba_activa = False
ultimo_sondeo = 0.0


def conectar_serial():
    while True:
        try:
            ser = serial.Serial(PUERTO_SERIAL, BAUD_RATE, timeout=1)
            print(f"[OK] Conectado a {PUERTO_SERIAL} a {BAUD_RATE} baudios.")
            time.sleep(2)
            return ser
        except serial.SerialException as e:
            print(f"[ERROR] No se pudo abrir {PUERTO_SERIAL}: {e}")
            print("        Reintentando en 5 segundos...")
            time.sleep(5)


def manejar_lectura(distancia_cm: float):
    try:
        resp = requests.post(
            f"{SERVER_BASE}/api/lectura_tanque.php",
            json={"distancia_cm": distancia_cm},
            headers=HEADERS,
            timeout=5,
        )
        print(f"  -> lectura_tanque.php [{resp.status_code}]: {resp.text.strip()}")
    except requests.RequestException as e:
        print(f"  -> [ERROR] No se pudo contactar al servidor: {e}")


def manejar_despacho(uid: str, ser: "serial.Serial"):
    global bomba_activa
    try:
        resp = requests.post(
            f"{SERVER_BASE}/api/iniciar_sesion.php",
            json={"uid_rfid": uid},
            headers=HEADERS,
            timeout=5,
        )
        data = resp.json()
        print(f"  -> iniciar_sesion.php [{resp.status_code}]: {data}")

        if data.get("ok"):
            ser.write(b"INICIAR\n")
            bomba_activa = True
            print("  -> Bomba encendida. Esperando que se detenga desde el dashboard...")
        else:
            motivo = data.get("motivo", "Rechazado")
            ser.write(f"RECHAZADO;{motivo}\n".encode())

    except requests.RequestException as e:
        print(f"  -> [ERROR] No se pudo contactar al servidor: {e}")
        ser.write(b"RECHAZADO;Sin conexion al servidor\n")
    except ValueError:
        print("  -> [ERROR] El servidor no respondió JSON válido.")
        ser.write(b"RECHAZADO;Error del servidor\n")


def sondear_fin_de_sesion(ser: "serial.Serial"):
    """Mientras la bomba está activa, pregunta cada ~1s si la sesión
    ya se cerró (por el botón 'Detener' en la web, o por el límite
    de modo crisis), y si es así, manda DETENER al ESP32."""
    global bomba_activa
    try:
        resp = requests.get(f"{SERVER_BASE}/api/estado_sesion.php", headers=HEADERS, timeout=5)
        data = resp.json()
        if not data.get("activa"):
            print("  -> La sesión se cerró del lado del servidor. Enviando DETENER.")
            ser.write(b"DETENER\n")
            bomba_activa = False
    except requests.RequestException as e:
        print(f"  -> [ERROR] No se pudo consultar estado_sesion.php: {e}")


def main():
    global bomba_activa, ultimo_sondeo

    print("==================================================")
    print(" ControlFuel - Puente Serial <-> API (sesión abierta)")
    print("==================================================")
    ser = conectar_serial()

    while True:
        try:
            linea = ser.readline().decode(errors="ignore").strip()
        except serial.SerialException:
            print("[ERROR] Se perdió la conexión USB. Reconectando...")
            ser = conectar_serial()
            continue

        if linea:
            if linea.startswith("#"):
                print(linea)
            elif linea.startswith("LECTURA;"):
                try:
                    distancia = float(linea.split(";", 1)[1])
                    print(f"[LECTURA] distancia = {distancia} cm")
                    manejar_lectura(distancia)
                except (IndexError, ValueError):
                    print(f"[AVISO] Línea de lectura mal formada: {linea}")
            elif linea.startswith("DESPACHO;"):
                uid = linea.split(";", 1)[1].strip()
                print(f"[DESPACHO] UID = {uid}")
                manejar_despacho(uid, ser)
            else:
                print(f"[?] {linea}")

        # Mientras la bomba está corriendo, vigila si ya la detuvieron
        # desde el dashboard (o se llegó al límite de modo crisis).
        if bomba_activa and (time.time() - ultimo_sondeo) >= INTERVALO_SONDEO_SESION_SEG:
            sondear_fin_de_sesion(ser)
            ultimo_sondeo = time.time()


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nPuente detenido.")
