<?php

function verificarHashesAsistencia(mysqli $db): array {
    $resultado = false; // Por defecto
    $lResultado = [];   // Lista resultado

    // Conseguir registros de auditoria
    $query_audit = "
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
    ";

    $result = $db->query($query_audit);
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $total_rows = count($rows);

    // Primer hash del chain (Equivalente a hashlib.sha256(b"GENESIS").digest())
    $prev_hash = hash('sha256', 'GENESIS', true);

    ## Contrastar con tabla asistencia
    $oMysqli = null;

    $estado_actual_asistencia = [];
    if ($oMysqli) {
        ### Conseguir registros de asistencia
        $query_asistencia = "
            SELECT
                id,
                id_empleado,
                entrada,
                salida
            FROM asistencia
            ORDER BY id ASC
        ";

        $res_asistencia = $oMysqli->query($query_asistencia);
        $filas_asistencia = $res_asistencia->fetch_all(MYSQLI_ASSOC);
        
        // Cerrar conexion
        $oMysqli->close();

        foreach ($filas_asistencia as $row) {
            $estado_actual_asistencia[$row['id']] = [
                "id_empleado" => $row["id_empleado"],
                "entrada"     => $row["entrada"],
                "salida"      => $row["salida"]
            ];
        }
    }
    ## -----------------------------------------------------------------------------------------------------------------

    // Verificar cadena
    $bucle_roto = false;
    foreach ($rows as $row) {
        $accion = $row["accion"];

        // Seleccionar fuente de datos
        $datos = [];
        if ($accion === "INSERT" || $accion === "UPDATE") {
            $datos = json_decode($row["datos_nuevos"], true);
        } elseif ($accion === "DELETE") {
            $datos = json_decode($row["datos_antiguos"], true);
        } else {
            echo "[Fallo] Acción desconocida en ID {$row['auditoria_id']}\n";
            $bucle_roto = true;
            break;
        }

        // Crear carga del hash (prev_hash en hex y mayúsculas)
        $prev_hash_hex = strtoupper(bin2hex($prev_hash));
        
        $componentes = [
            $row["registro_id"],
            $row["id_empleado"],
            $datos['entrada'] ?? null,
            $datos['salida'] ?? null,
            $prev_hash_hex
        ];

        // Filtrar nulos para emular funcion Concat de sql y pasarlos a string
        $filtered_components = [];
        foreach ($componentes as $c) {
            if ($c !== null) {
                $filtered_components[] = (string)$c;
            }
        }

        // Unir componentes
        $payload = implode("|", $filtered_components);

        // Calcular hash en binario crudo (Equivalente al .digest() de Python)
        $calculated_hash = hash('sha256', $payload, true);

        // Verificar con hash anterior
        if ($row["hash_antiguo"] !== $prev_hash) {
            log_msg("Cadena rota en auditoria_id {$row['auditoria_id']}");
            $bucle_roto = true;
            break; 
        }
        
        // Verificar con hash actual
        if ($row["hash_actual"] !== $calculated_hash) {
            log_msg("Modificación detectada en auditoria_id {$row['auditoria_id']}");
            $bucle_roto = true;
            break; 
        }
        
        // Continuar cadena criptográfica interna
        $prev_hash = $row["hash_actual"];

        // Almacenar línea resultado
        $lResultado[] = "(#".$row["registro_id"].") contiene hash anterior: ".strtoupper(bin2hex($row["hash_antiguo"])).", se esperaba: ".$prev_hash_hex.", hash actual: ".strtoupper(bin2hex($row["hash_actual"])).", se esperaba: ".strtoupper(bin2hex($calculated_hash));
    }

    // Emulación del bloque 'else' de Python tras el bucle foreach
    if (!$bucle_roto) {
        // Cadena completada, cotejar datos tablas asistencia y auditoría
        ## Conseguir tabla filtrada de auditoría
        $query_ae = "
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
        ";

        $result_ae = $db->query($query_ae);
        $rowsAe = $result_ae->fetch_all(MYSQLI_ASSOC);

        foreach ($rowsAe as $row) {
            $accion = $row["accion"];

            // Seleccionar fuente de datos
            $datos = [];
            if ($accion === "INSERT" || $accion === "UPDATE") {
                $datos = json_decode($row["datos_nuevos"], true);
            } elseif ($accion === "DELETE") {
                $datos = json_decode($row["datos_antiguos"], true);
            } else {
                echo "[Fallo] Acción desconocida en ID {$row['auditoria_id']}\n";
                return [false, $lResultado];
            }

            ## Comparar con tabla asistencia
            if (array_key_exists($row['registro_id'], $estado_actual_asistencia)) {
                $r_asistencia = $estado_actual_asistencia[$row['registro_id']];

                $entrada_datos = isset($datos['entrada']) ? (string)$datos['entrada'] : 'null';
                $salida_datos = isset($datos['salida']) ? (string)$datos['salida'] : 'null';
                
                $entrada_asistencia = isset($r_asistencia['entrada']) ? (string)$r_asistencia['entrada'] : 'null';
                $salida_asistencia = isset($r_asistencia['salida']) ? (string)$r_asistencia['salida'] : 'null';

                if (
                    (string)$row['id_empleado'] === (string)$r_asistencia['id_empleado'] &&
                    $entrada_datos === $entrada_asistencia &&
                    $salida_datos === $salida_asistencia
                ) {
                    // Coincide perfectamente
                } else {
                    log_msg("Modificación detectada en auditoria_id {$row['auditoria_id']}");
                    return [false, $lResultado];
                }
            }
        }

        log_msg("Cadena completa de auditoría verificada ({$total_rows} registros).");
        $resultado = true;
    }

    return [$resultado, $lResultado];
}

function log_msg($msg) {
    // Reemplaza esto con tu sistema log real
    error_log("[LOG_AUDIT] " . $msg);
}