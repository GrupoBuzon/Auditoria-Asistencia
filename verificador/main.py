import shutil
import subprocess
import pymysql
from datetime import datetime as dt, timedelta
import ssl
import json
import hashlib

__version__ = "0.2.2"

def verificarHashesAsistencia(cursor):
    resultado = False   # Por defecto

    # Conseguir registros de auditoria
    cursor.execute("""
        SELECT
            auditoria_id,
            registro_id,
            id_empleado,
            accion,
            datos_antiguos,
            datos_nuevos,
            hash_antiguo,
            hash_actual
        FROM registro_jornada_audit
        ORDER BY auditoria_id ASC
    """)

    rows = cursor.fetchall()
    total_rows = len(rows)

    # Primer hash del blockchain
    prev_hash = hashlib.sha256(b"GENESIS").digest()

    ## Contrastar con tabla asistencia
    try:
        conexion_asistencia = pymysql.connect(
            read_default_file="carnicas_buzon.cnf",
            connect_timeout=3,
            read_timeout=5,
            write_timeout=5
        )
    except:
        conexion_asistencia = None

    estado_actual_asistencia = {}
    if conexion_asistencia:
        cursor_asistencia = conexion_asistencia.cursor(pymysql.cursors.DictCursor)

        ### Conseguir registros de asistencia
        cursor_asistencia.execute("""
                    SELECT
                        id,
                        id_empleado,
                        entrada,
                        salida
                    FROM asistencia
                    ORDER BY id ASC
                """)

        filas_asistencia = cursor_asistencia.fetchall()

        cursor_asistencia.close()
        conexion_asistencia.close()

        estado_actual_asistencia = {
            row["id"]: {"id_empleado": row["id_empleado"], "entrada": row["entrada"], "salida": row["salida"]}
            for row in filas_asistencia
        }
    ## -----------------------------------------------------------------------------------------------------------------

    # Verificar cadena
    for row in rows:
        accion = row["accion"]

        # Seleccionar fuente de datos
        if accion in ("INSERT", "UPDATE"):
            datos = json.loads(row["datos_nuevos"])

        if accion == "DELETE":
            datos = json.loads(row["datos_antiguos"])

        if accion not in ("INSERT", "UPDATE", "DELETE"):
            print(f"[Fallo] Acción desconocida en ID {row['auditoria_id']}")
            break

        # Crear carga del hash
        componentes = [
            row["registro_id"],
            row["id_empleado"],
            datos.get("entrada"),
            datos.get("salida"),
            prev_hash.hex().upper()
        ]

        # Filtrar nulos para emular funcion Concat de sql
        filtered_components = [str(c) for c in componentes if c is not None]

        # Unir componentes
        payload = "|".join(filtered_components)

        # Calcular hash
        calculated_hash = hashlib.sha256(
            payload.encode("utf-8")
        ).digest()

        # Verificar con hash anterior
        if row["hash_antiguo"] != prev_hash:
            log(f"Cadena rota en auditoria_id {row['auditoria_id']}")
            break   # Impide seguir calculando
        # Verificar con hash actual
        if row["hash_actual"] != calculated_hash:
            log(f"Modificación detectada en auditoria_id {row['auditoria_id']}")
            break   # Impide seguir calculando

        # Continuar
        prev_hash = row["hash_actual"]
    # Llega al iterar completamente el bucle
    else:
        # Cadena completada, cotejar datos tablas asistencia y auditoría
        ## Conseguir tabla filtrada de auditoría
        cursor.execute("""
            WITH asistenciaEsperada AS (
            SELECT 
                auditoria_id,
                registro_id,
                id_empleado,
                accion,
                datos_antiguos,
                datos_nuevos,
                ROW_NUMBER() OVER (
                    PARTITION BY registro_id 
                    ORDER BY realizado_fecha DESC, auditoria_id DESC
                ) AS ae
            FROM registro_jornada_audit 
            )
            SELECT 
                auditoria_id,
                registro_id,
                id_empleado,
                accion,
                datos_antiguos,
                datos_nuevos
            FROM asistenciaEsperada 
            WHERE ae = 1;
            """)

        rowsAe = cursor.fetchall()

        for row in rowsAe:
            accion = row["accion"]

            # Seleccionar fuente de datos
            if accion in ("INSERT", "UPDATE"):
                datos = json.loads(row["datos_nuevos"])

            if accion == "DELETE":
                datos = json.loads(row["datos_antiguos"])

            if accion not in ("INSERT", "UPDATE", "DELETE"):
                print(f"[Fallo] Acción desconocida en ID {row['auditoria_id']}")
                return False

            ## Comparar con tabla asistencia
            if row['registro_id'] in estado_actual_asistencia:
                r_asistencia = estado_actual_asistencia[row['registro_id']]

                if row['id_empleado'] == r_asistencia['id_empleado'] and str(datos.get('entrada')) == str(
                        r_asistencia['entrada']) and str(datos.get('salida')) == str(
                    r_asistencia['salida']):
                    pass
                else:
                    log(f"Modificación detectada en auditoria_id {row['auditoria_id']}")
                    return False
            ## ---------------------------------------------------------------------------------------------------------
        # --------------------------------------------------------------------------------------------------------------

        log(f"Cadena completa de auditoría verificada ({total_rows} registros).")
        resultado = True

    return resultado

def log(linea):
    now = dt.now()
    stamp = now.strftime("%Y-%m-%d %H:%M:%S")

    # Abre o crea y reemplaza
    try:  # Si existe el archivo
        with open("/tmp/BuzonAttendanceAudit.txt", "a") as file:
            file.write(f"{stamp} - {linea}\n")
    except:  # Si no existe
        try:
            with open("/tmp/BuzonAttendanceAudit.txt", "w") as file:
                file.write(f"{stamp} - {linea}\n")
        except:
            pass

def conexion():
    global connection

    try:
        #ssl_context = ssl.create_default_context(cafile="ca-cert.pem")

        connection = pymysql.connect(
            read_default_file="/home/kevin/BuzonAttendanceAudit/auditoria.cnf",
            connect_timeout=3,
            read_timeout=5,
            write_timeout=5,
            #ssl=ssl_context
        )
        return connection
    except Exception as e:
        print(e)
        log(f"conexion() {e}")
        connection = False
        return False

def main():
    connection = conexion()

    if connection:
        try:
            with connection:
                with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                    # Comprobar cadena hasta ahora
                    resultado = verificarHashesAsistencia(cursor)

                    # Guardar archivo en caso de comprobación correcta
                    if resultado:
                        sql = "SELECT realizado_fecha, auditoria_id, HEX(hash_actual) AS hash_final FROM `registro_jornada_audit` ORDER BY auditoria_id DESC LIMIT 1"
                        cursor.execute(sql,)
                        resultado = cursor.fetchone()

                        fecha_actual = dt.now().date()
                        carga = {'realizado_fecha': resultado['realizado_fecha'].isoformat(), 'auditoria_id': resultado['auditoria_id'], 'hash_actual': resultado['hash_final']}

                        with open(f'{fecha_actual}.json', 'w') as f:
                            json.dump(carga, f)

                        # Firma GPG
                        subprocess.run([
                            "gpg",
                            "--clearsign",
                            f"{fecha_actual}.json"
                        ], check=True)

                        ## Mover a directorio git
                        ruta_git = "/home/kevin/BuzonAttendanceAudit/Auditoria-Asistencia/"
                        shutil.move(
                            f"{fecha_actual}.json.asc",
                            ruta_git
                        )

                        subprocess.run(["git", "config", "pull.rebase", "false"], cwd=ruta_git, check=True)  # Sincronizar
                        subprocess.run(["git", "pull"], cwd=ruta_git, check=True) # Sincronizar
                        subprocess.run(["git", "add", "."], cwd=ruta_git, check=True)

                        subprocess.run([
                            "git",
                            "commit",
                            "-m",
                            f"Checkpoint diario {fecha_actual}"
                        ], cwd=ruta_git, check=True)

                        subprocess.run(["git", "push"], cwd=ruta_git, check=True)

                        log(f"Archivo {fecha_actual}.json.asc subido a git@github.com:GrupoBuzon/Auditoria-Asistencia")

        except Exception as e:
            print(e)
            log(e)

if __name__ == '__main__':
    main()
